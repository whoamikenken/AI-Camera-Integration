<?php

namespace App\Providers;

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
        \Illuminate\Support\Facades\RateLimiter::for('api', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        \App\Models\Employee::observe(\App\Observers\EmployeeObserver::class);
        \App\Models\Personnel::observe(\App\Observers\PersonnelObserver::class);
        \App\Models\SyncTask::observe(\App\Observers\SyncTaskObserver::class);

        $host = request()->getHost();
        $forwardedHost = request()->header('X-Forwarded-Host', '');
        if (
            app()->environment('production', 'staging') ||
            str_contains($host, 'camera-dev') ||
            str_contains($host, '8gategames.com') ||
            str_contains($forwardedHost, 'camera-dev')
        ) {
            \Illuminate\Support\Facades\Vite::useHotFile(storage_path('vite.hot.nonexistent'));
        }
    }
}
