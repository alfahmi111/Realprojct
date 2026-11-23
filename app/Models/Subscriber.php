<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A SaaS subscriber (property-management office or institutional owner) — "Tenant" in the SaaS sense (REQ-0169).
 * Profile, subscription period, agreed price and add-ons are managed by the platform admin (REQ-0065, REQ-0323).
 */
class Subscriber extends Model
{
    use Auditable;

    public const PLANS = ['owner', 'office', 'company'];

    public const CYCLES = ['monthly' => 'شهري', 'annual' => 'سنوي'];

    protected $fillable = ['name_ar', 'name_en', 'plan', 'status', 'commercial_registration', 'vat_number', 'city', 'contact_name', 'contact_phone', 'contact_email',
        'billing_cycle', 'subscription_starts_on', 'subscription_ends_on', 'price_minor', 'addons', 'notes', 'grace_days', 'license_kind', 'license_no', 'license_expires_on'];

    protected function casts(): array
    {
        return [
            'license_expires_on' => 'date',
            'archived_at' => 'datetime', 'suspended_at' => 'datetime',
            'subscription_starts_on' => 'date', 'subscription_ends_on' => 'date',
            'price_minor' => MoneyCast::class, 'addons' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }
}
