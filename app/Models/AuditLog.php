<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only audit row. The database rejects UPDATE/DELETE/TRUNCATE (REQ-0068, REQ-0497); the model refuses too.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log rows are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit log rows are immutable.'));
    }
}
