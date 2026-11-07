<?php

namespace App\Providers;

use App\Models\PaymentAccount;
use App\Policies\PaymentAccountPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Never render detailed exception pages in production, even if an
        // environment file was accidentally deployed with APP_DEBUG=true.
        if ($this->app->isProduction()) {
            if (str_starts_with((string) config('app.url'), 'https://')) {
                URL::forceScheme('https');
            }
            config(['app.debug' => false]);
        }

        // Named rate limiters are not registered here because the API throttle
        // is enforced inside AuthenticateApiKey (RateLimiter::tooManyAttempts /
        // RateLimiter::hit with explicit keys).  Route-level throttle:X,Y
        // middleware handles public endpoint limits via Laravel's built-in
        // ThrottleRequests middleware.

        Gate::policy(PaymentAccount::class, PaymentAccountPolicy::class);

        view()->share('csp_nonce', '');
    }
}
