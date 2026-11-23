<?php

namespace App\Http\Middleware;

use App\Settings\Settings;
use Closure;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Http\Request;

/**
 * Applies the signed-in user's effective settings to the request: interface language (REQ-0362) and the
 * subscriber's primary color for Filament (REQ-0375). The tenant context is already set by TenantUserProvider.
 */
class ApplyUserPreferences
{
    public function __construct(private Settings $settings)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user) {
            app()->setLocale($this->settings->get('locale.interface', $user->id) ?? config('app.locale'));
            $primary = $this->settings->get('theme.custom_primary') ?? $this->presetPrimary();
            if ($primary) {
                FilamentColor::register(['primary' => Color::hex($primary)]);
            }
        }
        // Landing page chosen by the user (REQ-0373): only when opening the interface root.
        if ($user && ($panel = filament()->getCurrentPanel()) && trim($request->path(), '/') === trim($panel->getPath(), '/')) {
            $landing = $this->settings->get('a11y.landing_page', $user->id);
            if ($landing && preg_match('/^[a-z0-9-]+$/', $landing)) {
                return redirect($panel->getUrl() . '/' . $landing);
            }
        }
        return $next($request);
    }

    private function presetPrimary(): ?string
    {
        $key = $this->settings->get('theme.preset') ?? $this->settings->get('platform.default_theme');
        if (! $key) {
            return null;
        }
        $tokens = json_decode(file_get_contents(base_path('docs/design/tokens.json')), true);
        return $tokens['themes'][$key]['light']['primary'] ?? null;
    }
}
