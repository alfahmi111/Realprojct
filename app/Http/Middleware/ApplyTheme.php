<?php

namespace App\Http\Middleware;

use App\Settings\Settings;
use App\Theming\ThemeService;
use Closure;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Http\Request;

/**
 * Applies the active theme to Filament on every request, signed in or not (login pages included): the subscriber's
 * custom colour, else its preset, else the platform default theme «وِثاق» (REQ-0375, decision 11 settled 2026-11-22).
 */
class ApplyTheme
{
    public function __construct(private Settings $settings, private ThemeService $themes)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $request->user();   // enters the subscriber context when signed in
        $key = $this->themes->activePresetKey();
        $primary = $this->settings->get('theme.custom_primary') ?? ($key ? $this->themes->preset($key)['light']['primary'] : null);
        if ($primary) {
            FilamentColor::register(['primary' => Color::hex($primary)]);
        }
        return $next($request);
    }
}
