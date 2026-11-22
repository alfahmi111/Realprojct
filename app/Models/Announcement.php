<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Management announcement to tenants (water cut, lift maintenance) — REQ-0278. */
class Announcement extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['building_id', 'title_ar', 'body_ar', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
