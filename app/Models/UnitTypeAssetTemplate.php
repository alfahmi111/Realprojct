<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Default asset list per unit type, confirmed or edited by the manager in wizard step 5 (REQ-0127). */
class UnitTypeAssetTemplate extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['unit_type', 'name_ar', 'name_en', 'category', 'quantity'];
}
