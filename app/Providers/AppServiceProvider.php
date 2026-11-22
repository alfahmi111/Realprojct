<?php

namespace App\Providers;

use App\Integrations\Accounting\AccountingGateway;
use App\Integrations\DocumentReader\DocumentReaderGateway;
use App\Integrations\EInvoicing\EInvoicingGateway;
use App\Integrations\Ejar\EjarGateway;
use App\Integrations\Ejar\ManualEjarGateway;
use App\Integrations\IntegrationNotConfigured;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Simulators;
use App\Integrations\Sms\SmsGateway;
use App\Integrations\UnconfiguredIntegration;
use App\Integrations\WhatsApp\WhatsAppGateway;
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

        $this->registerIntegrations();

        // Users are resolved through RLS-aware lookups (REQ-0169, REQ-0487). Registered as soon as auth exists.
        Auth::resolved(fn ($auth) => $auth->provider('tenant-eloquent', fn ($app, array $config) => new TenantUserProvider($app['hash'], $config['model'])));
    }

    /** One intermediary layer per external integration (REQ-0489, REQ-0463). */
    private function registerIntegrations(): void
    {
        $this->app->singleton(EjarGateway::class, fn () => match (config('integrations.ejar')) {
            'manual' => new ManualEjarGateway(),
            default => throw new IntegrationNotConfigured('Automatic Ejar integration is blocked by decision 12.'),
        });
        $this->app->singleton(Simulators::class);
        foreach ([
            'einvoicing' => EInvoicingGateway::class, 'payments' => PaymentGateway::class, 'whatsapp' => WhatsAppGateway::class,
            'sms' => SmsGateway::class, 'accounting' => AccountingGateway::class, 'document_reader' => DocumentReaderGateway::class,
        ] as $key => $contract) {
            $this->app->singleton($contract, fn ($app) => match (config("integrations.$key")) {
                'simulator' => $app->make(Simulators::class),
                default => new UnconfiguredIntegration($key),
            });
        }
    }

    public function boot(): void
    {
        // Each queued job starts with an empty tenant context; tenant jobs set their own (TenantAware jobs).
        Queue::before(fn () => app(TenantContext::class)->clear());
        Queue::after(fn () => app(TenantContext::class)->clear());
    }
}
