<?php

namespace App\Console\Commands;

use App\Settings\Settings;
use App\Theming\ThemeService;
use Illuminate\Console\Command;

/**
 * Release-readiness gate. Fails while anything required before launch is unset:
 *  - platform default theme (decision 11, REQ-0654)
 *  - at least one penetration test before launch (REQ-0508)
 *  - production engines: Redis queue/cache, S3 storage, debug off (REQ-0520, REQ-0523, REQ-0500)
 */
class LaunchCheck extends Command
{
    protected $signature = 'platform:launch-check {--production-config : also verify production engines in the current .env}';

    protected $description = 'Fail if anything required before launch is still unset';

    public function handle(ThemeService $themes, Settings $settings): int
    {
        $problems = $themes->launchProblems();
        if (! app(\App\Support\Dates\DateService::class)->hijriTrusted($why)) {
            $problems[] = "Hijri calendar is not Umm al-Qura on this server ($why) — REQ-0345";
        }
        if ($settings->get('launch.penetration_test_passed') !== true) {
            $problems[] = 'launch.penetration_test_passed is not set — a penetration test is required before launch (REQ-0508)';
        }
        // Tenant isolation: every subscriber table under RLS (REQ-0169, REQ-0487) — checked in every environment (M16).
        foreach (\App\Support\Database\RlsAudit::missing() as $table) {
            $problems[] = "Table [$table] has subscriber data but no forced row-level security (REQ-0487)";
        }
        $warnings = [];
        if (! function_exists('opcache_get_status') || ! (@opcache_get_status(false)['opcache_enabled'] ?? false)) {
            $warnings[] = 'OPcache is off for this PHP — enable it on the production server (speed)';
        }
        if ($this->option('production-config') || app()->environment('production')) {
            foreach (\App\Integrations\IntegrationStatus::ITEMS as $key => [$name]) {
                $driver = \App\Integrations\IntegrationStatus::driver($key);
                if ($driver === 'simulator') {
                    $problems[] = "Integration [$key] runs on the simulator — never in production (decision 5)";
                } elseif ($driver === 'none') {
                    $warnings[] = "Integration [$key] ($name) has no provider: the feature refuses until the owner contracts one";
                }
            }
            if (! config('session.secure')) {
                $problems[] = 'SESSION_SECURE_COOKIE must be true in production (cookies only over HTTPS)';
            }
            if (! config('session.encrypt')) {
                $problems[] = 'SESSION_ENCRYPT must be true in production';
            }
            if (config('app.debug')) {
                $problems[] = 'APP_DEBUG must be false in production';
            }
            if (config('queue.default') !== 'redis') {
                $problems[] = 'QUEUE_CONNECTION must be redis in production (REQ-0520)';
            }
            if (config('cache.default') !== 'redis') {
                $problems[] = 'CACHE_STORE must be redis in production (REQ-0520)';
            }
            if (config('filesystems.default') !== 's3') {
                $problems[] = 'FILESYSTEM_DISK must be s3 (in-Kingdom) in production (REQ-0523, REQ-0483)';
            }
            if (! str_starts_with((string) config('app.url'), 'https://')) {
                $problems[] = 'APP_URL must use https (encryption in transit, REQ-0506)';
            }
        }
        foreach ($warnings as $w) {
            $this->warn("! $w");
        }
        foreach ($problems as $p) {
            $this->error("✗ $p");
        }
        if ($problems) {
            return self::FAILURE;
        }
        $this->info('Launch check passed.');
        return self::SUCCESS;
    }
}
