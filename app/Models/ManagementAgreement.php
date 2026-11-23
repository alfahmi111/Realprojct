<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Management agreement between owner and manager (REQ-0066): covered properties, fee type
 * (percentage of collected / fixed / mixed), spend cap without owner approval, who collects funds.
 * When the owner is the manager, a "self" agreement with zero fee keeps the logic single (REQ-0067).
 */
class ManagementAgreement extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['owner_id', 'is_self', 'fee_type', 'fee_percentage_bp', 'fee_fixed_minor', 'spend_cap_minor', 'collector', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'is_self' => 'boolean', 'starts_on' => 'date', 'ends_on' => 'date', 'archived_at' => 'datetime',
            'fee_fixed_minor' => MoneyCast::class, 'spend_cap_minor' => MoneyCast::class,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'management_agreement_property')->withPivot('subscriber_id');
    }
}
