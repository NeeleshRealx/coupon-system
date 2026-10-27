<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::define('revoke-coupons', static fn (User $user): bool => $user->is_admin === true);

        // Abuse protection for state-changing coupon endpoints. Keyed by user so
        // users behind one NAT don't throttle each other. This is NOT a
        // correctness mechanism — the database guarantees correctness.
        RateLimiter::for('coupon-writes', static function (Request $request): Limit {
            return Limit::perMinute((int) config('coupons.write_rate_limit_per_minute', 30))
                ->by('coupon-writes:'.($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('api', static function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->user()?->id ?? $request->ip());
        });
    }
}
