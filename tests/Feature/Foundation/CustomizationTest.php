<?php

namespace Tests\Feature\Foundation;

use App\Settings\Settings;
use App\Theming\Customization;
use App\Theming\ThemeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REQ-0333, REQ-0334, REQ-0335, REQ-0336, REQ-0337 — customization by plan.
 * REQ-0332 — custom domain for the company plan. REQ-0341 — logo per mode.
 * REQ-0331 — the subscriber's identity (name) is used, not the platform's.
 */
class CustomizationTest extends TestCase
{
    use RefreshDatabase;

    private function c(): Customization
    {
        return app(Customization::class);
    }

    public function test_req_0335_owner_plan_gets_ready_theme_and_logo_only(): void
    {
        $s = $this->makeSubscriber('owner');
        $this->tenant()->set($s->id);
        $this->c()->set($s, 'theme.preset', 'clay');
        $this->c()->set($s, 'theme.logo_light', 'logos/a.png');
        $this->expectException(ThemeException::class);
        $this->c()->set($s, 'theme.custom_primary', '#1F2328');
    }

    public function test_req_0336_office_plan_adds_custom_primary_color_and_document_identity(): void
    {
        $s = $this->makeSubscriber('office');
        $this->tenant()->set($s->id);
        app(Settings::class)->setPlatform('theme.min_contrast_ratio', ['numerator' => 45, 'denominator' => 10]);
        $this->c()->set($s, 'theme.custom_primary', '#1F2328');
        $this->assertTrue($this->c()->allows($s, 'identity.documents'));
        $this->assertTrue($this->c()->allows($s, 'identity.messages'));
        $this->expectException(ThemeException::class);
        $this->c()->set($s, 'theme.custom_domain', 'portal.office.sa');
    }

    public function test_req_0337_company_plan_gets_full_white_label_including_req_0332_custom_domain(): void
    {
        $s = $this->makeSubscriber('company');
        $this->tenant()->set($s->id);
        $this->c()->set($s, 'theme.custom_domain', 'portal.officename.sa');
        $this->c()->set($s, 'theme.app_name', 'بوابة المكتب');
        $this->assertTrue($this->c()->allows($s, 'identity.login_page'));
        $this->get('/manifest.webmanifest')->assertOk();
    }

    public function test_req_0334_unreadable_custom_color_is_refused_even_on_allowed_plans(): void
    {
        $s = $this->makeSubscriber('company');
        $this->tenant()->set($s->id);
        app(Settings::class)->setPlatform('theme.min_contrast_ratio', ['numerator' => 45, 'denominator' => 10]);
        $this->expectException(ThemeException::class);
        $this->c()->set($s, 'theme.custom_primary', '#FBEED3');
    }

    public function test_req_0333_only_platform_offered_themes_can_be_chosen(): void
    {
        $s = $this->makeSubscriber('office');
        app(Settings::class)->setPlatform('platform.available_themes', ['clay', 'ink']);
        $this->tenant()->set($s->id);
        $this->expectException(ThemeException::class);
        $this->c()->set($s, 'theme.preset', 'plum');
    }

    public function test_req_0341_dark_logo_if_uploaded_else_light_logo_on_light_backdrop(): void
    {
        $s = $this->makeSubscriber('owner');
        $this->tenant()->set($s->id);
        $this->c()->set($s, 'theme.logo_light', 'l.png');
        $this->assertSame(['src' => 'l.png', 'light_backdrop' => true], $this->c()->logoFor('dark'));
        $this->assertSame(['src' => 'l.png', 'light_backdrop' => false], $this->c()->logoFor('light'));
        $this->c()->set($s, 'theme.logo_dark', 'd.png');
        $this->assertSame(['src' => 'd.png', 'light_backdrop' => false], $this->c()->logoFor('dark'));
    }

    public function test_req_0331_the_subscriber_name_is_the_displayed_identity(): void
    {
        $s = $this->makeSubscriber('office', 'مكتب الرياض');
        $this->assertSame('مكتب الرياض', $this->c()->displayName($s));
        $this->assertSame('Office', $this->c()->displayName($s, 'en'));
    }
}
