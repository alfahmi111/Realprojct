<?php

namespace Tests\Feature\Foundation;

use App\Settings\Settings;
use App\Support\Database\RlsAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M16 launch hardening: tenant isolation on every subscriber table (REQ-0487), baseline security headers (REQ-0506),
 * the launch gate refuses simulators and insecure sessions in production (REQ-0508), settings read once per request
 * without serving a stale value after a save (REQ-0323).
 */
class LaunchHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_req_0487_every_subscriber_table_has_forced_row_level_security(): void
    {
        $this->assertSame([], RlsAudit::missing(), 'A new table with subscriber_id must call Rls::enable() in its migration.');
        $this->assertCount(2, RlsAudit::EXEMPT);
    }

    public function test_req_0506_security_headers_on_public_and_app_pages(): void
    {
        foreach (['/manage/login', '/manifest.webmanifest'] as $url) {
            $r = $this->get($url);
            $r->assertHeader('X-Frame-Options', 'SAMEORIGIN');
            $r->assertHeader('X-Content-Type-Options', 'nosniff');
            $r->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $this->assertStringContainsString("frame-ancestors 'self'", $r->headers->get('Content-Security-Policy'));
            $this->assertStringContainsString('geolocation=(self)', $r->headers->get('Permissions-Policy'));
        }
    }

    public function test_req_0508_launch_check_refuses_simulators_and_insecure_sessions_in_production_config(): void
    {
        config(['integrations.sadad' => 'simulator', 'session.secure' => false]);
        $code = Artisan::call('platform:launch-check', ['--production-config' => true]);
        $out = Artisan::output();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Integration [sadad] runs on the simulator', $out);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE must be true', $out);
        $this->assertStringNotContainsString('row-level security', $out);
    }

    /** REQ-0506: the office colour is printed raw into <style>; anything but #RRGGBB never reaches the page. */
    public function test_req_0506_custom_colour_cannot_inject_markup(): void
    {
        $evil = '#000;}</style><script>alert(1)</script>';
        $css = app(\App\Theming\ThemeService::class)->css(null, $evil);
        $this->assertStringNotContainsString('<script>', $css);
        $this->expectException(\App\Theming\ThemeException::class);
        app(\App\Theming\ThemeService::class)->assertReadable($evil);
    }

    public function test_req_0323_settings_are_read_once_per_request_and_a_save_is_seen_at_once(): void
    {
        $s = $this->makeSubscriber();
        $this->tenant()->set($s->id);
        $settings = app(Settings::class);
        $before = $settings->get('leasing.non_renewal_notice_days');
        DB::enableQueryLog();
        DB::flushQueryLog();
        for ($i = 0; $i < 20; $i++) {
            $settings->get('leasing.non_renewal_notice_days');
        }
        $this->assertCount(0, DB::getQueryLog(), 'repeated reads in one request hit neither the cache nor the database');
        $settings->setSubscriber('leasing.non_renewal_notice_days', 45);
        $this->assertSame(45, $settings->get('leasing.non_renewal_notice_days'));
        $this->assertNotSame($before, 45);
    }
}
