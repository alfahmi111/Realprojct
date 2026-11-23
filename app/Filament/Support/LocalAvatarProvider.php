<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * Initial-letter avatar generated locally as an SVG data URI. Replaces Filament's default provider, which sends the
 * user's name to an external service (ui-avatars.com) — personal data must not leave the platform (REQ-0444, REQ-0453).
 */
class LocalAvatarProvider implements AvatarProvider
{
    public function get(Model | \Illuminate\Contracts\Auth\Authenticatable $record): string
    {
        $name = trim((string) filament()->getNameForDefaultAvatar($record));
        $initial = htmlspecialchars(mb_substr($name !== '' ? $name : '؟', 0, 1), ENT_QUOTES);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="32" fill="#E8F5F3"/>'
            . '<text x="32" y="42" text-anchor="middle" font-family="Cairo, Tajawal, sans-serif" font-size="28" font-weight="700" fill="#0D4F5C">' . $initial . '</text></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
