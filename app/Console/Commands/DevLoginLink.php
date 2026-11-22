<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * LOCAL DEVELOPMENT ONLY: a single-use login link that expires in 5 minutes, used to review and screenshot inner pages
 * without the 2FA challenge. Refuses to run, and its route is not even registered, outside APP_ENV=local.
 */
class DevLoginLink extends Command
{
    protected $signature = 'dev:login-link {email} {--path=/manage}';

    protected $description = 'LOCAL ONLY: one-time login link for screenshots';

    public function handle(TenantContext $ctx): int
    {
        if (! app()->environment('local')) {
            $this->error('Local environment only.');
            return self::FAILURE;
        }
        $ctx->setLoginEmail($this->argument('email'));
        $user = User::where('email', $this->argument('email'))->first();
        $ctx->clear();
        if (! $user) {
            $this->error('No such user.');
            return self::FAILURE;
        }
        $token = Str::random(48);
        Cache::put('dev-login:' . hash('sha256', $token), ['id' => $user->id, 'path' => $this->option('path')], now()->addMinutes(5));
        $this->line(url('/dev-login/' . $token));
        return self::SUCCESS;
    }
}
