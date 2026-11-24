<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Mandatory two-factor (REQ-0506, REQ-0370) on the custom routes outside the Filament panels — the full data export,
 * vault documents, circular attachments, the technician API (review M18: only `auth` guarded them, so a manager or
 * owner who had not enrolled yet could download everything after the password step alone).
 */
class RequireTwoFactorOnRoute
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if ($user && $user->role->requiresTwoFactor() && blank($user->getAppAuthenticationSecret())) {
            abort(403, 'فعّل التحقق بخطوتين من حسابك أولاً.');
        }
        return $next($request);
    }
}
