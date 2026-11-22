<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Bootstrap the first platform admin (REQ-0065) in platform context. */
class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin {email} {name}';

    protected $description = 'Create a platform admin user (asks for the password)';

    public function handle(TenantContext $ctx): int
    {
        $password = $this->secret('Password (min 12 characters)');
        if (strlen((string) $password) < 12) {
            $this->error('Password too short.');
            return self::FAILURE;
        }
        $ctx->clear();
        $ctx->setPlatform(true);
        User::create(['subscriber_id' => null, 'role' => 'platform_admin', 'email' => $this->argument('email'), 'name' => $this->argument('name'), 'password' => $password]);
        $this->info('Platform admin created.');
        return self::SUCCESS;
    }
}
