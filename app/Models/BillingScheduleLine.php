<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Payment schedule line (REQ-0141, REQ-0187). */
class BillingScheduleLine extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $attributes = ['status' => 'scheduled'];

    protected $fillable = ['lease_id', 'sequence', 'due_on', 'period_start', 'period_end', 'amount_minor', 'status', 'escalation_id'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'period_start' => 'date', 'period_end' => 'date', 'amount_minor' => MoneyCast::class, 'archived_at' => 'datetime'];
    }

    public function lease(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }
}
