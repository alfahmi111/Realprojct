<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Building QR with a permanent short code (REQ-0147, REQ-0393). */
class BuildingQr extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['building_id', 'code'];

    protected function casts(): array
    {
        return ['paused' => 'boolean', 'ad_license_expires_on' => 'date'];
    }

    protected $attributes = ['paused' => false, 'scan_count' => 0];
}
