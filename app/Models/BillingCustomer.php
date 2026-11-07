<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingCustomer extends Model
{
    protected $attributes = ['is_active' => true];

    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function invoices()
    {
        return $this->hasMany(BillingInvoice::class, 'customer_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(BillingSubscription::class, 'customer_id');
    }
}
