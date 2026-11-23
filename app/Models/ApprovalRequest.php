<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalRequest extends Model
{
    use Auditable, BelongsToSubscriber;

    /** Sensitive financial entries that need maker/checker (REQ-0069). */
    public const ACTIONS = ['invoice.cancel', 'invoice.discount', 'invoice.waiver', 'payment.edit', 'trust.disburse', 'deposit.refund'];

    protected $fillable = ['action', 'subject_type', 'subject_id', 'payload', 'maker_id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'decided_at' => 'datetime'];
    }

    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maker_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checker_id');
    }
}
