<?php

namespace App\Providers;

use App\Events\HolidayCreated;
use App\Listeners\CancelLeavesOnHoliday;
use App\Models\Setting;
use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
