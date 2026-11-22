<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Which part of the portfolio a user sees: role + scope (REQ-0494). */
class UserScope extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['user_id', 'scope_type', 'scope_id'];
}
