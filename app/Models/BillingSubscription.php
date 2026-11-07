<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingSubscription extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'next_billing_on' => 'immutable_date', 'cancelled_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(BillingCustomer::class);
    }

    public function plan()
    {
        return $this->belongsTo(BillingPlan::class);
    }

    public function invoices()
    {
        return $this->hasMany(BillingInvoice::class, 'subscription_id');
    }

    public function cycleDate(int $cycle)
    {
        return $this->starts_on->addMonthsNoOverflow($cycle * $this->interval_months);
    }
}
