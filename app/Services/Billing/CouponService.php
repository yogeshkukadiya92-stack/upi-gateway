<?php

namespace App\Services\Billing;

use App\Models\BillingCoupon;
use Illuminate\Validation\ValidationException;

class CouponService
{
    // Caller holds a transaction. The row lock serialises the reservation count.
    public function reserve(int $userId, ?string $code, int $amount): array
    {
        if (! $code) {
            return [null, 0];
        }
        $c = BillingCoupon::where('user_id', $userId)->where('code', strtoupper(trim($code)))->lockForUpdate()->first();
        $today = today('Asia/Kolkata');
        if (! $c || ! $c->is_active || ($c->starts_on && $c->starts_on->gt($today)) || ($c->expires_on && $c->expires_on->lt($today)) || $amount < $c->minimum_paise || ($c->usage_limit && $c->invoices()->where('status', '!=', 'void')->count() >= $c->usage_limit)) {
            throw ValidationException::withMessages(['coupon' => 'This coupon is unavailable, expired, fully reserved, or its minimum amount is not met.']);
        }
        $discount = $c->type === 'percent' ? intdiv($amount * $c->value, 10000) : $c->value;
        if ($c->maximum_discount_paise) {
            $discount = min($discount, $c->maximum_discount_paise);
        }
        if ($amount - $discount < 100) {
            throw ValidationException::withMessages(['coupon' => 'The total after this coupon must be at least ₹1.']);
        }

        return [$c, $discount];
    }
}
