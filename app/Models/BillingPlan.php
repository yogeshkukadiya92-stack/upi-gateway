<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingPlan extends Model
{
    protected $attributes = ['is_active' => true];

    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'amount_paise' => 'integer', 'interval_months' => 'integer'];
    }

    public function intervalLabel(): string
    {
        return [1 => 'Monthly', 3 => 'Quarterly', 12 => 'Yearly'][$this->interval_months];
    }
}
