<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** A space held by a lease, hold or maintenance for a date range; overlaps are rejected by PostgreSQL (REQ-0179). */
class SpaceOccupancy extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['space_id', 'lease_id', 'lease_offer_id', 'kind', 'period'];
}
