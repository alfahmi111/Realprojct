<?php

namespace Tests\Feature\Foundation;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M0 — approved framework versions and environment (spec §11.6).
 */
class PlatformVersionsTest extends TestCase
{
    private function lockedVersion(string $package): string
    {
        $lock = json_decode(file_get_contents(base_path('composer.lock')), true);
        foreach (array_merge($lock['packages'], $lock['packages-dev']) as $p) {
            if ($p['name'] === $package) {
                return ltrim($p['version'], 'v');
            }
        }
        $this->fail("$package is not in composer.lock");
    }

    /** REQ-0514 — PHP 8.4 (accepted 8.3 to 8.5). REQ-0478 — modern supported PHP. */
    public function test_req_0514_php_version_is_within_8_3_and_8_5(): void
    {
        $this->assertTrue(version_compare(PHP_VERSION, '8.3.0', '>='));
        $this->assertTrue(version_compare(PHP_VERSION, '8.6.0', '<'));
    }

    /** REQ-0515 — Laravel 13.x. */
    public function test_req_0515_laravel_is_13_x(): void
    {
        $this->assertStringStartsWith('13.', $this->lockedVersion('laravel/framework'));
        $this->assertStringStartsWith('13.', app()->version());
    }

    /** REQ-0516 — Livewire 4.x (4.2 or later supports Laravel 13). REQ-0479 — Laravel + Livewire web UI. */
    public function test_req_0516_livewire_is_4_2_or_later(): void
    {
        $v = $this->lockedVersion('livewire/livewire');
        $this->assertStringStartsWith('4.', $v);
        $this->assertTrue(version_compare($v, '4.2.0', '>='));
    }

    /** REQ-0517 — Filament 5.x. REQ-0526 — Laravel 13 + Livewire 4 + Filament 5 resolved together. REQ-0513, REQ-0524 — version table and rules. REQ-0558 — bootstrap with pinned versions. */
    public function test_req_0517_filament_is_5_x(): void
    {
        $this->assertStringStartsWith('5.', $this->lockedVersion('filament/filament'));
    }

    /** REQ-0518 — Tailwind is the version Filament 5 uses (v4), no other major pinned. */
    public function test_req_0518_tailwind_matches_filament_major(): void
    {
        $pkg = json_decode(file_get_contents(base_path('package.json')), true);
        $tw = $pkg['devDependencies']['tailwindcss'] ?? $pkg['dependencies']['tailwindcss'] ?? null;
        $this->assertNotNull($tw);
        $this->assertMatchesRegularExpression('/^\^?4\./', $tw);
    }

    /** REQ-0519 — PostgreSQL 16+. REQ-0481 — PostgreSQL database. */
    public function test_req_0519_postgresql_is_16_or_later(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $num = (int) DB::selectOne('show server_version_num')->server_version_num;
        $this->assertGreaterThanOrEqual(160000, $num);
    }

    /** REQ-0522 — Composer 2.x lock file. REQ-0525 — exact versions pinned in both lock files. */
    public function test_req_0525_lock_files_pin_exact_versions(): void
    {
        $this->assertFileExists(base_path('composer.lock'));
        $this->assertFileExists(base_path('package-lock.json'));
        $lock = json_decode(file_get_contents(base_path('composer.lock')), true);
        $this->assertNotEmpty($lock['content-hash']);
        foreach ($lock['packages'] as $p) {
            $this->assertMatchesRegularExpression('/^v?\d+\.\d+/', $p['version'], $p['name']);
        }
        $npm = json_decode(file_get_contents(base_path('package-lock.json')), true);
        $this->assertGreaterThanOrEqual(2, $npm['lockfileVersion']);
    }

    /** REQ-0528 — required PHP extensions. */
    public function test_req_0528_required_php_extensions_are_loaded(): void
    {
        foreach (['pdo_pgsql', 'pgsql', 'mbstring', 'intl', 'bcmath', 'zip', 'xml', 'dom', 'curl', 'openssl', 'fileinfo', 'tokenizer', 'ctype'] as $ext) {
            $this->assertTrue(extension_loaded($ext), "missing extension $ext");
        }
        $this->assertTrue(extension_loaded('gd') || extension_loaded('imagick'));
    }

    /**
     * REQ-0520, REQ-0523, REQ-0534, REQ-0535, REQ-0538 — queue/cache/storage drivers come only from .env
     * through Laravel's config; development uses database + local disk, production can switch to redis + s3.
     */
    public function test_req_0538_drivers_are_configured_from_env_only(): void
    {
        $config = file_get_contents(config_path('queue.php')) . file_get_contents(config_path('cache.php')) . file_get_contents(config_path('filesystems.php'));
        $this->assertStringContainsString("env('QUEUE_CONNECTION'", $config);
        $this->assertStringContainsString("env('CACHE_STORE'", $config);
        $this->assertStringContainsString("env('FILESYSTEM_DISK'", $config);

        $example = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('QUEUE_CONNECTION=database', $example);
        $this->assertStringContainsString('CACHE_STORE=database', $example);
        $this->assertStringContainsString('FILESYSTEM_DISK=local', $example);
        $this->assertArrayHasKey('redis', config('database'));
        $this->assertArrayHasKey('s3', config('filesystems.disks'));
    }

    /** REQ-0546 — application timezone is UTC. REQ-0166 — dates stored in UTC. */
    public function test_req_0546_application_and_database_timezone_is_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
        $this->assertSame('UTC', DB::selectOne('show timezone')->TimeZone ?? DB::selectOne('show timezone')->timezone);
    }

    /** REQ-0490 — Arabic RTL first, English second. */
    public function test_req_0490_default_locale_is_arabic_with_english_fallback(): void
    {
        $this->assertSame('ar', config('app.locale'));
        $this->assertSame('en', config('app.fallback_locale'));
    }
}
