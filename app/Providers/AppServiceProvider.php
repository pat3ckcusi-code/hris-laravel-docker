<?php

namespace App\Providers;

use App\Auth\ExpiringRememberTokenUserProvider;
use App\Events\HolidayCreated;
use App\Listeners\CancelLeavesOnHoliday;
use App\Models\Setting;
use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('eloquent-expiring-remember', function ($app, array $config) {
            return new ExpiringRememberTokenUserProvider($app['hash'], $config['model']);
        });

        // Defense-in-depth: align the browser-side "remember me" cookie's max-age
        // with the server-side token lifetime above, instead of leaving it at
        // Laravel's 400-day default (SessionGuard::$rememberDuration) while the
        // server-side token itself expires in a fraction of that. Must resolve
        // the guard eagerly here (not via a Login event listener) because
        // SessionGuard::login() queues the recaller cookie before firing that
        // event - too late to affect the very first login of a request.
        $guard = Auth::guard('web');
        if ($guard instanceof SessionGuard) {
            $guard->setRememberDuration((int) config('auth.remember_token_expiration_days') * 60 * 24);
        }

        User::observe(UserObserver::class);

        Event::listen(HolidayCreated::class, CancelLeavesOnHoliday::class);

        // Share app settings to views that need them without inline DB queries.
        View::composer(['auth.login', 'emails.hris_transaction'], function ($view) {
            $view->with('settings', cache()->remember('app_settings', 300, fn () => Setting::first()));
        });

        // Rate limiting: login attempts
        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());

            return Limit::perMinute(5)->by($key)->response(function () {
                return response('Too many login attempts. Please try again later.', 429);
            });
        });

        // Rate limiting: document request endpoints
        RateLimiter::for('documents', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // Rate limiting: general API endpoints
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
