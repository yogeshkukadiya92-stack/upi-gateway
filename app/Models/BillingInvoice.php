<?php

namespace App\Models;

use App\Services\Billing\BillingService;
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

    public function orders()
    {
        return $this->belongsToMany(Order::class, 'billing_invoice_orders', 'invoice_id', 'order_id');
    }

    public function paidPaise(): int
    {
        return (int) $this->orders->where('status', Order::PAID)->sum(fn ($o) => BillingService::paise((string) $o->amount));
    }

    public function remainingPaise(): int
    {
        return max(0, $this->totalPaise() - $this->paidPaise());
    }

    // Correlated aggregate works for both MySQL production and SQLite tests.
    public static function paidSql(): string
    {
        return "(select coalesce(sum(round(o.amount * 100)),0) from billing_invoice_orders bio join orders o on o.id = bio.order_id where bio.invoice_id = billing_invoices.id and o.status = 'paid')";
    }

    public function scopePaid(Builder $q): void
    {
        $q->whereRaw(self::paidSql().' >= amount_paise - discount_paise');
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
        if ($this->remainingPaise() === 0) {
            return 'paid';
        }
        if ($this->status === 'void') {
            return 'void';
        }
        if ($this->orders->contains('status', Order::REVIEW)) {
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
        $q->where('status', 'issued')->whereRaw(self::paidSql().' < amount_paise - discount_paise');
    }
}
