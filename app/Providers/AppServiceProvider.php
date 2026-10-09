<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Contracts\CameraGatewayInterface::class, function ($app) {
            return $app->environment('testing')
                ? new \App\Gateways\FakeCameraGateway()
                : $app->make(\App\Gateways\MqttCameraGateway::class);
        });

        $this->app->alias(\App\Contracts\CameraGatewayInterface::class, 'camera.gateway');
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
        \App\Models\Device::observe(\App\Observers\DeviceObserver::class);

        Gate::define('viewApiDocs', function ($user = null) {
            return true;
        });

        Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
            $openApi->secure(
                SecurityScheme::http('bearer')
            );
        });

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
