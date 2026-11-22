<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Vendor file: CR, rating, agreed prices, invoices (REQ-0235). */
class Vendor extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['name_ar', 'name_en', 'commercial_registration', 'phone', 'email', 'rating', 'agreed_prices', 'user_id'];

    protected function casts(): array
    {
        return ['agreed_prices' => 'array', 'archived_at' => 'datetime'];
    }
}
