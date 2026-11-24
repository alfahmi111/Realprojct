<?php

namespace Tests\Feature\Screens;

use App\Filament\Support\MyPreferencesPage;
use App\Models\Subscriber;
use App\Models\User;
use App\Settings\Settings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Platform name «وِثاق» and its logo (REQ-0328 platform identity; the square-Kufic «الحضن الكوفي» chosen by the owner on
 * 2026-11-25, interface colours unchanged); per-user preferences applied to the interface.
 * REQ-0373 (font size, high contrast, reduce motion, table density, landing page), REQ-0326, REQ-0338, REQ-0321 (locked keys),
 * REQ-0331 (installed app carries the subscriber's name, not the platform's).
 */
class BrandingAndPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private Subscriber $s;
    private User $tenantUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSubscriber('office', 'مكتب الأمانة');
        $this->tenantUser = $this->makeUser($this->s, 'tenant');
        $this->tenant()->set($this->s->id);
    }

    public function test_platform_name_and_mark_on_login_pages(): void
    {
        $this->tenant()->clear();
        $res = $this->get('/manage/login')->assertOk();
        // the stacked logo (square-Kufic wordmark over Withaq) on the identity panel, owner's choice 2026-11-25
        $res->assertSee('aria-label="وِثاق Withaq"', false);
        $res->assertSee('brand/withaq-favicon.svg', false);
        $res->assertSee('brand/icons/apple-touch-icon.png', false);
        $res->assertDontSee('withaq-mark', false);
        $this->assertSame('وِثاق', config('app.name'));
    }

    public function test_req_0328_the_brand_files_are_one_colour_paths_without_live_text(): void
    {
        foreach (['withaq-wordmark', 'withaq-stacked', 'withaq-horizontal', 'withaq-symbol', 'withaq-latin'] as $name) {
            $svg = file_get_contents(public_path("brand/$name.svg"));
            $this->assertStringContainsString('fill="currentColor"', $svg, $name);   // follows the theme colour
            $this->assertStringNotContainsString('<text', $svg, $name);              // letters are drawn, not typed
            $this->assertStringNotContainsString('<mask', $svg, $name);
            $this->assertStringNotContainsString('<image', $svg, $name);
        }
        foreach (['icon-192.png', 'icon-512.png', 'maskable-512.png', 'apple-touch-icon.png'] as $png) {
            $this->assertGreaterThan(0, filesize(public_path("brand/icons/$png")), $png);
        }
        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')));
        $this->assertFileDoesNotExist(public_path('brand/withaq-mark.svg'));
    }

    public function test_req_0328_the_panel_header_and_the_site_carry_the_kufic_wordmark(): void
    {
        // the panel's brandLogo view: the wordmark itself, no separate name text
        $logo = view('brand.logo')->render();
        $this->assertStringContainsString(file_get_contents(public_path('brand/withaq-wordmark.svg')), $logo);
        $this->assertStringNotContainsString('font-family', $logo);
        $this->tenant()->clear();
        $site = $this->get('/')->assertOk();
        $site->assertSee('class="ws-logo"', false);
        $site->assertDontSee('withaq-mark', false);
    }

    public function test_req_0331_the_platform_manifest_uses_the_new_icons_outside_a_subscriber(): void
    {
        $this->tenant()->clear();
        $icons = $this->get('/manifest.webmanifest')->assertOk()->json('icons');
        $srcs = array_column($icons, 'src');
        $this->assertContains(asset('brand/icons/icon-512.png'), $srcs);
        $this->assertContains('maskable', array_column($icons, 'purpose'));
    }

    public function test_req_0373_preferences_are_saved_and_applied_to_the_interface(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Livewire::actingAs($this->tenantUser)->test(MyPreferencesPage::class)
            ->set('data.a11y__font_size', 'larger')->set('data.a11y__high_contrast', true)->set('data.a11y__reduce_motion', true)
            ->set('data.a11y__table_density', 'compact')->set('data.appearance__mode', 'dark')->call('save');
        $s = app(Settings::class);
        $this->assertSame('larger', $s->get('a11y.font_size', $this->tenantUser->id));
        $this->assertTrue($s->get('a11y.reduce_motion', $this->tenantUser->id));
        $html = $this->actingAs($this->tenantUser)->get('/tenant/my-preferences')->assertOk()->getContent();
        foreach (['fontSize = "larger"', 'contrast = "high"', 'reduceMotion = "1"', 'density = "compact"', 'themeMode = "dark"'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
    }

    public function test_req_0373_landing_page_opens_on_login(): void
    {
        app(Settings::class)->setUser($this->tenantUser->id, 'a11y.landing_page', 'my-unit');
        $this->actingAs($this->tenantUser)->get('/tenant')->assertRedirect(url('/tenant/my-unit'));
    }

    public function test_req_0321_a_key_locked_by_the_subscriber_is_not_changed_from_the_preferences_page(): void
    {
        app(Settings::class)->setSubscriber('numbers.digits', 'western', locked: true);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Livewire::actingAs($this->tenantUser)->test(MyPreferencesPage::class)->set('data.numbers__digits', 'eastern')->call('save');
        $this->assertSame('western', app(Settings::class)->get('numbers.digits', $this->tenantUser->id));
    }

    public function test_req_0331_installed_app_shows_the_subscriber_name_not_the_platform(): void
    {
        $this->actingAs($this->tenantUser)->get('/manifest.webmanifest')->assertJsonPath('name', 'مكتب الأمانة');
        $this->tenant()->clear();
        auth()->logout();
        $this->get('/manifest.webmanifest')->assertJsonPath('name', 'وِثاق');
    }
}
