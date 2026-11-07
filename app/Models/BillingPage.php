<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingPage extends Model
{
    protected $table = 'billing_pages';

    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'starts_on' => 'immutable_date', 'expires_on' => 'immutable_date'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function products()
    {
        return $this->belongsToMany(BillingProduct::class, 'billing_page_products', 'page_id', 'product_id');
    }

    public function publicUrl(): string
    {
        return route('commerce.public', $this->slug);
    }
}
