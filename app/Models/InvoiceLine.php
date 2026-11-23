<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Invoice line carrying its tax-treatment code (REQ-0420). Append-only. */
class InvoiceLine extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['invoice_id', 'description_ar', 'description_en', 'net_minor', 'tax_code', 'tax_rate_bp', 'tax_minor', 'total_minor'];

    protected function casts(): array
    {
        return ['net_minor' => MoneyCast::class, 'tax_minor' => MoneyCast::class, 'total_minor' => MoneyCast::class];
    }
}
