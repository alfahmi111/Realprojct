<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Portfolio — a group of properties of an owner or under a manager (REQ-0074). */
class Portfolio extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['name_ar', 'name_en', 'owner_id'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];   // review M18
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
