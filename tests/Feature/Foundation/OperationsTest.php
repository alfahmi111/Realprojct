<?php

namespace Tests\Feature\Foundation;

use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * REQ-0500 — separate environments; tests never touch the development data (own schema).
 * REQ-0502, REQ-0504 — automated tests + vulnerable-package audit run together before every change (composer ci).
 * REQ-0508 — at least one penetration test before launch (launch gate).
 * REQ-0520, REQ-0523 — production engines are Redis and in-Kingdom S3 (launch gate with --production-config).
 */
class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_req_0500_tests_run_isolated_from_development_data(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('testing', config('database.connections.pgsql.search_path'));
        $this->assertSame('testing', \DB::selectOne('select current_schema() as s')->s);
    }

    public function test_req_0504_ci_script_runs_tests_audit_and_traceability(): void
    {
        $scripts = json_decode(file_get_contents(base_path('composer.json')), true)['scripts']['ci'];
        $this->assertContains('@php artisan test', $scripts);
        $this->assertContains('@composer audit', $scripts);
        $this->assertContains('@php tools/trace_check.php', $scripts);
    }

    public function test_req_0508_launch_is_blocked_until_a_penetration_test_is_recorded(): void
    {
        app(Settings::class)->setPlatform('platform.default_theme', 'clay');
        $this->assertSame(1, Artisan::call('platform:launch-check'));
        $this->assertStringContainsString('REQ-0508', Artisan::output());
        app(Settings::class)->setPlatform('launch.penetration_test_passed', true);
        $this->assertSame(0, Artisan::call('platform:launch-check'));
    }

    public function test_req_0520_production_config_requires_redis_s3_https_and_no_debug(): void
    {
        app(Settings::class)->setPlatform('platform.default_theme', 'clay');
        app(Settings::class)->setPlatform('launch.penetration_test_passed', true);
        $this->assertSame(1, Artisan::call('platform:launch-check', ['--production-config' => true]));
        $out = Artisan::output();
        foreach (['REQ-0520', 'REQ-0523', 'https'] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
        // Settings come from a stub here so switching the cache engine to Redis does not need a Redis server.
        $stub = $this->createStub(Settings::class);
        $stub->method('get')->willReturnMap([['launch.penetration_test_passed', null, null, true], ['platform.default_theme', null, null, 'clay'], ['theme.preset', null, null, null]]);
        $this->app->instance(Settings::class, $stub);
        $this->app->forgetInstance(\App\Theming\ThemeService::class);
        config(['app.debug' => false, 'queue.default' => 'redis', 'cache.default' => 'redis', 'filesystems.default' => 's3', 'app.url' => 'https://app.example.sa', 'session.secure' => true, 'session.encrypt' => true]);
        $this->assertSame(0, Artisan::call('platform:launch-check', ['--production-config' => true]));
    }
}
