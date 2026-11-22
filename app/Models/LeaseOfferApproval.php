<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** One approval step of a lease offer. */
class LeaseOfferApproval extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['lease_offer_id', 'step', 'approver_role'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function offer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LeaseOffer::class, 'lease_offer_id');
    }
}
