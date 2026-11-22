<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Journal line. Append-only. */
class JournalLine extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['journal_entry_id', 'account', 'owner_id', 'debit_minor', 'credit_minor'];

    protected function casts(): array
    {
        return ['debit_minor' => MoneyCast::class, 'credit_minor' => MoneyCast::class];
    }
}
