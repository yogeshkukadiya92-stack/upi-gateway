<?php

namespace Tests\Feature;

use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Order;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FeeFreeBillingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $u = User::factory()->create(['is_active' => true]);
        $c = new BillingCustomer(['name' => 'Customer One', 'email' => 'customer@example.com', 'mobile' => '+919876543210', 'notes' => 'Private note']);
        $c->user_id = $u->id;
        $c->save();
        $p = new BillingPlan(['name' => 'Monthly coaching', 'amount_paise' => 99900, 'interval_months' => 1, 'due_days' => 7]);
        $p->user_id = $u->id;
        $p->save();

        return [$u, $c, $p];
    }

    private function subscribe($u, $c, $p, array $data = []): BillingSubscription
    {
        return app(BillingService::class)->subscribe($u, $c, $p, $data + ['starts_on' => today('Asia/Kolkata')->toDateString()]);
    }

    private function manual($u): void
    {
        $u->paymentAccounts()->create(['provider' => 'manual', 'label' => 'Bank', 'upi_id' => 'merchant@upi', 'payee_name' => 'Merchant', 'is_active' => true, 'daily_limit' => 100000]);
    }

    public function test_month_end_renewals_are_anchored_and_idempotent(): void
    {
        $this->travelTo(now()->setDate(2027, 1, 31)->setTime(12, 0));
        [$u,$c,$p] = $this->fixture();
        $s = $this->subscribe($u, $c, $p);
        $this->assertSame('2027-02-28', $s->next_billing_on->toDateString());
        $this->assertSame(0, app(BillingService::class)->generate($s));
        $this->travelTo(now()->setDate(2027, 2, 28));
        app(BillingService::class)->generate($s);
        $this->assertSame('2027-03-31', $s->fresh()->next_billing_on->toDateString());
        $this->assertSame(2, $s->invoices()->count());
        $this->assertSame($u->id, $s->invoices()->first()->user_id);
        $this->travelBack();
    }

    public function test_renewal_never_creates_payment_or_calls_provider(): void
    {
        Http::preventStrayRequests();
        [$u,$c,$p] = $this->fixture();
        $s = $this->subscribe($u, $c, $p);
        $this->artisan('billing:renew')->assertSuccessful();
        $this->assertSame(1, $s->invoices()->count());
        $this->assertSame(0, Order::count());
        Http::assertNothingSent();
    }

    public function test_paused_cycles_are_skipped_and_cancelled_cannot_resume(): void
    {
        $this->travelTo(now()->setDate(2027, 1, 15)->setTime(12, 0));
        [$u,$c,$p] = $this->fixture();
        $s = $this->subscribe($u, $c, $p);
        $billing = app(BillingService::class);
        $billing->changeState($s, 'pause');
        $this->travelTo(now()->setDate(2027, 4, 16));
        $this->assertSame(0, $billing->generate($s));
        $billing->changeState($s, 'resume');
        $this->assertSame('2027-05-15', $s->fresh()->next_billing_on->toDateString());
        $this->assertSame(1, $s->invoices()->count());
        $billing->changeState($s, 'cancel');
        $this->actingAs($u)->post(route('billing.subscription.change', $s), ['action' => 'resume'])->assertSessionHasErrors('status');
        $this->assertSame('cancelled', $s->fresh()->status);
        $this->travelBack();
    }

    public function test_cycle_limit_completes_and_price_snapshot_is_stable(): void
    {
        [$u,$c,$p] = $this->fixture();
        $s = $this->subscribe($u, $c, $p, ['discount' => '99.00', 'total_cycles' => 1]);
        $p->update(['amount_paise' => 150000]);
        $this->assertSame('completed', $s->status);
        $this->assertNull($s->next_billing_on);
        $this->assertSame(90000, $s->invoices()->first()->totalPaise());
    }

    public function test_customer_invoice_contact_details_are_not_public(): void
    {
        [$u,$c,$p] = $this->fixture();
        $i = $this->subscribe($u, $c, $p)->invoices()->first();
        $this->get($i->publicUrl())->assertOk()->assertSee('Customer One')->assertDontSee('customer@example.com')->assertDontSee('Private note')->assertSee('not AutoPay');
        $this->get('/invoice/'.str_repeat('z', 48))->assertNotFound();
    }

    public function test_cross_merchant_access_and_foreign_plan_are_rejected(): void
    {
        [$u,$c,$p] = $this->fixture();
        $s = $this->subscribe($u, $c, $p);
        $i = $s->invoices()->first();
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('billing.invoice', $i))->assertNotFound();
        $this->post(route('billing.invoice.void', $i))->assertNotFound();
        $this->post(route('billing.subscription.change', $s), ['action' => 'cancel'])->assertNotFound();
        $this->put(route('billing.customers.update', $c), ['name' => 'Attack'])->assertNotFound();
        $this->post(route('billing.plans.toggle', $p))->assertNotFound();
        $this->post(route('billing.subscriptions.store'), ['customer_id' => $c->id, 'plan_id' => $p->id, 'starts_on' => today()->toDateString()])->assertNotFound();
        $this->get(route('billing.export'))->assertOk()->assertDontSee($i->number);
    }

    public function test_repeated_checkout_reuses_one_order_and_respects_review(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        [$u,$c,$p] = $this->fixture();
        $this->manual($u);
        $i = $this->subscribe($u, $c, $p)->invoices()->first();
        $service = app(BillingService::class);
        $a = $service->payment($i);
        $b = $service->payment($i);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Order::count());
        $a->update(['expires_at' => now()->subHour(), 'status' => Order::EXPIRED]);
        $this->assertSame($a->id, $service->payment($i)->id);
        app(OrderService::class)->submitCustomerUtr($a->fresh(), '123456789012');
        $this->assertSame('review', $i->fresh()->state());
        $this->get($i->publicUrl())->assertSee('Please do not pay again')->assertDontSee('with UPI</button>', false);
        $this->assertSame(Order::REVIEW, $service->payment($i)->status);
        Http::assertNothingSent();
    }

    public function test_gateway_only_account_cannot_be_used_for_fee_free_checkout(): void
    {
        [$u,$c,$p] = $this->fixture();
        $u->paymentAccounts()->create(['provider' => 'paytm_pg', 'label' => 'PG', 'payee_name' => 'Merchant', 'is_active' => true]);
        $i = $this->subscribe($u, $c, $p)->invoices()->first();
        $this->from($i->publicUrl())->post(route('billing.pay', $i->public_token))->assertSessionHasErrors('payment');
        $this->assertSame(0, Order::count());
    }

    public function test_verified_order_is_the_only_source_of_paid_status(): void
    {
        Queue::fake();
        [$u,$c,$p] = $this->fixture();
        $this->manual($u);
        $i = $this->subscribe($u, $c, $p)->invoices()->first();
        $o = app(BillingService::class)->payment($i);
        $this->assertNotSame('paid', $i->fresh()->state());
        app(OrderService::class)->markPaid($o, '123456789012', null, [], 'manual');
        $this->assertSame('paid', $i->fresh()->state());
        $this->get($i->publicUrl())->assertSee('Payment received');
    }

    public function test_void_prevents_checkout_and_checkout_prevents_void(): void
    {
        [$u,$c,$p] = $this->fixture();
        $this->manual($u);
        $i = $this->subscribe($u, $c, $p)->invoices()->first();
        $this->actingAs($u)->post(route('billing.invoice.void', $i))->assertRedirect();
        $this->post(route('billing.pay', $i->public_token))->assertSessionHasErrors('payment');
        $i->refresh()->update(['status' => 'issued']);
        app(BillingService::class)->payment($i);
        $this->post(route('billing.invoice.void', $i))->assertSessionHasErrors('invoice');
    }

    public function test_refunds_require_verified_payment_and_cannot_exceed_paid_total(): void
    {
        Queue::fake();
        [$u,$c,$p] = $this->fixture();
        $this->manual($u);
        $i = $this->subscribe($u, $c, $p)->invoices()->first();
        $data = ['amount' => '500', 'reference' => 'REFUND123', 'refunded_on' => today()->toDateString(), 'confirmed' => '1'];
        $this->actingAs($u)->post(route('billing.invoice.refund', $i), $data)->assertSessionHasErrors('refund');
        $o = app(BillingService::class)->payment($i);
        app(OrderService::class)->markPaid($o, '123456789012', null, [], 'manual');
        $this->post(route('billing.invoice.refund', $i), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('billing.invoice.refund', $i), array_replace($data, ['reference' => 'REFUND456']))->assertSessionHasErrors('amount');
        $this->assertSame(1, $i->refunds()->count());
    }

    public function test_invalid_discounts_and_fractional_paise_are_rejected(): void
    {
        [$u,$c,$p] = $this->fixture();
        $this->actingAs($u)->post(route('billing.invoices.store'), [
            'customer_id' => $c->id, 'description' => 'Invoice', 'amount' => '10.001', 'due_on' => today()->toDateString(),
        ])->assertSessionHasErrors('amount');
        $this->post(route('billing.subscriptions.store'), ['customer_id' => $c->id, 'plan_id' => $p->id, 'starts_on' => today()->toDateString(), 'discount' => '999'])->assertSessionHasErrors('discount');
        $this->assertSame(0, BillingInvoice::count());
    }

    public function test_csv_quotes_formula_cells(): void
    {
        [$u,$c,$p] = $this->fixture();
        $c->update(['name' => '=HYPERLINK("bad")']);
        $i = $this->subscribe($u, $c, $p)->invoices()->first();
        $response = $this->actingAs($u)->get(route('billing.export'))->assertOk();
        $this->assertStringContainsString("'=HYPERLINK", $response->streamedContent());
    }

    public function test_all_billing_screens_render_and_empty_states_work(): void
    {
        [$u,$c,$p] = $this->fixture();
        $this->actingAs($u);
        foreach (['billing.index', 'billing.customers', 'billing.plans', 'billing.subscriptions', 'billing.invoices', 'billing.reminders'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $s = $this->subscribe($u, $c, $p);
        $i = $s->invoices()->first();
        $this->get(route('billing.subscription', $s))->assertOk();
        $this->get(route('billing.invoice', $i))->assertOk();
        $this->get(route('billing.customers.edit', $c))->assertOk();
        $this->get(route('billing.invoices'))->assertOk();
    }

    public function test_disabled_merchant_cannot_accept_invoice_payments(): void
    {
        [$u,$c,$p] = $this->fixture();
        $i = $this->subscribe($u,$c,$p)->invoices()->first();
        $u->update(['is_active' => false]);
        $this->get($i->publicUrl())->assertNotFound();
        $this->post(route('billing.pay',$i->public_token))->assertNotFound();
    }
}
