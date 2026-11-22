<?php

namespace App\Console\Commands;

use App\Theming\ThemeService;
use Illuminate\Console\Command;

/**
 * Release-readiness check. Fails while open launch decisions are unset (decision 11 for now; more checks are
 * added as features land — e.g. pending legal opinions, providers).
 */
class LaunchCheck extends Command
{
    protected $signature = 'platform:launch-check';

    protected $description = 'Fail if anything required before launch is still unset';

    public function handle(ThemeService $themes): int
    {
        $problems = $themes->launchProblems();
        if (app()->environment('production') && config('app.debug')) {
            $problems[] = 'APP_DEBUG must be false in production';
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
