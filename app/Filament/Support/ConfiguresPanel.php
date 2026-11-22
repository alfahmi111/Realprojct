<?php

namespace App\Filament\Support;

use App\Http\Middleware\ApplyUserPreferences;
use App\Http\Middleware\EnsureTwoFactorForRole;
use App\Theming\ThemeService;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Shared configuration for every role interface inside the single installable web app (REQ-0271, REQ-0480):
 * Arabic RTL first (REQ-0490), theme tokens as CSS variables (REQ-0375), dark mode (REQ-0338),
 * two-factor for manager and owner (REQ-0506), Laravel protections untouched (REQ-0505).
 */
trait ConfiguresPanel
{
    protected function base(Panel $panel, string $id, string $path): Panel
    {
        return $panel
            ->id($id)
            ->path($path)
            ->login()
            ->passwordReset()
            ->profile()
            ->darkMode(true)
            ->multiFactorAuthentication(
                [AppAuthentication::make()->recoverable()],
                isRequired: true,
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureTwoFactorForRole::class)
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => Blade::render(
                '<style id="theme-tokens">{!! $css !!}</style><link rel="manifest" href="{{ url(\'/manifest.webmanifest\') }}">',
                ['css' => app(ThemeService::class)->css()],
            ))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                ApplyUserPreferences::class,
            ]);
    }
}
