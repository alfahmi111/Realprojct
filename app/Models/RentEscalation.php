<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Scheduled rent increase (REQ-0150, REQ-0190). */
class RentEscalation extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $attributes = ['status' => 'scheduled', 'percentage_bp' => 0, 'amount_minor' => 0];

    protected $fillable = ['lease_id', 'kind', 'percentage_bp', 'amount_minor', 'effective_on', 'contract_year', 'status', 'suspension_reason', 'policy_id'];

    protected function casts(): array
    {
        return ['effective_on' => 'date', 'amount_minor' => MoneyCast::class];
    }
}
