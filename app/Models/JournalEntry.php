<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Double-entry journal entry (trust accounting, REQ-0428). Append-only. */
class JournalEntry extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['entry_date', 'kind', 'memo_ar', 'source_type', 'source_id', 'owner_id', 'property_id', 'external_ref'];

    protected function casts(): array
    {
        return ['entry_date' => 'date'];
    }

    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
