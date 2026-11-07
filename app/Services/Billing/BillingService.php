<?php

namespace App\Services\Billing;

use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public static function paise(string $value): int
    {
        if (! preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', $value)) {
            throw ValidationException::withMessages(['amount' => 'Use a rupee amount with at most two decimal places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public function subscribe(User $user, BillingCustomer $customer, BillingPlan $plan, array $data): BillingSubscription
    {
        abort_unless($customer->user_id === $user->id && $plan->user_id === $user->id, 404);
        if (! $customer->is_active || ! $plan->is_active) {
            throw ValidationException::withMessages(['plan_id' => 'Choose an active customer and plan.']);
        }
        $discount = self::paise((string) ($data['discount'] ?? '0'));
        if ($plan->amount_paise - $discount < 100) {
            throw ValidationException::withMessages(['discount' => 'The amount after discount must be at least ₹1.']);
        }

        return DB::transaction(function () use ($user, $customer, $plan, $data, $discount) {
            $s = new BillingSubscription([
                'customer_id' => $customer->id, 'plan_id' => $plan->id, 'plan_name' => $plan->name,
                'amount_paise' => $plan->amount_paise, 'discount_paise' => $discount,
                'interval_months' => $plan->interval_months, 'due_days' => $plan->due_days,
                'starts_on' => $data['starts_on'], 'trial_days' => (int) ($data['trial_days'] ?? 0),
                'billing_anchor_on' => CarbonImmutable::parse($data['starts_on'])->addDays((int) ($data['trial_days'] ?? 0)),
                'next_billing_on' => CarbonImmutable::parse($data['starts_on'])->addDays((int) ($data['trial_days'] ?? 0)),
                'total_cycles' => $data['total_cycles'] ?? null,
            ]);
            $s->user()->associate($user);
            $s->save();
            $this->generate($s);

            return $s->refresh();
        });
    }

    /** Unique subscription/cycle key and row lock make scheduler retries safe. */
    public function generate(BillingSubscription $subscription): int
    {
        return DB::transaction(function () use ($subscription) {
            $s = BillingSubscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            if ($s->status !== 'active' || ! $s->user->is_active) {
                return 0;
            }
            $count = 0;
            while ($s->next_billing_on && $s->next_billing_on->lte(today('Asia/Kolkata')) && $count < 24) {
                if ($s->total_cycles && $s->next_cycle >= $s->total_cycles) {
                    break;
                }
                if ($s->pending_plan) {
                    $s->forceFill($s->pending_plan);
                    $s->billing_anchor_on = $s->next_billing_on;
                    $s->anchor_cycle = $s->next_cycle;
                    $s->pending_plan = null;
                }
                $s->invoices()->firstOrCreate(['cycle' => $s->next_cycle], $this->invoiceFields(
                    $s->user, $s->customer, $s->plan_name, $s->amount_paise, $s->discount_paise,
                    $s->next_billing_on->toDateString(), $s->next_billing_on->addDays($s->due_days)->toDateString()
                ));
                $s->next_cycle++;
                $count++;
                $s->next_billing_on = $s->cycleDate($s->next_cycle);
            }
            if ($s->total_cycles && $s->next_cycle >= $s->total_cycles) {
                $s->status = 'completed';
                $s->next_billing_on = null;
            }
            $s->save();

            return $count;
        });
    }

    public function invoiceFields(User $user, BillingCustomer $customer, string $description, int $amount, int $discount, string $issued, string $due): array
    {
        return [
            'user_id' => $user->id, 'customer_id' => $customer->id,
            'number' => 'INV-'.strtoupper(Str::random(12)), 'public_token' => Str::random(48),
            'merchant_name' => $user->displayName(), 'customer_name' => $customer->name,
            'description' => $description, 'amount_paise' => $amount, 'discount_paise' => $discount,
            'issued_on' => $issued, 'due_on' => $due,
        ];
    }

    public function changeState(BillingSubscription $subscription, string $action): void
    {
        DB::transaction(function () use ($subscription, $action) {
            $s = BillingSubscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $allowed = ['pause' => ['active'], 'resume' => ['paused'], 'cancel' => ['active', 'paused']];
            if (! in_array($s->status, $allowed[$action] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'This subscription cannot make that change.']);
            }
            if ($action === 'resume') {
                // Paused cycles are skipped; never create surprise catch-up bills.
                while ($s->cycleDate($s->next_cycle)->lt(today('Asia/Kolkata'))) {
                    $s->next_cycle++;
                }
                $s->next_billing_on = $s->cycleDate($s->next_cycle);
                $s->status = 'active';
                if ($s->total_cycles && $s->next_cycle >= $s->total_cycles) {
                    $s->status = 'completed';
                    $s->next_billing_on = null;
                }
            } elseif ($action === 'cancel') {
                $s->status = 'cancelled';
                $s->next_billing_on = null;
                $s->cancelled_at = now();
            } else {
                $s->status = 'paused';
            }
            $s->save();
        });
    }

    public function schedulePlan(BillingSubscription $subscription, BillingPlan $plan): void
    {
        DB::transaction(function () use ($subscription, $plan) {
            $s = BillingSubscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            abort_unless($plan->user_id === $s->user_id, 404);
            if (! $plan->is_active || ! in_array($s->status, ['active', 'paused'])) {
                throw ValidationException::withMessages(['plan_id' => 'Choose an active plan for an active or paused subscription.']);
            }
            if ($s->next_billing_on && $s->next_billing_on->lt(today('Asia/Kolkata')) && $s->status === 'active') {
                throw ValidationException::withMessages(['plan_id' => 'Generate outstanding renewal invoices before changing the plan.']);
            }
            $s->pending_plan = ['plan_id' => $plan->id, 'plan_name' => $plan->name, 'amount_paise' => $plan->amount_paise,
                'discount_paise' => 0, 'interval_months' => $plan->interval_months, 'due_days' => $plan->due_days];
            $s->save();
        });
    }

    /** Only manual UPI accounts: no payment provider and no debit API is called. */
    public function payment(BillingInvoice $invoice, ?int $requestedPaise = null, ?int $expectedOrderId = null): Order
    {
        return DB::transaction(function () use ($invoice, $requestedPaise, $expectedOrderId) {
            $user = User::whereKey($invoice->user_id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active, 404);
            $i = BillingInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($i->status === 'void') {
                throw ValidationException::withMessages(['payment' => 'This invoice has been voided.']);
            }
            if ($expectedOrderId !== null && (int) $i->order_id !== $expectedOrderId && $i->order_id) {
                return $i->order; // Retry of an older form: never create another instalment.
            }
            if ($i->order_id && ($i->order->status !== Order::PAID || $i->remainingPaise() === 0)) {
                $order = Order::whereKey($i->order_id)->lockForUpdate()->firstOrFail();
                if (in_array($order->status, [Order::PAID, Order::REVIEW], true)) {
                    return $order;
                }
                if ($order->status === Order::FAILED || $order->utr_attempts >= Order::MAX_UTR_ATTEMPTS) {
                    throw ValidationException::withMessages(['payment' => 'Contact the merchant to review this payment before trying again.']);
                }
                $account = PaymentAccount::whereKey($order->payment_account_id)->withUsageToday()->first();
                if (! $account || ! $account->is_active || $account->provider !== 'manual') {
                    throw ValidationException::withMessages(['payment' => 'The receiving UPI account is unavailable. Contact the merchant.']);
                }
                if ($order->isPastExpiry() || $order->status === Order::EXPIRED) {
                    if (! $account->canAccept((float) $order->amount)) {
                        throw ValidationException::withMessages(['payment' => 'The receiving account has reached its daily limit. Please try later.']);
                    }
                    // Reuse the same order: a late payment cannot settle two invoices.
                    $order->forceFill(['status' => Order::PENDING, 'expires_at' => now()->addMinutes(config('gateway.order_expiry_minutes', 15))])->save();
                }

                return $order;
            }
            $amount = $requestedPaise ?? $i->remainingPaise();
            if ($amount < 100 || $amount > $i->remainingPaise() || ($i->remainingPaise() - $amount > 0 && $i->remainingPaise() - $amount < 100)) {
                throw ValidationException::withMessages(['amount' => 'Pay at least ₹1, up to the remaining balance. Any balance left must also be at least ₹1.']);
            }
            $account = $user->paymentAccounts()->where('provider', 'manual')->where('is_active', true)
                ->withUsageToday()->orderByDesc('is_default')->orderBy('id')->get()
                ->first(fn ($a) => $a->canAccept($amount / 100));
            if (! $account) {
                throw ValidationException::withMessages(['payment' => 'The merchant needs an active manual UPI account with available daily limit. Please contact them.']);
            }
            $order = app(OrderService::class)->create($user, [
                'amount' => number_format($amount / 100, 2, '.', ''),
                'payment_account_id' => $account->id, 'client_order_id' => $i->number.'-'.($i->orders()->count() + 1),
                'customer_name' => $i->customer_name, 'note' => $i->number,
            ]);
            $i->orders()->attach($order->id);
            $i->order_id = $order->id;
            $i->save();

            return $order;
        });
    }
}
