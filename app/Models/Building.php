<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Building — a property may have one or several (REQ-0076, REQ-0134). */
class Building extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['property_id', 'name_ar', 'name_en'];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }
}
