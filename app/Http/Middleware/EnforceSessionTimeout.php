<?php

namespace App\Http\Middleware;

use App\Settings\Settings;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * Idle timeout per subscriber (REQ-0370; Q10 settled 2026-11-23: 120 minutes by default, editable by the subscriber).
 * After that long without a request the session ends and the person signs in again (2FA included).
 */
class EnforceSessionTimeout
{
    public const KEY = 'withaq.last_activity';

    public function __construct(private Settings $settings)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && $request->hasSession()) {
            $minutes = (int) ($this->settings->get('security.session_minutes') ?? 0);
            $last = (int) $request->session()->get(self::KEY, 0);
            if ($minutes > 0 && $last > 0 && (time() - $last) > $minutes * 60) {
                Filament::auth()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $login = Filament::getCurrentPanel()?->getLoginUrl() ?? url('/');
                return $request->expectsJson() || $request->header('X-Livewire')
                    ? response()->json(['message' => 'انتهت الجلسة لعدم النشاط'], 419)
                    : redirect($login);
            }
            $request->session()->put(self::KEY, time());
        }
        return $next($request);
    }
}
