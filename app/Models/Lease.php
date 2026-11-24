<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Lease contract — state machine of spec 5.4 (REQ-0181…REQ-0186, REQ-0139, REQ-0140). */
class Lease extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $attributes = ['status' => 'draft', 'kind' => 'lease', 'track' => 'long', 'deposit_minor' => 0];

    protected $fillable = ['leasable_unit_id', 'tenant_id', 'lease_offer_id', 'parent_lease_id', 'kind', 'usage', 'track', 'starts_on', 'ends_on', 'annual_rent_minor', 'payment_frequency', 'deposit_minor'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'non_renewal_notice_on' => 'date', 'closed_at' => 'datetime', 'annual_rent_minor' => MoneyCast::class, 'deposit_minor' => MoneyCast::class, 'archived_at' => 'datetime', 'auto_renewed_at' => 'datetime', 'terminated_at' => 'datetime'];
    }

    public function unit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LeasableUnit::class, 'leasable_unit_id');
    }

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scheduleLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BillingScheduleLine::class)->whereNull('archived_at')->orderBy('sequence');
    }

    public function escalations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RentEscalation::class);
    }
}
