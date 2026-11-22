<?php

namespace Tests\Feature\Foundation;

use App\Settings\Settings;
use App\Theming\ThemeException;
use App\Theming\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * REQ-0327, REQ-0375, REQ-0492 — themes are CSS design tokens, light + dark, read by Tailwind and Filament.
 * REQ-0328 — three ready themes from tokens.json. REQ-0330, REQ-0339 — five status colors fixed in every theme.
 * REQ-0329 — custom color: generated shades, unreadable colors rejected. REQ-0340 — documents always light.
 * Covered IDs: REQ-0327, REQ-0328, REQ-0329, REQ-0330, REQ-0338, REQ-0339, REQ-0340, REQ-0375, REQ-0492, REQ-0654.
 * REQ-0654 — no platform default theme fixed in code; launch check fails until set.
 */
class ThemeTest extends TestCase
{
    use RefreshDatabase;

    private function themes(): ThemeService
    {
        return app(ThemeService::class);
    }

    public function test_req_0328_three_ready_themes_with_light_and_dark_versions(): void
    {
        $this->assertSame(['clay', 'ink', 'plum'], $this->themes()->presets());
        foreach ($this->themes()->presets() as $key) {
            $p = $this->themes()->preset($key);
            $this->assertArrayHasKey('light', $p);
            $this->assertArrayHasKey('dark', $p);
            $this->assertSame(array_keys($p['light']), array_keys($p['dark']));
        }
        $this->assertSame('طين نجدي', $this->themes()->preset('clay')['name_ar']);
    }

    public function test_req_0327_changing_the_theme_changes_variables_only(): void
    {
        $clay = $this->themes()->css('clay');
        $plum = $this->themes()->css('plum');
        $this->assertStringContainsString('--color-primary: #9A3F22;', $clay);
        $this->assertStringContainsString('--color-primary: #5A2D82;', $plum);
        $this->assertStringContainsString('--color-on-primary: #FFFFFF;', $clay);
        // Same variable names in both → screens never need rebuilding.
        preg_match_all('/(--[a-z0-9-]+):/', $clay, $a);
        preg_match_all('/(--[a-z0-9-]+):/', $plum, $b);
        $this->assertSame($a[1], $b[1]);
    }

    public function test_req_0330_status_colors_are_identical_in_every_theme(): void
    {
        $extract = function (string $css) {
            preg_match_all('/(--status-[a-z]+-(?:bg|fg)): (#[0-9A-F]{6});/', $css, $m, PREG_SET_ORDER);
            return array_map(fn ($x) => $x[1] . '=' . $x[2], $m);
        };
        $clay = $extract($this->themes()->css('clay'));
        $this->assertCount(30, $clay);   // 5 statuses × bg/fg × (light, dark, auto-dark)
        $this->assertSame($clay, $extract($this->themes()->css('ink')));
        $this->assertSame($clay, $extract($this->themes()->css('plum', '#123456')));
        $this->assertSame(['paid', 'late', 'vacant', 'maint', 'soon'], array_keys($this->themes()->statusColors()['light']));
    }

    public function test_req_0339_every_theme_and_status_has_a_dark_version(): void
    {
        $s = $this->themes()->statusColors();
        $this->assertSame(array_keys($s['light']), array_keys($s['dark']));
        foreach ($s['dark'] as [$bg, $fg]) {
            $this->assertGreaterThanOrEqual(4500, $this->themes()->contrastRatio($bg, $fg));   // readable dark variants
        }
        $css = $this->themes()->css('ink');
        $this->assertStringContainsString('[data-theme-mode="dark"]', $css);
        $this->assertStringContainsString('prefers-color-scheme: dark', $css);
    }

    public function test_req_0340_documents_are_always_light(): void
    {
        $doc = $this->themes()->documentCss('clay');
        $this->assertStringContainsString('#FBF7F2', $doc);       // light bg
        $this->assertStringNotContainsString('#17110D', $doc);    // dark bg absent
        $this->assertStringNotContainsString('dark', $doc);
    }

    public function test_req_0329_custom_color_generates_shades(): void
    {
        $sh = $this->themes()->shades('#336699');
        $this->assertSame([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950], array_keys($sh));
        $this->assertSame('#336699', $sh[500]);
        $this->assertStringContainsString('--color-primary-700', $this->themes()->css('clay', '#336699'));
    }

    public function test_req_0329_unreadable_custom_color_is_rejected(): void
    {
        $s = $this->makeSubscriber();
        $this->tenant()->set($s->id);
        app(Settings::class)->setPlatform('theme.min_contrast_ratio', ['numerator' => 45, 'denominator' => 10]);
        $this->themes()->assertReadable('#1F2328');   // dark ink: readable with white text
        $this->expectException(ThemeException::class);
        $this->themes()->assertReadable('#F4E4D9');   // pale: unreadable with white text
    }

    public function test_req_0329_custom_colors_are_refused_while_the_ratio_is_not_configured(): void
    {
        $this->expectException(ThemeException::class);
        $this->themes()->assertReadable('#000000');
    }

    public function test_req_0654_launch_check_fails_until_the_platform_default_theme_is_set(): void
    {
        $this->assertNull($this->themes()->activePresetKey());
        $this->assertSame(1, Artisan::call('platform:launch-check'));
        app(Settings::class)->setPlatform('platform.default_theme', 'ink');
        $this->assertSame(0, Artisan::call('platform:launch-check'));
        $this->assertSame('ink', $this->themes()->activePresetKey());
    }

    public function test_req_0328_subscriber_preset_overrides_platform_default(): void
    {
        app(Settings::class)->setPlatform('platform.default_theme', 'clay');
        $s = $this->makeSubscriber();
        $this->tenant()->set($s->id);
        app(Settings::class)->setSubscriber('theme.preset', 'plum');
        $this->assertSame('plum', $this->themes()->activePresetKey());
    }
}
