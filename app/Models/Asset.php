<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Equipment attached to any node of the tree (REQ-0073, REQ-0144). Full register in M5 (REQ-0211). */
class Asset extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['space_id', 'qr_code', 'category', 'type', 'name_ar', 'name_en', 'brand', 'model', 'serial_number', 'installed_on', 'warranty_ends_on', 'condition', 'cost_minor', 'useful_life_months', 'photos',
        'depreciation_method', 'depreciation_rate_bp', 'depreciation_annual_minor', 'salvage_minor'];

    protected function casts(): array
    {
        return ['installed_on' => 'date', 'warranty_ends_on' => 'date', 'photos' => 'array', 'archived_at' => 'datetime', 'cost_minor' => MoneyCast::class,
            'depreciation_rate_bp' => 'integer', 'depreciation_annual_minor' => 'integer', 'salvage_minor' => 'integer'];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }
}
