<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Human work-queue item from the nightly reconciliation (REQ-0434). */
class ReconciliationIssue extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['kind', 'subject_type', 'subject_id', 'details', 'detected_on', 'status'];

    protected function casts(): array
    {
        return ['details' => 'array', 'detected_on' => 'date'];
    }

    protected $attributes = ['status' => 'open'];
}
