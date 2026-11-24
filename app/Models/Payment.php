<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Payment received directly by the manager or owner (REQ-0436); the platform only records and matches. */
class Payment extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['tenant_id', 'receipt_number', 'amount_minor', 'method', 'source', 'received_on', 'reference', 'external_key', 'gateway_payment_id', 'paid_to', 'recorded_by'];

    protected function casts(): array
    {
        return ['received_on' => 'date', 'amount_minor' => MoneyCast::class, 'archived_at' => 'datetime'];
    }

    protected $attributes = ['status' => 'recorded'];

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function allocations():\Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
