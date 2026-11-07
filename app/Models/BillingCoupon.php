<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingCoupon extends Model
{
    protected $table = 'billing_coupons';

    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'starts_on' => 'immutable_date', 'expires_on' => 'immutable_date'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function invoices()
    {
        return $this->hasMany(BillingInvoice::class, 'coupon_id');
    }
}
