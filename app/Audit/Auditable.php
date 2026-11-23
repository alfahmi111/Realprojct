<?php

namespace App\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Writes an append-only audit row (who, what, when, value before and after) for every change
 * to the model (REQ-0068, REQ-0497).
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $m) => AuditLogger::record('created', $m, null, $m->auditSnapshot($m->getAttributes())));
        static::updated(function (Model $m) {
            $changed = array_keys($m->getChanges());
            // Ignore timestamps and secrets (e.g. remember_token on logout): nothing auditable changed.
            $changed = array_values(array_diff($changed, ['updated_at'], $m->auditHiddenAttributes()));
            if (! $changed) {
                return;
            }
            $before = array_intersect_key($m->getOriginal(), array_flip($changed));
            $after = array_intersect_key($m->getAttributes(), array_flip($changed));
            $action = in_array('archived_at', $changed, true) && $m->archived_at !== null ? 'archived' : 'updated';
            AuditLogger::record($action, $m, $m->auditSnapshot($before), $m->auditSnapshot($after));
        });
        static::deleted(fn (Model $m) => AuditLogger::record('deleted', $m, $m->auditSnapshot($m->getOriginal()), null));
    }

    /** Attributes never written to the audit log (secrets). */
    protected function auditHidden(): array
    {
        return ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'];
    }

    public function auditHiddenAttributes(): array
    {
        return $this->auditHidden();
    }

    public function auditSnapshot(array $attributes): array
    {
        return array_diff_key($attributes, array_flip($this->auditHidden()));
    }
}
