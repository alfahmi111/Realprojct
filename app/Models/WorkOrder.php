<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Work order — lifecycle of spec 6.3 (REQ-0145, REQ-0227…REQ-0230). */
class WorkOrder extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['number', 'type', 'priority', 'property_id', 'space_id', 'asset_id', 'lease_id', 'ppm_plan_id', 'reported_by_type', 'reported_by_user_id', 'description', 'photos_report', 'reported_at'];

    protected function casts(): array
    {
        return ['photos_report' => 'array', 'photos_before' => 'array', 'photos_after' => 'array', 'estimated_cost_minor' => MoneyCast::class, 'owner_approval_required' => 'boolean', 'tenant_caused' => 'boolean', 'reported_at' => 'datetime', 'responded_at' => 'datetime', 'completed_at' => 'datetime', 'closed_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    protected $attributes = ['status' => 'reported', 'photos_report' => '[]', 'photos_before' => '[]', 'photos_after' => '[]'];

    public function property(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function space(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function vendor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function assignee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function costLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CostLine::class);
    }
}
