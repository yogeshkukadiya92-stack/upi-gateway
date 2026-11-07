<?php

namespace Tests\Feature;

use App\Models\BillingCoupon;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingPage;
use App\Models\BillingPlan;
use App\Models\BillingProduct;
use App\Models\Order;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CommerceFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Queue::fake();
        Http::preventStrayRequests();
        $u = User::factory()->create(['is_active' => true]);
        $u->paymentAccounts()->create(['provider' => 'manual', 'label' => 'UPI', 'upi_id' => 'merchant@upi', 'payee_name' => 'Merchant', 'is_active' => true, 'daily_limit' => 100000]);
        $c = new BillingCustomer(['name' => 'Customer']);
        $c->user_id = $u->id;
        $c->save();
        $p = new BillingPlan(['name' => 'Basic', 'amount_paise' => 100000, 'interval_months' => 1, 'due_days' => 7]);
        $p->user_id = $u->id;
        $p->save();

        return [$u, $c, $p];
    }

    private function invoice($u, $c, $p)
    {
        return app(BillingService::class)->subscribe($u, $c, $p, ['starts_on' => today('Asia/Kolkata')->toDateString()])->invoices()->first();
    }

    private function paid($o, $utr)
    {
        app(OrderService::class)->markPaid($o, $utr, null, [], 'manual');
    }

    private function shop($u): array
    {
        $p = new BillingProduct(['name' => 'Consultation', 'amount_paise' => 100000]);
        $p->user_id = $u->id;
        $p->save();
        $page = new BillingPage(['title' => 'Consulting', 'slug' => 'test-page', 'button_label' => 'Book now']);
        $page->user_id = $u->id;
        $page->save();
        $page->products()->attach($p);

        return [$page, $p];
    }

    private function coupon($u, array $data = []): BillingCoupon
    {
        $c = new BillingCoupon($data + ['code' => 'SAVE10', 'type' => 'percent', 'value' => 1000, 'usage_limit' => 1]);
        $c->user_id = $u->id;
        $c->save();

        return $c;
    }

    public function test_partial_payments_keep_balance_and_only_verified_money_counts(): void
    {
        [$u,$c,$p] = $this->fixture();
        $i = $this->invoice($u, $c, $p);
        $b = app(BillingService::class);
        $o = $b->payment($i, 40000);
        $this->assertSame('400.00', $o->amount);
        $this->assertSame($o->id, $b->payment($i, 60000)->id);
        $this->assertSame(100000, $i->fresh()->remainingPaise());
        $this->paid($o, '111111111111');
        $this->assertSame($o->id, $b->payment($i, 40000, 0)->id);
        $this->assertSame(60000, $i->fresh()->remainingPaise());
        $this->assertNotSame('paid', $i->fresh()->state());
        $this->actingAs($u)->get(route('billing.index'))->assertOk()->assertSee('600.00')->assertSee('400.00');
        $this->get(route('billing.invoices', ['status' => 'outstanding']))->assertSee($i->number);
        $this->get(route('billing.invoices', ['status' => 'paid']))->assertDontSee($i->number);
        $this->get(route('billing.reminders'))->assertOk();
        $second = $b->payment($i, 60000);
        $this->assertNotSame($o->id, $second->id);
        $this->paid($second, '222222222222');
        $this->assertSame('paid', $i->fresh()->state());
        $this->assertSame(0, $i->fresh()->remainingPaise());
        $this->assertSame($second->id, $b->payment($i)->id);
        $this->assertSame(2, $i->orders()->count());
        $this->get($i->publicUrl())->assertOk()->assertSee('Payment received');
        $this->get(route('billing.invoice', $i))->assertOk()->assertSee('400.00')->assertSee('600.00');
        $csv = $this->get(route('billing.export'))->streamedContent();
        $this->assertStringContainsString('111111111111; 222222222222', $csv);
        Http::assertNothingSent();
    }

    public function test_excess_fractional_and_dust_partial_payments_are_rejected(): void
    {
        [$u,$c,$p] = $this->fixture();
        $i = $this->invoice($u, $c, $p);
        foreach (['1000.01', '0.99', '999.50', '10.001'] as $amount) {
            $this->post(route('billing.pay', $i->public_token), ['amount' => $amount])->assertSessionHasErrors('amount');
        }
        $this->assertSame(0, Order::count());
    }

    public function test_partial_refund_does_not_reopen_paid_amount_and_cannot_exceed_receipts(): void
    {
        [$u,$c,$p] = $this->fixture();
        $i = $this->invoice($u, $c, $p);
        $this->paid(app(BillingService::class)->payment($i, 40000), '111111111111');
        $d = ['amount' => '401', 'reference' => 'REF123456', 'refunded_on' => today()->toDateString(), 'confirmed' => 1];
        $this->actingAs($u)->post(route('billing.invoice.refund', $i), $d)->assertSessionHasErrors('amount');
        $d['amount'] = '100';
        $this->post(route('billing.invoice.refund', $i), $d)->assertSessionHasNoErrors();
        $this->assertSame(60000, $i->fresh()->remainingPaise());
        $this->assertSame(10000, (int) $i->refunds()->sum('amount_paise'));
    }

    public function test_trial_delays_first_invoice_and_plan_change_uses_new_anchor_without_proration(): void
    {
        $this->travelTo(now()->setDate(2027, 1, 31)->setTime(12, 0));
        [$u,$c,$p] = $this->fixture();
        $b = app(BillingService::class);
        $s = $b->subscribe($u, $c, $p, ['starts_on' => '2027-01-31', 'trial_days' => 7, 'discount' => '50']);
        $this->assertSame(0, $s->invoices()->count());
        $this->assertSame('2027-02-07', $s->next_billing_on->toDateString());
        $this->travelTo(now()->setDate(2027, 2, 7));
        $b->generate($s);
        $old = $s->invoices()->first();
        $this->assertSame(95000, $old->totalPaise());
        $next = $p->replicate();
        $next->name = 'Quarterly';
        $next->amount_paise = 250000;
        $next->interval_months = 3;
        $next->save();
        $this->actingAs($u)->post(route('billing.subscription.plan', $s), ['plan_id' => $next->id])->assertSessionHasNoErrors();
        $next->update(['amount_paise' => 900000]);
        $this->travelTo(now()->setDate(2027, 3, 7));
        $this->assertSame(1, $b->generate($s));
        $this->assertSame(0, $b->generate($s));
        $this->assertSame(250000, $s->invoices()->latest('id')->first()->totalPaise());
        $this->assertSame(95000, $old->fresh()->totalPaise());
        $this->assertSame('2027-06-07', $s->fresh()->next_billing_on->toDateString());
        $this->assertNull($s->fresh()->pending_plan);
        $this->assertSame(0, Order::count());
        $this->travelBack();
    }

    public function test_plan_change_tenant_archive_cancel_and_pending_removal(): void
    {
        [$u,$c,$p] = $this->fixture();
        $s = $this->invoice($u, $c, $p)->subscription;
        $other = User::factory()->create();
        $this->actingAs($other)->post(route('billing.subscription.plan', $s), ['plan_id' => $p->id])->assertNotFound();
        $this->actingAs($u)->post(route('billing.subscription.plan', $s), ['plan_id' => $p->id])->assertSessionHasNoErrors();
        $this->delete(route('billing.subscription.plan.cancel', $s))->assertSessionHasNoErrors();
        $this->assertNull($s->fresh()->pending_plan);
        $p->update(['is_active' => false]);
        $this->post(route('billing.subscription.plan', $s), ['plan_id' => $p->id])->assertSessionHasErrors('plan_id');
        $p->update(['is_active' => true]);
        app(BillingService::class)->changeState($s, 'cancel');
        $this->post(route('billing.subscription.plan', $s), ['plan_id' => $p->id])->assertSessionHasErrors('plan_id');
    }

    public function test_public_purchase_coupon_total_server_prices_and_duplicate_protection(): void
    {
        [$u] = $this->fixture();
        [$page,$product] = $this->shop($u);
        $coupon = $this->coupon($u);
        $this->get($page->publicUrl())->assertOk()->assertSee('Consultation');
        $key = session('commerce.keys.'.$page->id);
        $d = ['checkout_key' => $key, 'product_id' => $product->id, 'quantity' => 2, 'name' => 'Buyer', 'coupon' => 'save10', 'amount' => '1'];
        $this->post(route('commerce.checkout', $page->slug), $d)->assertRedirect();
        $i = BillingInvoice::firstOrFail();
        $this->assertSame(180000, $i->totalPaise());
        $this->assertSame($coupon->id, $i->coupon_id);
        $product->update(['amount_paise' => 700]);
        $this->post(route('commerce.checkout', $page->slug), $d)->assertRedirect($i->publicUrl());
        $this->assertSame(1, BillingInvoice::count());
        $this->assertSame(0, Order::count());
        $this->get($i->publicUrl())->assertSee('SAVE10')->assertSee('1,800.00');
        $this->post(route('commerce.new', $page->slug));
        $d['checkout_key'] = session('commerce.keys.'.$page->id);
        $this->post(route('commerce.checkout', $page->slug), $d)->assertSessionHasErrors('coupon');
    }

    public function test_coupon_expiry_caps_and_void_releases_reservation(): void
    {
        [$u,$c] = $this->fixture();
        $coupon = $this->coupon($u, ['maximum_discount_paise' => 5000]);
        $d = ['customer_id' => $c->id, 'description' => 'Service', 'amount' => '1000', 'discount' => '0', 'coupon' => 'SAVE10', 'due_on' => today()->toDateString()];
        $this->actingAs($u)->post(route('billing.invoices.store'), $d)->assertSessionHasNoErrors();
        $i = BillingInvoice::firstOrFail();
        $this->assertSame(95000, $i->totalPaise());
        $this->post(route('billing.invoices.store'), $d)->assertSessionHasErrors('coupon');
        $this->post(route('billing.invoice.void', $i))->assertSessionHasNoErrors();
        $this->post(route('billing.invoices.store'), $d)->assertSessionHasNoErrors();
        $coupon->update(['expires_on' => today()->subDay(), 'usage_limit' => null]);
        $this->post(route('billing.invoices.store'), $d)->assertSessionHasErrors('coupon');
    }

    public function test_foreign_products_unpublished_pages_and_session_token_are_rejected(): void
    {
        [$u] = $this->fixture();
        [$page,$product] = $this->shop($u);
        $other = User::factory()->create();
        $this->actingAs($other)->put(route('commerce.products.update', $product), ['name' => 'bad', 'amount' => 1])->assertNotFound();
        $this->post(route('commerce.pages.toggle', $page))->assertNotFound();
        $this->post(route('commerce.pages.store'), ['title' => 'Bad', 'button_label' => 'Pay', 'products' => [$product->id]])->assertSessionHasErrors('products.0');
        $this->post(route('commerce.checkout', $page->slug), ['checkout_key' => str_repeat('a', 48), 'product_id' => $product->id, 'quantity' => 1, 'name' => 'Buyer'])->assertStatus(419);
        $page->update(['is_active' => false]);
        $this->get($page->publicUrl())->assertNotFound();
        $page->update(['is_active' => true]);
        $u->update(['is_active' => false]);
        $this->get($page->publicUrl())->assertNotFound();
    }

    public function test_merchant_screens_crud_render_and_embed_is_escaped(): void
    {
        [$u] = $this->fixture();
        $this->actingAs($u);
        $this->post(route('commerce.products.store'), ['name' => '<script>alert(1)</script>', 'amount' => '100', 'description' => 'Service'])->assertSessionHasNoErrors();
        $p = BillingProduct::firstOrFail();
        $this->post(route('commerce.pages.store'), ['title' => 'Sales', 'button_label' => 'Buy <now>', 'products' => [$p->id]])->assertSessionHasNoErrors();
        $page = BillingPage::firstOrFail();
        $this->post(route('commerce.coupons.store'), ['code' => 'hello', 'type' => 'percent', 'value' => '10', 'starts_on' => today()->toDateString(), 'expires_on' => today()->addDay()->toDateString()])->assertSessionHasNoErrors();
        foreach (['commerce.products', 'commerce.pages', 'commerce.coupons'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('commerce.pages'))->assertSee('Copy button code')->assertDontSee('<script>alert(1)</script>', false);
        $this->get($page->publicUrl())->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        $this->put(route('commerce.pages.update', $page), ['title' => 'Updated', 'button_label' => 'Pay', 'products' => [$p->id]])->assertSessionHasNoErrors();
        $this->assertSame('Updated', $page->fresh()->title);
    }
}
