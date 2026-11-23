<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToSubscriber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Management announcement to tenants (water cut, lift maintenance) — REQ-0278. Audience: one or more buildings
 * (none = all) minus excluded units; visible from published_at until expires_at (owner request 2026-11-23).
 */
class Announcement extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['building_id', 'building_ids', 'excluded_unit_ids', 'title_ar', 'body_ar', 'published_at', 'expires_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'expires_at' => 'datetime', 'building_ids' => 'array', 'excluded_unit_ids' => 'array'];
    }

    /** Ids are stored as integers so JSON containment matches (forms send strings). */
    public function setBuildingIdsAttribute($v): void
    {
        $this->attributes['building_ids'] = json_encode(array_values(array_map('intval', array_filter((array) $v, fn ($x) => $x !== null && $x !== ''))));
    }

    public function setExcludedUnitIdsAttribute($v): void
    {
        $this->attributes['excluded_unit_ids'] = json_encode(array_values(array_map('intval', array_filter((array) $v, fn ($x) => $x !== null && $x !== ''))));
    }

    /** Live now, for a tenant in this building and leasable unit. */
    public function scopeVisibleTo(Builder $q, ?int $buildingId, ?int $unitId, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now();
        return $q->where('published_at', '<=', $now)
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->where(fn ($w) => $w->whereRaw("building_ids = '[]'::jsonb")->when($buildingId, fn ($x) => $x->orWhereJsonContains('building_ids', $buildingId)))
            ->when($unitId, fn ($w) => $w->whereNot(fn ($x) => $x->whereJsonContains('excluded_unit_ids', $unitId)));
    }

    /** scheduled | live | ended */
    public function state(?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();
        return match (true) {
            $this->published_at > $now => 'scheduled',
            $this->expires_at !== null && $this->expires_at <= $now => 'ended',
            default => 'live',
        };
    }
}
