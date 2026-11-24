<?php

namespace App\Plans;

use App\Models\Subscriber;
use App\Models\User;
use App\Notifications\NotificationCenter;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Q24 (owner 2026-11-23): during the grace period the office's managers get a notice every day; on the first read-only
 * day they get one more. Runs inside the subscriber's context from the daily notifications run.
 * Review M18 D4: a second run on the same day sends nothing more, and the read-only notice is caught up if its exact
 * day was missed (sent once per subscription end date).
 */
final class SubscriptionNotices
{
    public function __construct(private SubscriberAdmin $admin, private NotificationCenter $notifications, private TenantContext $ctx)
    {
    }

    public function run(CarbonImmutable $today): ?string
    {
        $sid = $this->ctx->subscriberId();
        $s = $sid ? $this->ctx->run(null, fn () => Subscriber::find($sid)) : null;
        if (! $s) {
            return null;
        }
        $state = $this->admin->state($s, $today);
        $daysSinceEnd = -($this->admin->daysRemaining($s, $today) ?? 0);
        $event = match (true) {
            $state === 'grace' => 'subscription.grace',
            $state === 'read_only' && $daysSinceEnd >= $this->admin->graceDays($s) + 1 => 'subscription.read_only',
            default => null,
        };
        if ($event === null) {
            return null;
        }
        $end = $s->subscription_ends_on->format('Y-m-d');
        // sent since this subscription ended = already told (a renewal moves the end date, so a later lapse is told again)
        $sent = \Illuminate\Support\Facades\DB::table('notifications_outbox')->where('event', $event)->where('subject_type', 'Subscriber')->where('subject_id', $s->id)
            ->where('created_at', '>=', $end);
        if ($event === 'subscription.grace' ? (clone $sent)->whereDate('created_at', $today->toDateString())->exists() : $sent->exists()) {
            return null;
        }
        $managers = User::where('subscriber_id', $s->id)->where('role', 'manager')->whereNull('archived_at')->get()->all();
        $vars = ['تاريخ الانتهاء' => $s->subscription_ends_on->format('Y-m-d')];
        if ($event === 'subscription.grace') {
            $vars['المتبقي من المهلة'] = $this->admin->graceLeft($s, $today) . ' يوم';
        }
        $this->notifications->dispatch($event, $managers, $vars, $today->setTime(4, 0), urgent: true, subject: $s);
        return $event;
    }
}
