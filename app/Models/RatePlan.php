<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Price of a leasable unit per duration unit; the day price is not the month price ÷ 30 (REQ-0177). */
class RatePlan extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['leasable_unit_id', 'duration_unit', 'price_minor'];

    protected function casts(): array
    {
        return ['price_minor' => MoneyCast::class, 'archived_at' => 'datetime'];
    }
}
