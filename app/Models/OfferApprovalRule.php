<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Approval step for lease offers by role and discount (REQ-0184). */
class OfferApprovalRule extends Model
{
    use Archivable, Auditable, BelongsToSubscriber;

    protected $fillable = ['step', 'approver_role', 'min_discount_bp'];
}
