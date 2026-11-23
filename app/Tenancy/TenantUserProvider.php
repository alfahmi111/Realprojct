<?php

namespace App\Tenancy;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves users through RLS: before each lookup it opens exactly the one row being authenticated,
 * then switches the database session into that user's subscriber context.
 */
class TenantUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        $ctx = app(TenantContext::class);
        $ctx->setUserId((int) $identifier);
        return $this->enter(parent::retrieveById($identifier));
    }

    public function retrieveByToken($identifier, $token)
    {
        app(TenantContext::class)->setUserId((int) $identifier);
        return $this->enter(parent::retrieveByToken($identifier, $token));
    }

    public function retrieveByCredentials(array $credentials)
    {
        $ctx = app(TenantContext::class);
        $ctx->setLoginEmail($credentials['email'] ?? null);
        try {
            $user = parent::retrieveByCredentials($credentials);
        } finally {
            $ctx->setLoginEmail(null);
        }
        if ($user) {
            $ctx->setUserId($user->getAuthIdentifier());
        }
        return $this->enter($user);
    }

    private function enter(?Authenticatable $user): ?Authenticatable
    {
        if ($user) {
            $ctx = app(TenantContext::class);
            $ctx->set($user->subscriber_id);
            $ctx->setPlatform($user->subscriber_id === null);
        }
        return $user;
    }
}
