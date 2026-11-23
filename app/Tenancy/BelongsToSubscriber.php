<?php

namespace App\Tenancy;

use App\Models\Subscriber;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fills subscriber_id from the current context on create. Isolation itself is enforced by RLS,
 * so a wrong or missing subscriber_id is rejected by PostgreSQL (WITH CHECK).
 */
trait BelongsToSubscriber
{
    public static function bootBelongsToSubscriber(): void
    {
        static::creating(function ($model) {
            if ($model->subscriber_id === null) {
                $model->subscriber_id = app(TenantContext::class)->subscriberId();
            }
        });
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
