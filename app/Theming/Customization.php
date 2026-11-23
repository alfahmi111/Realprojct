<?php

namespace App\Theming;

use App\Models\Subscriber;
use App\Settings\Settings;

/**
 * Customization by plan (REQ-0333…REQ-0337) and where the subscriber's identity appears (REQ-0331).
 *
 *  owner   : ready theme + logo
 *  office  : + custom primary color, identity on documents and messages
 *  company : + full white-label: custom domain, app icon and name, branded login page
 */
class Customization
{
    public const FEATURES = [
        'owner' => ['theme.preset', 'theme.logo_light', 'theme.logo_dark'],
        'office' => ['theme.preset', 'theme.logo_light', 'theme.logo_dark', 'theme.custom_primary', 'identity.documents', 'identity.messages'],
        'company' => ['theme.preset', 'theme.logo_light', 'theme.logo_dark', 'theme.custom_primary', 'identity.documents', 'identity.messages', 'theme.custom_domain', 'theme.app_name', 'identity.login_page'],
    ];

    public function __construct(private Settings $settings, private ThemeService $themes)
    {
    }

    public function allows(Subscriber $subscriber, string $feature): bool
    {
        return in_array($feature, self::FEATURES[$subscriber->plan] ?? [], true);
    }

    /** Apply a customization setting, enforcing the plan and the readability gate for custom colors. */
    public function set(Subscriber $subscriber, string $key, mixed $value): void
    {
        if (! $this->allows($subscriber, $key)) {
            throw new ThemeException("[$key] is not included in the {$subscriber->plan} plan.");
        }
        if ($key === 'theme.custom_primary' && $value !== null) {
            $this->themes->assertReadable($value);
        }
        if ($key === 'theme.preset' && $value !== null && ! in_array($value, $this->settings->get('platform.available_themes') ?? [], true)) {
            throw new ThemeException("Theme [$value] is not offered by the platform.");
        }
        $this->settings->setSubscriber($key, $value);
    }

    /**
     * Logo for a screen mode (REQ-0341): the dark-mode logo if uploaded; otherwise the light logo shown on
     * a small light background.
     *
     * @return array{src: ?string, light_backdrop: bool}
     */
    public function logoFor(string $mode): array
    {
        $light = $this->settings->get('theme.logo_light');
        $dark = $this->settings->get('theme.logo_dark');
        if ($mode !== 'dark') {
            return ['src' => $light, 'light_backdrop' => false];
        }
        return $dark ? ['src' => $dark, 'light_backdrop' => false] : ['src' => $light, 'light_backdrop' => $light !== null];
    }

    /** Name shown as sender / on documents: the subscriber's, never the platform's (REQ-0331, REQ-0452). */
    public function displayName(Subscriber $subscriber, string $locale = 'ar'): string
    {
        return $locale === 'en' ? $subscriber->name_en : $subscriber->name_ar;
    }
}
