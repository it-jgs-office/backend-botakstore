<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        RateLimiter::for('public-api', fn (Request $request) => Limit::perMinute(90)->by($request->ip()));
        RateLimiter::for('account-check', fn (Request $request) => Limit::perMinute(12)->by($request->ip()));
        RateLimiter::for('invoice', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('payment-create', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perMinutes(15, 10)->by('email:'.strtolower((string) $request->input('email'))),
        ]);
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perMinutes(15, 3)->by($request->ip()));
        RateLimiter::for('account', fn (Request $request) => Limit::perMinute(30)->by(
            (string) optional($request->user())->getAuthIdentifier().'|'.$request->ip(),
        ));
        RateLimiter::for('admin-api', fn (Request $request) => Limit::perMinute(40)->by(
            (string) optional($request->user())->getAuthIdentifier().'|'.$request->ip(),
        ));
    }
}
