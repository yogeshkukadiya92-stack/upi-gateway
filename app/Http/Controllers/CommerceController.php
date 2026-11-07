<?php

namespace App\Http\Controllers;

use App\Models\BillingCoupon;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingPage;
use App\Models\BillingProduct;
use App\Services\Billing\BillingService;
use App\Services\Billing\CouponService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommerceController extends Controller
{
    private function own(Request $r, $model): void
    {
        abort_unless($model->user_id === $r->user()->id, 404);
    }

    public function products(Request $r)
    {
        return view('commerce.products', ['products' => BillingProduct::where('user_id', $r->user()->id)->latest()->paginate(20)]);
    }

    private function productData(Request $r): array
    {
        $d = $r->validate(['name' => 'required|string|max:100', 'description' => 'nullable|string|max:2000', 'amount' => ['required', 'numeric', 'min:1', 'max:100000', 'regex:/^\d+(\.\d{1,2})?$/']]);

        return ['name' => $d['name'], 'description' => $d['description'] ?? null, 'amount_paise' => BillingService::paise((string) $d['amount'])];
    }

    public function storeProduct(Request $r)
    {
        $p = new BillingProduct($this->productData($r));
        $p->user_id = $r->user()->id;
        $p->save();

        return back()->with('status', 'Product / service added.');
    }

    public function updateProduct(Request $r, BillingProduct $product)
    {
        $this->own($r, $product);
        $product->update($this->productData($r));

        return back()->with('status', 'Product updated. Existing invoices are unchanged.');
    }

    public function toggleProduct(Request $r, BillingProduct $product)
    {
        $this->own($r, $product);
        $product->update(['is_active' => ! $product->is_active]);

        return back()->with('status', 'Product availability updated.');
    }

    public function pages(Request $r)
    {
        return view('commerce.pages', [
            'pages' => BillingPage::where('user_id', $r->user()->id)->with('products')->latest()->paginate(20),
            'products' => BillingProduct::where('user_id', $r->user()->id)->where('is_active', true)->orderBy('name')->get()]);
    }

    private function pageData(Request $r): array
    {
        return $r->validate(['title' => 'required|string|max:100', 'description' => 'nullable|string|max:2000', 'button_label' => 'required|string|max:40',
            'products' => 'required|array|min:1|max:50', 'products.*' => ['required', 'integer', 'distinct', Rule::exists('billing_products', 'id')->where('user_id', $r->user()->id)->where('is_active', true)]]);
    }

    public function storePage(Request $r)
    {
        $d = $this->pageData($r);
        DB::transaction(function () use ($r, $d) {
            $p = new BillingPage(collect($d)->except('products')->all());
            $p->user_id = $r->user()->id;
            $p->slug = Str::random(24);
            $p->save();
            $p->products()->sync($d['products']);
        });

        return back()->with('status', 'Payment page created. Copy its link or website button code.');
    }

    public function updatePage(Request $r, BillingPage $page)
    {
        $this->own($r, $page);
        $d = $this->pageData($r);
        DB::transaction(function () use ($page, $d) {
            $page->update(collect($d)->except('products')->all());
            $page->products()->sync($d['products']);
        });

        return back()->with('status', 'Page updated. Existing invoices are unchanged.');
    }

    public function togglePage(Request $r, BillingPage $page)
    {
        $this->own($r, $page);
        $page->update(['is_active' => ! $page->is_active]);

        return back()->with('status', 'Page availability updated.');
    }

    public function coupons(Request $r)
    {
        return view('commerce.coupons', ['coupons' => BillingCoupon::where('user_id', $r->user()->id)->withCount(['invoices' => fn ($q) => $q->where('status', '!=', 'void')])->latest()->paginate(20)]);
    }

    public function storeCoupon(Request $r)
    {
        $r->merge(['code' => strtoupper(trim((string) $r->input('code')))]);
        $d = $r->validate(['code' => ['required', 'regex:/^[A-Z0-9_-]{3,40}$/', Rule::unique('billing_coupons')->where('user_id', $r->user()->id)],
            'type' => ['required', Rule::in(['fixed', 'percent'])], 'value' => ['required', 'numeric', 'min:0.01', 'max:100000', 'regex:/^\d+(\.\d{1,2})?$/'],
            'minimum' => 'nullable|numeric|min:0|max:100000', 'maximum_discount' => 'nullable|numeric|min:0.01|max:100000',
            'usage_limit' => 'nullable|integer|min:1|max:1000000', 'starts_on' => 'nullable|date_format:Y-m-d',
            'expires_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on']);
        if ($d['type'] === 'percent' && (float) $d['value'] > 100) {
            return back()->withInput()->withErrors(['value' => 'Percentage cannot exceed 100.']);
        }
        $c = new BillingCoupon(['code' => $d['code'], 'type' => $d['type'], 'value' => BillingService::paise((string) $d['value']),
            'minimum_paise' => BillingService::paise((string) ($d['minimum'] ?? '0')),
            'maximum_discount_paise' => isset($d['maximum_discount']) ? BillingService::paise((string) $d['maximum_discount']) : null,
            'usage_limit' => $d['usage_limit'] ?? null, 'starts_on' => $d['starts_on'] ?? null, 'expires_on' => $d['expires_on'] ?? null]);
        $c->user_id = $r->user()->id;
        $c->save();

        return back()->with('status', 'Coupon created. Each non-void invoice reserves one use, including unpaid invoices.');
    }

    public function toggleCoupon(Request $r, BillingCoupon $coupon)
    {
        $this->own($r, $coupon);
        $coupon->update(['is_active' => ! $coupon->is_active]);

        return back()->with('status', 'Coupon availability updated.');
    }

    private function publicPage(string $slug): BillingPage
    {
        return BillingPage::where('slug', $slug)->where('is_active', true)->whereHas('user', fn ($q) => $q->where('is_active', true))->firstOrFail();
    }

    public function show(Request $r, string $slug)
    {
        $page = $this->publicPage($slug);
        $key = $r->session()->get('commerce.keys.'.$page->id);
        if (! $key) {
            $key = Str::random(48);
            $r->session()->put('commerce.keys.'.$page->id, $key);
        }

        return response()->view('commerce.public', ['page' => $page, 'products' => $page->products()->where('is_active', true)->get(), 'checkoutKey' => $key])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function checkout(Request $r, string $slug, BillingService $billing, CouponService $coupons)
    {
        $page = $this->publicPage($slug);
        $d = $r->validate(['checkout_key' => 'required|string|size:48', 'product_id' => 'required|integer', 'quantity' => 'required|integer|min:1|max:100',
            'name' => 'required|string|max:100', 'email' => 'nullable|email|max:255', 'coupon' => 'nullable|string|max:40']);
        abort_unless(hash_equals((string) $r->session()->get('commerce.keys.'.$page->id), $d['checkout_key']), 419);
        $invoice = DB::transaction(function () use ($page, $d, $billing, $coupons) {
            $p = BillingPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            abort_unless($p->is_active && $p->user->is_active, 404);
            $key = hash('sha256', $p->id.':'.$d['checkout_key']);
            if ($existing = BillingInvoice::where('checkout_key', $key)->first()) {
                return $existing;
            }
            $product = $p->products()->where('is_active', true)->where('billing_products.user_id', $p->user_id)->findOrFail($d['product_id']);
            $amount = $product->amount_paise * $d['quantity'];
            if ($amount > 10000000) {
                throw ValidationException::withMessages(['quantity' => 'Order total cannot exceed ₹100,000.']);
            }
            [$coupon,$discount] = $coupons->reserve($p->user_id, $d['coupon'] ?? null, $amount);
            $customer = new BillingCustomer(['name' => $d['name'], 'email' => $d['email'] ?? null]);
            $customer->user_id = $p->user_id;
            $customer->save();
            $i = new BillingInvoice;
            $i->forceFill($billing->invoiceFields($p->user, $customer, $product->name.' × '.$d['quantity'], $amount, $discount, today('Asia/Kolkata')->toDateString(), today('Asia/Kolkata')->toDateString()) + [
                'coupon_id' => $coupon?->id, 'coupon_code' => $coupon?->code, 'product_id' => $product->id, 'page_id' => $p->id, 'quantity' => $d['quantity'], 'checkout_key' => $key]);
            $i->save();

            return $i;
        });

        // Keep the token for retry/back-button safety. A fresh purchase is an explicit action.
        return redirect($invoice->publicUrl());
    }

    public function newPurchase(Request $r,string $slug)
    {
        $page = $this->publicPage($slug);
        $r->session()->put('commerce.keys.'.$page->id,Str::random(48));

        return redirect($page->publicUrl());
    }
}
