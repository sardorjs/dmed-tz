<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('upload', function (Request $request) {
            return [
                // Per-IP: 120 req/min — blocks flood attacks and misconfigured
                // clients hammering the endpoint before auth kicks in.
                // 120/min = 2/sec burst tolerance per IP address.
                Limit::perMinute(120)->by('ip:'.$request->ip()),

                // Per-user: 10,000 uploads/day — fair-use cap that prevents
                // a single user from consuming all of the 1M/day capacity.
                // At 1M/day with 100 active users = 10,000 each exactly;
                // lower-volume users leave headroom for power users.
                Limit::perDay(10000)->by('user:'.$request->user()?->id ?? $request->ip()),
            ];
        });
    }
}
