<?php

namespace App\Leasing;

use App\Models\Lease;
use App\Models\LeaseOffer;
use App\Models\User;
use App\Notifications\NotificationCenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Daily lease housekeeping (system review M18), inside the subscriber's context:
 *  - a lease that received notice and passed its end date becomes «expired»;
 *  - a sent offer past its validity is expired and its hold on the unit released;
 *  - managers are told once when a lease enters the last 14 days before its notice deadline (`lease.expiring`);
 *  - a lease past its end date with no notice is flagged once: by the spec it renews automatically, but the renewal
 *    (new term, schedule, Ejar documentation) is recorded by a person — the platform never creates it on its own (Q35).
 * Every alert is sent at most once per lease (the outbox subject is checked), so a missed day is caught up.
 */
final class LeaseLifecycle
{
    public const NOTICE_LEAD_DAYS = 14;

    public function __construct(private LeasingService $leasing, private NotificationCenter $notifications)
    {
    }

    /** @return array{expired:int, offers:int, notice_alerts:int, renewal_alerts:int} */
    public function run(CarbonImmutable $today): array
    {
        $r = ['expired' => 0, 'offers' => 0, 'notice_alerts' => 0, 'renewal_alerts' => 0];
        foreach (Lease::where('status', 'notice_given')->where('ends_on', '<', $today->toDateString())->get() as $l) {
            $this->leasing->transition($l, 'expired');
            $r['expired']++;
        }
        foreach (LeaseOffer::whereIn('status', ['approved', 'sent'])->whereNotNull('valid_until')->where('valid_until', '<', $today->toDateString())->get() as $o) {
            $this->leasing->expireOffer($o, 'انتهت صلاحية العرض دون قبول');
            $r['offers']++;
        }
        $managers = null;
        foreach (Lease::whereIn('status', ['active', 'registered'])->where('ends_on', '>=', $today->toDateString())->with('unit')->get() as $l) {
            $deadline = $this->leasing->noticeDeadline($l);
            if ($today->between($deadline->subDays(self::NOTICE_LEAD_DAYS), $deadline) && ! $this->alerted('lease.expiring', $l)) {
                $managers ??= $this->managers();
                $this->notifications->dispatch('lease.expiring', $managers, ['الوحدة' => (string) $l->unit?->code, 'آخر موعد للإشعار' => $deadline->toDateString(), 'نهاية العقد' => $l->ends_on->toDateString()], $today->setTime(4, 0), subject: $l);
                $r['notice_alerts']++;
            }
        }
        foreach (Lease::where('status', 'active')->where('ends_on', '<', $today->toDateString())->with('unit')->get() as $l) {
            if (! $this->alerted('lease.renewal_due', $l)) {
                $managers ??= $this->managers();
                $this->notifications->dispatch('lease.renewal_due', $managers, ['الوحدة' => (string) $l->unit?->code, 'نهاية العقد' => $l->ends_on->toDateString()], $today->setTime(4, 0), urgent: true, subject: $l);
                $r['renewal_alerts']++;
            }
        }
        return $r;
    }

    private function managers(): array
    {
        return User::where('role', 'manager')->whereNull('archived_at')->get()->all();
    }

    private function alerted(string $event, Lease $l): bool
    {
        return DB::table('notifications_outbox')->where('event', $event)->where('subject_type', class_basename($l))->where('subject_id', $l->id)->exists();
    }
}
