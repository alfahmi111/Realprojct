<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Lessee: individual or company (REQ-0063, REQ-0140). */
class Tenant extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['kind', 'name_ar', 'name_en', 'national_id', 'commercial_registration', 'vat_number', 'national_address', 'phone', 'email', 'messages_locale', 'user_id'];

    protected function casts(): array
    {
        return ['national_id' => 'encrypted', 'commercial_registration' => 'encrypted', 'national_address' => 'array', 'archived_at' => 'datetime'];
    }
}
