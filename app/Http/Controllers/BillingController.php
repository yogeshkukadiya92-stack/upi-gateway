<?php

namespace App\Http\Controllers;

use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingPlan;
use App\Models\BillingRefund;
use App\Models\BillingSubscription;
use App\Models\Order;
use App\Services\Billing\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BillingController extends Controller
{
    public function __construct(private BillingService $billing) {}

    private function own(Request $r, $model): void
    {
        abort_unless($model->user_id === $r->user()->id, 404);
    }

    private function invoices(Request $r)
    {
        return BillingInvoice::where('user_id', $r->user()->id);
    }

    public function index(Request $r)
    {
        $paid = $this->invoices($r)->whereHas('order', fn ($q) => $q->where('status', Order::PAID));

        return view('billing.index', [
            'customers' => BillingCustomer::where('user_id', $r->user()->id)->where('is_active', true)->count(),
            'active' => BillingSubscription::where('user_id', $r->user()->id)->where('status', 'active')->count(),
            'outstanding' => (int) $this->invoices($r)->outstanding()->sum(DB::raw('amount_paise - discount_paise')),
            'collected' => (int) $paid->sum(DB::raw('amount_paise - discount_paise')),
            'refunds' => (int) BillingRefund::where('user_id', $r->user()->id)->sum('amount_paise'),
            'invoices' => $this->invoices($r)->with('order')->latest()->limit(8)->get(),
            'hasUpi' => $r->user()->paymentAccounts()->where('provider', 'manual')->where('is_active', true)->exists(),
        ]);
    }

    public function customers(Request $r)
    {
        $query = BillingCustomer::where('user_id', $r->user()->id);
        if ($search = $r->query('q')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.mb_substr($search, 0, 100).'%')->orWhere('email', $search)->orWhere('mobile', $search));
        }

        return view('billing.customers', ['customers' => $query->latest()->paginate(20)->withQueryString()]);
    }

    private function customerData(Request $r): array
    {
        return $r->validate(['name' => 'required|string|max:100', 'email' => 'nullable|email|max:255',
            'mobile' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'], 'notes' => 'nullable|string|max:2000']);
    }

    public function storeCustomer(Request $r)
    {
        $customer = new BillingCustomer($this->customerData($r));
        $customer->user_id = $r->user()->id;
        $customer->save();

        return back()->with('status', 'Customer added.');
    }

    public function editCustomer(Request $r, BillingCustomer $customer)
    {
        $this->own($r, $customer);

        return view('billing.customer-edit', compact('customer'));
    }

    public function updateCustomer(Request $r, BillingCustomer $customer)
    {
        $this->own($r, $customer);
        $customer->update($this->customerData($r));

        return redirect()->route('billing.customers')->with('status', 'Customer updated. Existing invoices keep their original customer name.');
    }

    public function toggleCustomer(Request $r, BillingCustomer $customer)
    {
        $this->own($r, $customer);
        if ($customer->is_active && $customer->subscriptions()->whereIn('status', ['active', 'paused'])->exists()) {
            return back()->withErrors(['customer' => 'Cancel the customer’s active or paused subscriptions before archiving.']);
        }
        $customer->is_active = ! $customer->is_active;
        $customer->save();

        return back()->with('status', 'Customer status updated. Existing invoices remain available.');
    }

    public function plans(Request $r)
    {
        return view('billing.plans', ['plans' => BillingPlan::where('user_id', $r->user()->id)->latest()->paginate(20)]);
    }

    public function storePlan(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:100', 'amount' => ['required', 'numeric', 'min:1', 'max:100000', 'regex:/^\d+(\.\d{1,2})?$/'],
            'interval_months' => ['required', 'integer', Rule::in([1, 3, 12])], 'due_days' => 'required|integer|min:0|max:30']);
        $p = new BillingPlan(['name' => $d['name'], 'amount_paise' => BillingService::paise((string) $d['amount']),
            'interval_months' => $d['interval_months'], 'due_days' => $d['due_days']]);
        $p->user_id = $r->user()->id;
        $p->save();

        return back()->with('status', 'Plan created. No customer will be debited automatically.');
    }

    public function togglePlan(Request $r, BillingPlan $plan)
    {
        $this->own($r, $plan);
        $plan->is_active = ! $plan->is_active;
        $plan->save();

        return back()->with('status', 'Plan availability updated. Existing subscription prices are unchanged.');
    }

    public function subscriptions(Request $r)
    {
        return view('billing.subscriptions', [
            'subscriptions' => BillingSubscription::where('user_id', $r->user()->id)->with('customer')->latest()->paginate(20),
            'customers' => BillingCustomer::where('user_id', $r->user()->id)->where('is_active', true)->orderBy('name')->get(),
            'plans' => BillingPlan::where('user_id', $r->user()->id)->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storeSubscription(Request $r)
    {
        $d = $r->validate(['customer_id' => 'required|integer', 'plan_id' => 'required|integer',
            'starts_on' => 'required|date_format:Y-m-d|after_or_equal:today|before:2100-01-01',
            'discount' => ['nullable', 'numeric', 'min:0', 'max:99999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'total_cycles' => 'nullable|integer|min:1|max:1200']);
        $c = BillingCustomer::where('user_id', $r->user()->id)->findOrFail($d['customer_id']);
        $p = BillingPlan::where('user_id', $r->user()->id)->findOrFail($d['plan_id']);
        $s = $this->billing->subscribe($r->user(), $c, $p, $d);

        return redirect()->route('billing.subscription', $s)->with('status', 'Subscription created. Renewal invoices are generated automatically; customers pay each invoice themselves.');
    }

    public function subscription(Request $r, BillingSubscription $subscription)
    {
        $this->own($r, $subscription);

        return view('billing.subscription', ['subscription' => $subscription->load('customer'),
            'invoices' => $subscription->invoices()->with('order')->latest()->paginate(20)]);
    }

    public function changeSubscription(Request $r, BillingSubscription $subscription)
    {
        $this->own($r, $subscription);
        $d = $r->validate(['action' => ['required', Rule::in(['pause', 'resume', 'cancel'])]]);
        $this->billing->changeState($subscription, $d['action']);

        return back()->with('status', 'Subscription updated. Already issued invoices are unchanged.');
    }

    public function generate(Request $r)
    {
        $count = 0;
        BillingSubscription::where('user_id', $r->user()->id)->where('status', 'active')->whereDate('next_billing_on', '<=', today('Asia/Kolkata'))
            ->chunkById(100, function ($rows) use (&$count) {
                foreach ($rows as $s) {
                    $count += $this->billing->generate($s);
                }
            });

        return back()->with('status', "$count renewal invoices generated. Nothing was charged or sent to customers.");
    }

    public function invoiceList(Request $r)
    {
        $query = $this->invoices($r)->with('order');
        $filter = $r->query('status');
        if ($filter === 'paid') {
            $query->whereHas('order', fn ($q) => $q->where('status', Order::PAID));
        } elseif ($filter === 'outstanding') {
            $query->outstanding();
        } elseif ($filter === 'overdue') {
            $query->outstanding()->whereDate('due_on', '<', today('Asia/Kolkata'));
        } elseif ($filter === 'void') {
            $query->where('status', 'void');
        }

        return view('billing.invoices', ['invoices' => $query->latest()->paginate(20)->withQueryString(),
            'customers' => BillingCustomer::where('user_id', $r->user()->id)->where('is_active', true)->orderBy('name')->get()]);
    }

    public function storeInvoice(Request $r)
    {
        $d = $r->validate(['customer_id' => 'required|integer', 'description' => 'required|string|max:255',
            'amount' => ['required', 'numeric', 'min:1', 'max:100000', 'regex:/^\d+(\.\d{1,2})?$/'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:99999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'due_on' => 'required|date_format:Y-m-d|after_or_equal:today|before:2100-01-01']);
        $c = BillingCustomer::where('user_id', $r->user()->id)->where('is_active', true)->findOrFail($d['customer_id']);
        $amount = BillingService::paise((string) $d['amount']);
        $discount = BillingService::paise((string) ($d['discount'] ?? '0'));
        if ($amount - $discount < 100) {
            throw ValidationException::withMessages(['discount' => 'Amount after discount must be at least ₹1.']);
        }
        $i = new BillingInvoice;
        $i->forceFill($this->billing->invoiceFields($r->user(), $c, $d['description'], $amount, $discount, today('Asia/Kolkata')->toDateString(), $d['due_on']))->save();

        return redirect()->route('billing.invoice', $i)->with('status', 'Invoice created. Share the link when ready.');
    }

    public function invoice(Request $r, BillingInvoice $invoice)
    {
        $this->own($r, $invoice);

        return view('billing.invoice', ['invoice' => $invoice->load('order', 'customer', 'refunds')]);
    }

    public function voidInvoice(Request $r, BillingInvoice $invoice)
    {
        $this->own($r, $invoice);
        DB::transaction(function () use ($invoice) {
            $i = BillingInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($i->order_id) {
                throw ValidationException::withMessages(['invoice' => 'Checkout has already started. Review its order instead; a transfer may still arrive.']);
            }
            $i->status = 'void';
            $i->save();
        });

        return back()->with('status', 'Invoice voided. Its payment link can no longer start a checkout.');
    }

    public function reminders(Request $r)
    {
        return view('billing.reminders', ['invoices' => $this->invoices($r)->outstanding()
            ->whereDate('due_on', '<=', today('Asia/Kolkata')->addDays(3))->with('customer', 'order')
            ->orderBy('due_on')->paginate(20)]);
    }

    public function markReminder(Request $r, BillingInvoice $invoice)
    {
        $this->own($r, $invoice);
        if (! in_array($invoice->state(), ['due', 'overdue'])) {
            return back()->withErrors(['invoice' => 'No reminder needed while paid, void or under review.']);
        }
        $invoice->last_reminded_at = now();
        $invoice->reminder_count++;
        $invoice->save();

        return back()->with('status', 'Your manual reminder was recorded. The app did not send a message.');
    }

    public function refund(Request $r, BillingInvoice $invoice)
    {
        $this->own($r, $invoice);
        $d = $r->validate(['amount' => ['required', 'numeric', 'min:0.01', 'max:100000', 'regex:/^\d+(\.\d{1,2})?$/'],
            'reference' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{6,80}$/'], 'reason' => 'nullable|string|max:255',
            'refunded_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($r, $invoice, $d) {
            $i = BillingInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($i->state() !== 'paid') {
                throw ValidationException::withMessages(['refund' => 'Only a verified paid invoice can have a refund record.']);
            }
            if ($d['refunded_on'] < $i->order->paid_at->toDateString()) {
                throw ValidationException::withMessages(['refunded_on' => 'Refund date cannot be earlier than the payment.']);
            }
            $amount = BillingService::paise((string) $d['amount']);
            if ($amount > $i->totalPaise() - (int) $i->refunds()->sum('amount_paise')) {
                throw ValidationException::withMessages(['amount' => 'Refund exceeds the remaining paid amount.']);
            }
            if (BillingRefund::where('user_id', $r->user()->id)->where('reference', $d['reference'])->exists()) {
                throw ValidationException::withMessages(['reference' => 'This refund reference is already recorded.']);
            }
            $refund = new BillingRefund(['amount_paise' => $amount, 'reference' => $d['reference'], 'reason' => $d['reason'] ?? null, 'refunded_on' => $d['refunded_on']]);
            $refund->user_id = $r->user()->id;
            $refund->invoice_id = $i->id;
            $refund->save();
        });

        return back()->with('status', 'Refund recorded for your accounts. This app did not transfer any money.');
    }

    public function publicInvoice(string $token)
    {
        $invoice = BillingInvoice::where('public_token', $token)->whereHas('user', fn ($q) => $q->where('is_active', true))->with('order')->firstOrFail();

        return response()->view('billing.public', compact('invoice'))->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function pay(string $token)
    {
        $i = BillingInvoice::where('public_token', $token)->firstOrFail();
        $order = $this->billing->payment($i);

        return redirect($order->paymentUrl());
    }

    public function export(Request $r)
    {
        $d = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $query = $this->invoices($r)->with('order', 'customer')->withSum('refunds', 'amount_paise')->orderBy('id');
        if (! empty($d['from'])) {
            $query->whereDate('issued_on', '>=', $d['from']);
        }
        if (! empty($d['to'])) {
            $query->whereDate('issued_on', '<=', $d['to']);
        }

        return response()->streamDownload(function () use ($query) {
            $f = fopen('php://output', 'w');
            fputcsv($f, ['Invoice', 'Customer', 'Email', 'Description', 'Issued', 'Due', 'Amount INR', 'Discount INR', 'Total INR', 'Status', 'Paid at', 'UTR', 'Refunded INR'], ',', '"', '');
            foreach ($query->lazyById(200) as $i) {
                $row = [$i->number, $i->customer_name, $i->customer->email, $i->description,
                    $i->issued_on->toDateString(), $i->due_on->toDateString(), number_format($i->amount_paise / 100, 2, '.', ''),
                    number_format($i->discount_paise / 100, 2, '.', ''), number_format($i->totalPaise() / 100, 2, '.', ''),
                    $i->state(), $i->order?->paid_at?->toIso8601String(), $i->order?->utr, number_format(($i->refunds_sum_amount_paise ?? 0) / 100, 2, '.', '')];
                $row = array_map(fn ($v) => preg_match('/^[\s]*[=+@-]|^[\t\r\n]/', (string) $v) ? "'".$v : (string) $v, $row);
                fputcsv($f, $row, ',', '"', '');
            }
            fclose($f);
        }, 'billing-invoices-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store']);
    }
}
