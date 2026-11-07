<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BillingInvoice extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['public_token'];

    protected function casts(): array
    {
        return ['issued_on' => 'immutable_date', 'due_on' => 'immutable_date', 'last_reminded_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(BillingCustomer::class);
    }

    public function subscription()
    {
        return $this->belongsTo(BillingSubscription::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function refunds()
    {
        return $this->hasMany(BillingRefund::class, 'invoice_id');
    }

    public function totalPaise(): int
    {
        return $this->amount_paise - $this->discount_paise;
    }

    public function state(): string
    {
        if ($this->order?->status === Order::PAID) {
            return 'paid';
        }
        if ($this->status === 'void') {
            return 'void';
        }
        if ($this->order?->status === Order::REVIEW) {
            return 'review';
        }

        return $this->due_on->lt(today('Asia/Kolkata')) ? 'overdue' : 'due';
    }

    public function publicUrl(): string
    {
        return route('billing.public', $this->public_token);
    }

    public function scopeOutstanding(Builder $q): void
    {
        $q->where('status', 'issued')->whereDoesntHave('order', fn ($q) => $q->where('status', Order::PAID));
    }
}
