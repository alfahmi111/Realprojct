<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * What is offered for rent (REQ-0072, REQ-0137): one space (an apartment) or several (whole building / floor).
 * Allowed tracks flag + hospitality licence (REQ-0176); last registered rent (REQ-0199).
 */
class LeasableUnit extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['property_id', 'code', 'name_ar', 'name_en', 'unit_type', 'is_composite', 'allowed_tracks', 'hospitality_license_no', 'hospitality_license_expires_on', 'last_registered_rent_minor'];

    protected function casts(): array
    {
        return ['is_composite' => 'boolean', 'hospitality_license_expires_on' => 'date', 'archived_at' => 'datetime', 'last_registered_rent_minor' => MoneyCast::class];
    }

    protected static function booted(): void
    {
        // Plan unit limit (REQ-0635…REQ-0637); composites do not count.
        static::creating(function (self $u) {
            if (! $u->is_composite && ($sid = app(\App\Tenancy\TenantContext::class)->subscriberId())) {
                $s = app(\App\Tenancy\TenantContext::class)->run(null, fn () => \App\Models\Subscriber::find($sid));
                \App\Plans\PlanEntitlements::assertCanAddUnit($s);
            }
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function spaces(): BelongsToMany
    {
        return $this->belongsToMany(Space::class, 'leasable_unit_space')->withPivot('subscriber_id');
    }

    /**
     * Leasable units that share at least one space with this one (a composite and its parts, REQ-0102).
     * A lease on any of them blocks the others for the same period (enforced by the lease engine, M3).
     */
    public function overlappingUnitIds(): array
    {
        return DB::table('leasable_unit_space as a')
            ->join('leasable_unit_space as b', 'a.space_id', '=', 'b.space_id')
            ->where('a.leasable_unit_id', $this->id)
            ->where('b.leasable_unit_id', '<>', $this->id)
            ->distinct()->pluck('b.leasable_unit_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    }

    /** Short-term (daily/weekly) letting is allowed only with a valid hospitality licence (REQ-0176, REQ-0441). */
    public function canOpenShortTerm(\DateTimeInterface $on): bool
    {
        return in_array($this->allowed_tracks, ['short', 'both'], true)
            && $this->hospitality_license_no !== null
            && $this->hospitality_license_expires_on !== null
            && $this->hospitality_license_expires_on->endOfDay() >= $on;
    }
}
