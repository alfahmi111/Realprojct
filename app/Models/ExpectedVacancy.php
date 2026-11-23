<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Expected vacancy with confidence (REQ-0156, REQ-0410). */
class ExpectedVacancy extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['leasable_unit_id', 'lease_id', 'month', 'confidence', 'source'];
}
