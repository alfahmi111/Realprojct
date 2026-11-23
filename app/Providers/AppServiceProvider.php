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
        $this->app->scoped(\App\Plans\ReadOnlyGuard::class);
        $this->app->scoped(\App\Dashboards\TenantProfiles::class);   // computed once per request for the tenants table

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

        // Q24: a read-only office (subscription ended, grace period over) cannot save anything — see ReadOnlyGuard.
        \Illuminate\Support\Facades\Event::listen(['eloquent.saving: *', 'eloquent.deleting: *'], function (string $event, array $payload) {
            foreach ($payload as $model) {
                if ($model instanceof \Illuminate\Database\Eloquent\Model) {
                    app(\App\Plans\ReadOnlyGuard::class)->check($model);
                }
            }
        });
        // …and the refusal reaches the person as a plain message, not an error page, on any screen.
        \Livewire\on('exception', function ($component, $e, $stopPropagation) {
            if ($e instanceof \App\Plans\SubscriptionReadOnlyException) {
                \Filament\Notifications\Notification::make()->title('وضع القراءة فقط')->body($e->getMessage())->danger()->persistent()->send();
                $stopPropagation();
            }
        });

        // Last sign-in, shown to the platform admin for account support. Never allowed to break a sign-in.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, function ($e) {
            try {
                $ctx = app(TenantContext::class);
                $ctx->run($e->user->subscriber_id, fn () => \Illuminate\Support\Facades\DB::table('users')->where('id', $e->user->id)->update(['last_login_at' => now()]));
            } catch (\Throwable) {
            }
        });

        // Password policy per subscriber (REQ-0370; Q10 settled 2026-11-23: at least 12 characters by default).
        \Illuminate\Validation\Rules\Password::defaults(function () {
            $min = 12;
            try {
                $min = max(8, (int) (app(\App\Settings\Settings::class)->get('security.password_policy')['min_length'] ?? 12));
            } catch (\Throwable) {
                // no tenant context (console, platform): keep the platform default
            }
            return \Illuminate\Validation\Rules\Password::min($min);
        });
    }
}
