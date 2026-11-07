<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingRefund extends Model
{
    protected $guarded = ['id', 'user_id', 'invoice_id'];

    protected function casts(): array
    {
        return ['refunded_on' => 'date'];
    }
}
