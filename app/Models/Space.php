<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Space — a physical node in the tree: floor / unit / room / common area / rentable location on a facility
 * (REQ-0071, REQ-0077…REQ-0081, REQ-0135, REQ-0136).
 */
class Space extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    public const KINDS = ['floor', 'unit', 'room', 'common_area', 'rentable_location', 'land'];

    protected $fillable = ['property_id', 'building_id', 'parent_id', 'kind', 'unit_type', 'code', 'name_ar', 'name_en', 'attributes', 'sort'];

    protected function casts(): array
    {
        return ['attributes' => 'array', 'archived_at' => 'datetime'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->whereNull('archived_at')->orderBy('sort');
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function leasableUnits(): BelongsToMany
    {
        return $this->belongsToMany(LeasableUnit::class, 'leasable_unit_space')->withPivot('subscriber_id');
    }
}
