<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Manufacturer/supplier warranty or annual maintenance contract (REQ-0155, REQ-0220). */
class Warranty extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['kind', 'asset_id', 'building_id', 'vendor_id', 'scope_ar', 'starts_on', 'ends_on', 'reference'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'archived_at' => 'datetime'];
    }
}
