<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Lease offer before the draft (REQ-0184, REQ-0048, REQ-0149). */
class LeaseOffer extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $attributes = ['status' => 'draft', 'escalations' => '[]', 'deposit_minor' => 0];

    protected $fillable = ['leasable_unit_id', 'tenant_id', 'created_by', 'usage', 'starts_on', 'duration_unit', 'duration_count', 'annual_rent_minor', 'listed_annual_rent_minor', 'payment_frequency', 'deposit_minor', 'escalations', 'valid_until', 'source_prospect_id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'valid_until' => 'date', 'escalations' => 'array', 'annual_rent_minor' => MoneyCast::class, 'listed_annual_rent_minor' => MoneyCast::class, 'deposit_minor' => MoneyCast::class, 'archived_at' => 'datetime'];
    }

    public function unit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LeasableUnit::class, 'leasable_unit_id');
    }

    public function approvals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LeaseOfferApproval::class)->orderBy('step');
    }
}
