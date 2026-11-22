<?php

namespace App\Http\Controllers;

use App\Settings\Settings;
use Illuminate\Http\JsonResponse;

/**
 * Web app manifest for the single installable PWA (REQ-0271, REQ-0480). When a subscriber has set its app
 * name (company plan white-label, REQ-0331, REQ-0337) the installed app shows the subscriber's name, not ours.
 */
class ManifestController
{
    public function __invoke(Settings $settings): JsonResponse
    {
        $name = $settings->get('theme.app_name') ?? config('app.name');
        return response()->json([
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'lang' => 'ar',
            'dir' => 'rtl',
            'start_url' => url('/'),
            'scope' => url('/'),
            'display' => 'standalone',
            'icons' => array_values(array_filter([
                ($logo = $settings->get('theme.logo_light')) ? ['src' => $logo, 'sizes' => '512x512', 'type' => 'image/png'] : null,
            ])),
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}
