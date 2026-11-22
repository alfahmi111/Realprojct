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
 * Platform name «وِثاق» and mark; per-user preferences applied to the interface.
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
        $res->assertSee('وِثاق');
        $res->assertSee('brand/withaq-favicon.svg', false);
        $this->assertFileExists(public_path('brand/withaq-mark.svg'));
        $this->assertSame('وِثاق', config('app.name'));
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
