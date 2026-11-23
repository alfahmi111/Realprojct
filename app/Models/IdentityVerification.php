<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** One identity check against Nafath, Yaqeen or Wathq (REQ-0445…REQ-0447). Only a masked reference is stored. */
class IdentityVerification extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['subject_type', 'subject_id', 'source', 'status', 'reference_masked', 'returned_name', 'details', 'verified_by', 'verified_at'];

    protected function casts(): array
    {
        return ['details' => 'array', 'verified_at' => 'datetime'];
    }
}
