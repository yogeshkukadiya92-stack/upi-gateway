<?php

use App\Models\BillingSubscription;
use App\Models\Order;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\OrderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/**
 * Catches payments whose customer closed the checkout page before it
 * confirmed, and expires unpaid orders (after one last status check).
 */
Artisan::command('orders:check-pending', function (OrderService $orders) {
    $checked = 0;

    Order::with('paymentAccount')
        ->where('status', Order::PENDING)
        ->orderBy('id')
        ->chunkById(100, function ($chunk) use ($orders, &$checked) {
            foreach ($chunk as $order) {
                $orders->check($order);
                $checked++;
            }
        });

    $this->info("Checked {$checked} pending orders.");
})->purpose('Verify pending orders with providers and expire stale ones');

Artisan::command('app:create-admin {email} {--name=Admin}', function (string $email) {
    $password = $this->secret('Password (min 10 chars)');

    if (strlen((string) $password) < 10) {
        return $this->error('Password must be at least 10 characters.');
    }

    $user = User::updateOrCreate(['email' => $email], [
        'name' => $this->option('name'),
        'password' => $password,
        'role' => 'admin',
        'is_active' => true,
    ]);

    if (! $user->api_key) {
        $user->regenerateApiCredentials();
    }

    $this->info("Admin ready: {$email}");
})->purpose('Create or promote an admin user');

Schedule::command('orders:check-pending')->everyMinute()->withoutOverlapping();

Artisan::command('billing:renew', function (BillingService $billing) {
    $count = 0;
    BillingSubscription::where('status', 'active')
        ->whereDate('next_billing_on', '<=', today('Asia/Kolkata'))
        ->chunkById(100, function ($rows) use ($billing, &$count) {
            foreach ($rows as $subscription) {
                $count += $billing->generate($subscription);
            }
        });
    $this->info("Generated {$count} renewal invoices. No payments charged or messages sent.");
})->purpose('Generate fee-free renewal invoices without charging customers');
Schedule::command('billing:renew')->hourly()->withoutOverlapping();
