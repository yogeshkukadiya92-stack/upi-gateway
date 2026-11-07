<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('email')->nullable();
            $t->string('mobile', 20)->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['user_id', 'is_active']);
        });
        Schema::create('billing_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->unsignedInteger('amount_paise');
            $t->unsignedTinyInteger('interval_months');
            $t->unsignedTinyInteger('due_days')->default(7);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('billing_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained('billing_customers');
            $t->foreignId('plan_id')->constrained('billing_plans');
            $t->string('plan_name', 100);
            $t->unsignedInteger('amount_paise');
            $t->unsignedInteger('discount_paise')->default(0);
            $t->unsignedTinyInteger('interval_months');
            $t->unsignedTinyInteger('due_days');
            $t->date('starts_on');
            $t->date('next_billing_on')->nullable();
            $t->unsignedInteger('next_cycle')->default(0);
            $t->unsignedInteger('total_cycles')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'next_billing_on']);
        });
        Schema::create('billing_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained('billing_customers');
            $t->foreignId('subscription_id')->nullable()->constrained('billing_subscriptions');
            $t->unsignedInteger('cycle')->nullable();
            $t->string('number', 30)->unique();
            $t->string('public_token', 64)->unique();
            $t->string('customer_name', 100);
            $t->string('merchant_name', 100);
            $t->string('description', 255);
            $t->unsignedInteger('amount_paise');
            $t->unsignedInteger('discount_paise')->default(0);
            $t->date('issued_on');
            $t->date('due_on');
            $t->string('status', 16)->default('issued');
            $t->foreignId('order_id')->nullable()->unique()->constrained('orders');
            $t->timestamp('last_reminded_at')->nullable();
            $t->unsignedInteger('reminder_count')->default(0);
            $t->timestamps();
            $t->unique(['subscription_id', 'cycle']);
            $t->index(['user_id', 'due_on']);
        });
        Schema::create('billing_refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('billing_invoices');
            $t->foreignId('user_id')->constrained();
            $t->unsignedInteger('amount_paise');
            $t->string('reference', 80);
            $t->string('reason', 255)->nullable();
            $t->date('refunded_on');
            $t->timestamps();
            $t->unique(['user_id', 'reference']);
        });
    }

    public function down(): void
    {
        foreach (['billing_refunds', 'billing_invoices', 'billing_subscriptions', 'billing_plans', 'billing_customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
