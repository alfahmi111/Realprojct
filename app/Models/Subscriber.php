<?php

namespace App\Models;

use App\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A SaaS subscriber (property-management office or institutional owner) — "Tenant" in the SaaS sense (REQ-0169).
 */
class Subscriber extends Model
{
    use Auditable;

    public const PLANS = ['owner', 'office', 'company'];

    protected $fillable = ['name_ar', 'name_en', 'plan', 'status'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
