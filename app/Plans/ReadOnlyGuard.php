<?php

namespace App\Plans;

use App\Models\AuditLog;
use App\Models\Subscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Q24 (owner 2026-11-23): once the grace period is over, an office is read-only. Every Eloquent save or delete made by a
 * signed-in user of that office is refused. Not affected: the audit log, the user's own account (password, two-factor),
 * system processes without a signed-in user (reminders, nightly reconciliation), and reading or exporting (ر-20).
 * Registered as a scoped service: the state is measured once per request.
 */
final class ReadOnlyGuard
{
    private const ALWAYS_ALLOWED = [AuditLog::class, User::class];

    private ?bool $readOnly = null;

    public function __construct(private SubscriberAdmin $admin)
    {
    }

    public function check(Model $model): void
    {
        if (in_array($model::class, self::ALWAYS_ALLOWED, true) || ! $this->active()) {
            return;
        }
        throw new SubscriptionReadOnlyException();
    }

    public function active(): bool
    {
        if ($this->readOnly !== null) {
            return $this->readOnly;
        }
        $user = auth()->user();
        if (! $user instanceof User || $user->subscriber_id === null) {
            return $this->readOnly = false;
        }
        $s = app(\App\Tenancy\TenantContext::class)->run(null, fn () => Subscriber::find($user->subscriber_id));
        return $this->readOnly = $s !== null && $this->admin->isReadOnly($s, CarbonImmutable::today());
    }

    /** After a renewal or a grace extension in the same request. */
    public function forget(): void
    {
        $this->readOnly = null;
    }
}
