<?php

namespace App\Theming;

use App\Settings\Settings;
use InvalidArgumentException;

/**
 * Themes as CSS design tokens (REQ-0327, REQ-0375, REQ-0492): changing a theme changes variables only.
 * Values come from docs/design/tokens.json (three approved themes, light + dark each, five fixed status colors).
 *
 *  - Status colors never change with the theme (REQ-0330) and have readable dark variants (REQ-0339).
 *  - A custom subscriber color is rejected if it fails the readability contrast with text (REQ-0329). The
 *    required ratio is a platform setting (theme.min_contrast_ratio); while it is unset, custom colors are
 *    refused (fail closed) — see QUESTIONS.md Q10.
 *  - The platform default theme is NOT fixed in code (decision 11); the launch check fails until it is set.
 */
class ThemeService
{
    private array $tokens;

    public function __construct(private Settings $settings)
    {
        $this->tokens = json_decode(file_get_contents(base_path('docs/design/tokens.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function presets(): array
    {
        return array_keys($this->tokens['themes']);
    }

    public function preset(string $key): array
    {
        return $this->tokens['themes'][$key] ?? throw new InvalidArgumentException("Unknown theme [$key].");
    }

    public function statusColors(): array
    {
        return ['light' => $this->tokens['status']['light'], 'dark' => $this->tokens['status']['dark'], 'labels_ar' => $this->tokens['status']['labels_ar']];
    }

    /** Theme of the current subscriber, falling back to the platform default; null if neither is set (decision 11). */
    public function activePresetKey(): ?string
    {
        return $this->settings->get('theme.preset') ?? $this->settings->get('platform.default_theme');
    }

    /** CSS custom properties for a theme, both modes; the custom primary (if any) replaces the preset primary. */
    public function css(?string $presetKey = null, ?string $customPrimary = null): string
    {
        $presetKey ??= $this->activePresetKey();
        $customPrimary ??= $this->settings->get('theme.custom_primary');
        $out = [];
        foreach (['light', 'dark'] as $mode) {
            $vars = [];
            if ($presetKey !== null) {
                $p = $this->preset($presetKey);
                foreach ($p[$mode] as $name => $hex) {
                    $vars['--color-' . $this->kebab($name)] = $hex;
                }
                $vars['--radius'] = $p['radius'];
                $vars['--font-heading'] = "'{$p['font_heading']}'";
                $vars['--font-body'] = "'{$p['font_body']}'";
            }
            if ($customPrimary !== null) {
                $vars['--color-primary'] = $mode === 'light' ? $customPrimary : $this->mix($customPrimary, '#FFFFFF', 35);
                foreach ($this->shades($customPrimary) as $step => $hex) {
                    $vars["--color-primary-$step"] = $hex;
                }
            }
            foreach ($this->tokens['status'][$mode] as $status => [$bg, $fg]) {
                $vars["--status-$status-bg"] = $bg;
                $vars["--status-$status-fg"] = $fg;
            }
            $body = implode(' ', array_map(fn ($k, $v) => "$k: $v;", array_keys($vars), $vars));
            $out[] = $mode === 'light'
                ? ":root, [data-theme-mode=\"light\"] { $body }"
                : "[data-theme-mode=\"dark\"], .dark { $body } @media (prefers-color-scheme: dark) { [data-theme-mode=\"auto\"] { $body } }";
        }
        return implode("\n", $out);
    }

    /** Filament palette for the active theme (custom colour > subscriber preset > platform default). */
    public function filamentColors(): array
    {
        auth()->user();   // signed-in users enter their subscriber context first
        $key = $this->activePresetKey();
        $primary = $this->settings->get('theme.custom_primary') ?? ($key ? $this->preset($key)['light']['primary'] : null);
        return $primary ? ['primary' => \Filament\Support\Colors\Color::hex($primary)] : [];
    }

    /** Printed documents and PDFs are always light, whatever the screen mode (REQ-0340). */
    public function documentCss(?string $presetKey = null): string
    {
        return preg_replace('/\[data-theme-mode="dark"\].*$/s', '', $this->css($presetKey));
    }

    /**
     * Validate a subscriber's primary color against text contrast (REQ-0329).
     * Returns the contrast ratio as [numerator, denominator] scaled ×1000; throws if unreadable or if the
     * required ratio has not been set by the platform.
     */
    public function assertReadable(string $hex): void
    {
        $required = $this->settings->get('theme.min_contrast_ratio');
        if ($required === null) {
            throw new ThemeException('The required contrast ratio is not configured yet (QUESTIONS.md Q10); custom colors are refused.');
        }
        $ratio = $this->contrastRatio($hex, '#FFFFFF');   // primary buttons carry white text (onPrimary)
        if ($ratio * $required['denominator'] < $required['numerator'] * 1000) {
            throw new ThemeException('This color does not reach the required readability contrast with text.');
        }
    }

    /** WCAG relative-luminance contrast ratio ×1000 as an integer (display maths only, no money involved). */
    public function contrastRatio(string $a, string $b): int
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);
        [$hi, $lo] = $la >= $lb ? [$la, $lb] : [$lb, $la];
        return (int) round(($hi + 0.05) / ($lo + 0.05) * 1000);
    }

    /** Tints/shades 50…950 generated from the base color (REQ-0329: "يولّد النظام درجاته تلقائياً"). */
    public function shades(string $hex): array
    {
        $steps = [50 => 90, 100 => 80, 200 => 60, 300 => 40, 400 => 20, 500 => 0, 600 => -15, 700 => -30, 800 => -45, 900 => -60, 950 => -75];
        $out = [];
        foreach ($steps as $step => $pct) {
            $out[$step] = $pct >= 0 ? $this->mix($hex, '#FFFFFF', $pct) : $this->mix($hex, '#000000', -$pct);
        }
        return $out;
    }

    /** Launch readiness (decision 11): fails until the platform default theme is chosen. */
    public function launchProblems(): array
    {
        return $this->settings->get('platform.default_theme') === null
            ? ['platform.default_theme is not set (decision 11 — choose clay / ink / plum)']
            : [];
    }

    private function luminance(string $hex): float
    {
        [$r, $g, $b] = $this->rgb($hex);
        $f = function (int $c): float {
            $s = $c / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    }

    private function mix(string $hex, string $with, int $percent): string
    {
        $a = $this->rgb($hex);
        $b = $this->rgb($with);
        $c = array_map(fn ($x, $y) => intdiv($x * (100 - $percent) + $y * $percent + 50, 100), $a, $b);
        return sprintf('#%02X%02X%02X', ...$c);
    }

    private function rgb(string $hex): array
    {
        if (! preg_match('/^#([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})$/', $hex, $m)) {
            throw new InvalidArgumentException("Invalid color [$hex].");
        }
        return [hexdec($m[1]), hexdec($m[2]), hexdec($m[3])];
    }

    private function kebab(string $s): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $s));
    }
}
