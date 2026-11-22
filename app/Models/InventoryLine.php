<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** One asset in a count (REQ-0152, REQ-0153). */
class InventoryLine extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['inventory_count_id', 'asset_id', 'present', 'condition', 'photo', 'note', 'added_during_count'];

    protected function casts(): array
    {
        return ['present' => 'boolean', 'added_during_count' => 'boolean'];
    }

    public function count(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(InventoryCount::class, 'inventory_count_id');
    }
}
