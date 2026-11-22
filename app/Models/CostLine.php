<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Labour / part / vendor cost of a work order (REQ-0146). Append-only. */
class CostLine extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['work_order_id', 'kind', 'description_ar', 'quantity', 'unit_cost_minor', 'total_minor'];

    protected function casts(): array
    {
        return ['unit_cost_minor' => MoneyCast::class, 'total_minor' => MoneyCast::class];
    }
}
