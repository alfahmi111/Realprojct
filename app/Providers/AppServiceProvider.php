<?php

namespace App\Providers;

use App\Settings\Settings;
use App\Support\Dates\DateService;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantUserProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(Settings::class);
        $this->app->singleton(DateService::class);

        // Users are resolved through RLS-aware lookups (REQ-0169, REQ-0487). Registered as soon as auth exists.
        Auth::resolved(fn ($auth) => $auth->provider('tenant-eloquent', fn ($app, array $config) => new TenantUserProvider($app['hash'], $config['model'])));
    }

    public function boot(): void
    {
        // Each queued job starts with an empty tenant context; tenant jobs set their own (TenantAware jobs).
        Queue::before(fn () => app(TenantContext::class)->clear());
        Queue::after(fn () => app(TenantContext::class)->clear());
    }
}
