<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingProduct extends Model
{
    protected $table = 'billing_products';

    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'starts_on' => 'immutable_date', 'expires_on' => 'immutable_date'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function pages()
    {
        return $this->belongsToMany(BillingPage::class, 'billing_page_products', 'product_id', 'page_id');
    }
}
