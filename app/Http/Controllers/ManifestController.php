<?php

namespace App\Http\Controllers;

use App\Models\Subscriber;
use App\Settings\Settings;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Web app manifest of the single installable PWA (REQ-0271, REQ-0480). When the app is installed from a subscriber's
 * session it shows the SUBSCRIBER's name and icon, not ours (REQ-0331): the company-plan app name if set, otherwise
 * the subscriber's name. The platform name «وِثاق» and mark are used only outside any subscriber.
 */
class ManifestController
{
    public function __invoke(Settings $settings, TenantContext $ctx): JsonResponse
    {
        auth()->user();   // resolving the session user enters its subscriber context (TenantUserProvider)
        $sid = $ctx->subscriberId();
        $subscriber = $sid ? $ctx->run(null, fn () => Subscriber::find($sid)) : null;
        $name = $settings->get('theme.app_name') ?? $subscriber?->name_ar ?? config('app.name');
        $logo = $subscriber ? $settings->get('theme.logo_light') : null;
        return response()->json([
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'lang' => 'ar',
            'dir' => 'rtl',
            'start_url' => url('/'),
            'scope' => url('/'),
            'display' => 'standalone',
            'icons' => [$logo ? ['src' => $logo, 'sizes' => '512x512', 'type' => 'image/png'] : ['src' => asset('brand/withaq-favicon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml']],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}
