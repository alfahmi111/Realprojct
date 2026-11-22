<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Property — deed, property number, real-estate registry number, national address (REQ-0075, REQ-0123). */
class Property extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['portfolio_id', 'name_ar', 'name_en', 'template', 'deed_number', 'ejar_property_number', 'real_estate_registry_number', 'national_address', 'region', 'city', 'within_urban_boundary', 'latitude', 'longitude'];

    protected function casts(): array
    {
        return ['national_address' => 'array', 'within_urban_boundary' => 'boolean', 'archived_at' => 'datetime'];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }

    public function leasableUnits(): HasMany
    {
        return $this->hasMany(LeasableUnit::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(OwnershipShare::class);
    }

    public function managementAgreements(): BelongsToMany
    {
        return $this->belongsToMany(ManagementAgreement::class, 'management_agreement_property')->withPivot('subscriber_id');
    }
}
