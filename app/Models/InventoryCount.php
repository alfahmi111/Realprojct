<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Inventory count (REQ-0151, REQ-0263); handover reports are counts of type entry/exit (REQ-0164). */
class InventoryCount extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['type', 'space_id', 'counted_on', 'performed_by', 'technician_signature', 'tenant_signature', 'status'];

    protected function casts(): array
    {
        return ['counted_on' => 'date'];
    }

    protected $attributes = ['status' => 'open'];

    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InventoryLine::class);
    }
}
