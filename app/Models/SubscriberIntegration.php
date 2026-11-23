<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

class SubscriberIntegration extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['integration', 'account_holder_name', 'credentials', 'active'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'active' => 'boolean'];
    }

    protected function auditHidden(): array
    {
        return ['credentials'];
    }
}
