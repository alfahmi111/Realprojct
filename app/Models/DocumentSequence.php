<?php

namespace App\Models;

use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Per-subscriber document numbering counters (REQ-0366). */
class DocumentSequence extends Model
{
    use BelongsToSubscriber;

    protected $fillable = ['kind', 'year', 'last_number'];

    public $timestamps = false;
}
