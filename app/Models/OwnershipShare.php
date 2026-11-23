<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An owner's share in a property as an exact fraction (REQ-0130, REQ-0131) — no decimals. */
class OwnershipShare extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['property_id', 'owner_id', 'share_numerator', 'share_denominator'];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }
}
