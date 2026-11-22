<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Owner: individual, heirs or company (REQ-0058). Identity numbers are encrypted and hidden (REQ-0391, REQ-0453). */
class Owner extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['kind', 'name_ar', 'name_en', 'national_id', 'commercial_registration', 'vat_registered', 'vat_number', 'resident', 'user_id'];

    protected $hidden = ['national_id', 'commercial_registration'];

    protected function casts(): array
    {
        return ['national_id' => 'encrypted', 'commercial_registration' => 'encrypted', 'vat_registered' => 'boolean', 'resident' => 'boolean', 'archived_at' => 'datetime'];
    }

    protected function auditHidden(): array
    {
        return ['national_id', 'commercial_registration'];
    }

    public function shares(): HasMany
    {
        return $this->hasMany(OwnershipShare::class);
    }
}
