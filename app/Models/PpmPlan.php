<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Planned preventive maintenance schedule (REQ-0223). */
class PpmPlan extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['asset_id', 'space_id', 'title_ar', 'every_months', 'next_due_on', 'vendor_id'];

    protected function casts(): array
    {
        return ['next_due_on' => 'date', 'archived_at' => 'datetime'];
    }
}
