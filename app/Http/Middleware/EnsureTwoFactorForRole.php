<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * Two-factor is mandatory for manager and owner roles (REQ-0506, REQ-0370); other roles may enable it.
 * Filament evaluates "required" when routes are registered, so the per-role decision is made here per request.
 */
class EnsureTwoFactorForRole extends EnsureMultiFactorAuthenticationIsEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = Filament::auth()->user();
        if (! $user || ! $user->role->requiresTwoFactor()) {
            return $next($request);
        }
        return parent::handle($request, $next);
    }
}
