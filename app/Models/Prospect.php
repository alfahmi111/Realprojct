<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Prospect on the waitlist; personal data encrypted (REQ-0148, REQ-0391). */
class Prospect extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['building_qr_id', 'name', 'phone', 'email', 'unit_type', 'budget_minor', 'desired_month', 'consent_at', 'consent_version', 'last_confirmed_at'];

    protected function casts(): array
    {
        return ['name' => 'encrypted', 'phone' => 'encrypted', 'email' => 'encrypted', 'budget_minor' => MoneyCast::class, 'consent_at' => 'datetime', 'last_confirmed_at' => 'datetime'];
    }

    protected $attributes = ['status' => 'active'];

    protected $hidden = ['name', 'phone', 'email'];

    protected function auditHidden(): array
    {
        return ['name', 'phone', 'email'];
    }
}
