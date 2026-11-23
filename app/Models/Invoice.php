<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Invoice (REQ-0142, REQ-0424). Archive-only. */
class Invoice extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['number', 'kind', 'seller_type', 'seller_owner_id', 'tenant_id', 'buyer_owner_id', 'lease_id', 'billing_schedule_line_id', 'property_id', 'issue_date', 'period_start', 'period_end', 'ejar_contract_number', 'unit_code', 'buyer_vat_number', 'buyer_national_address', 'einvoice_type', 'subtotal_minor', 'tax_minor', 'total_minor', 'status'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'period_start' => 'date', 'period_end' => 'date', 'buyer_national_address' => 'array', 'subtotal_minor' => MoneyCast::class, 'tax_minor' => MoneyCast::class, 'total_minor' => MoneyCast::class, 'archived_at' => 'datetime'];
    }

    protected $attributes = ['status' => 'issued'];

    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function property(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function allocations():\Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
