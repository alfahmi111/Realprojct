<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class AuditLogger
{
    public static function record(string $action, Model|string $subject, ?array $before, ?array $after, ?string $subjectId = null): AuditLog
    {
        $subscriberId = $subject instanceof Model && array_key_exists('subscriber_id', $subject->getAttributes())
            ? $subject->subscriber_id
            : app(TenantContext::class)->subscriberId();

        $log = new AuditLog();
        $log->forceFill([
            'subscriber_id' => $subscriberId,
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $subject instanceof Model ? $subject->getMorphClass() : $subject,
            'auditable_id' => $subject instanceof Model ? (string) $subject->getKey() : $subjectId,
            'before' => $before,
            'after' => $after,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ])->save();

        return $log;
    }
}
