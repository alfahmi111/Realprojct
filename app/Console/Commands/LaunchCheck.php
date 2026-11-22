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
        if ($settings->get('launch.penetration_test_passed') !== true) {
            $problems[] = 'launch.penetration_test_passed is not set — a penetration test is required before launch (REQ-0508)';
        }
        if ($this->option('production-config') || app()->environment('production')) {
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
