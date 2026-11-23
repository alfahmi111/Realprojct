<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Holds the current subscriber (SaaS tenant) and pushes it into the PostgreSQL session so that
 * Row-Level Security policies isolate every query (REQ-0169, REQ-0487). The application never
 * filters tenants by code alone: if a query forgets a WHERE clause, PostgreSQL still hides other rows.
 */
class TenantContext
{
    private ?int $subscriberId = null;

    public function subscriberId(): ?int
    {
        return $this->subscriberId;
    }

    public function hasSubscriber(): bool
    {
        return $this->subscriberId !== null;
    }

    public function set(?int $subscriberId): void
    {
        $this->subscriberId = $subscriberId;
        $this->setting('app.subscriber_id', $subscriberId === null ? '' : (string) $subscriberId);
    }

    /** Run a callback inside a subscriber's context and restore the previous one afterwards. */
    public function run(?int $subscriberId, callable $callback): mixed
    {
        $previous = $this->subscriberId;
        $this->set($subscriberId);
        try {
            return $callback();
        } finally {
            $this->set($previous);
        }
    }

    /** Lets the authentication layer reach exactly one users row (by id) before the subscriber is known. */
    public function setUserId(?int $userId): void
    {
        $this->setting('app.user_id', $userId === null ? '' : (string) $userId);
    }

    /** Lets the login form reach exactly one users row (by e-mail) before the subscriber is known. */
    public function setLoginEmail(?string $email): void
    {
        $this->setting('app.login_email', $email ?? '');
    }

    /** Platform-admin context: no subscriber, platform rows only. */
    public function setPlatform(bool $on): void
    {
        $this->setting('app.platform', $on ? 'on' : '');
    }

    public function clear(): void
    {
        $this->set(null);
        $this->setUserId(null);
        $this->setLoginEmail(null);
        $this->setPlatform(false);
    }

    private function setting(string $name, string $value): void
    {
        DB::select('select set_config(?, ?, false)', [$name, $value]);
    }
}
