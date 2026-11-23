<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Allocation of a payment to an invoice (REQ-0143, REQ-0188). Append-only. */
class PaymentAllocation extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['payment_id', 'invoice_id', 'amount_minor'];

    protected function casts(): array
    {
        return ['amount_minor' => MoneyCast::class];
    }

    public function payment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
