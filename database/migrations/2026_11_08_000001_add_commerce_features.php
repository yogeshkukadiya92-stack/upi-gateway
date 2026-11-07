<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoice_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('billing_invoices');
            $t->foreignId('order_id')->unique()->constrained('orders');
            $t->unique(['invoice_id', 'order_id']);
        });
        DB::table('billing_invoices')->whereNotNull('order_id')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('billing_invoice_orders')->insert(['invoice_id' => $row->id, 'order_id' => $row->order_id]);
            }
        });
        Schema::table('billing_subscriptions', function (Blueprint $t) {
            $t->unsignedSmallInteger('trial_days')->default(0);
            $t->date('billing_anchor_on')->nullable();
            $t->unsignedInteger('anchor_cycle')->default(0);
            $t->json('pending_plan')->nullable();
        });
        Schema::create('billing_products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('name', 100);
            $t->text('description')->nullable();
            $t->unsignedBigInteger('amount_paise');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('billing_pages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('slug', 48)->unique();
            $t->string('title', 100);
            $t->text('description')->nullable();
            $t->string('button_label', 40)->default('Pay with UPI');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('billing_page_products', function (Blueprint $t) {
            $t->foreignId('page_id')->constrained('billing_pages');
            $t->foreignId('product_id')->constrained('billing_products');
            $t->primary(['page_id', 'product_id']);
        });
        Schema::create('billing_coupons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('code', 40);
            $t->string('type', 10);
            $t->unsignedBigInteger('value');
            $t->unsignedBigInteger('minimum_paise')->default(0);
            $t->unsignedBigInteger('maximum_discount_paise')->nullable();
            $t->unsignedInteger('usage_limit')->nullable();
            $t->date('starts_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['user_id', 'code']);
        });
        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->foreignId('coupon_id')->nullable()->constrained('billing_coupons');
            $t->string('coupon_code', 40)->nullable();
            $t->foreignId('page_id')->nullable()->constrained('billing_pages');
            $t->foreignId('product_id')->nullable()->constrained('billing_products');
            $t->unsignedInteger('quantity')->default(1);
            $t->string('checkout_key', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('coupon_id');
            $t->dropConstrainedForeignId('page_id');
            $t->dropConstrainedForeignId('product_id');
            $t->dropColumn(['coupon_code', 'quantity', 'checkout_key']);
        });
        Schema::dropIfExists('billing_coupons');
        Schema::dropIfExists('billing_page_products');
        Schema::dropIfExists('billing_pages');
        Schema::dropIfExists('billing_products');
        Schema::table('billing_subscriptions', fn (Blueprint $t) => $t->dropColumn(['trial_days', 'billing_anchor_on', 'anchor_cycle', 'pending_plan']));
        Schema::dropIfExists('billing_invoice_orders');
    }
};
