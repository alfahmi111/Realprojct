<?php

namespace App\Compliance;

use App\Models\Subscriber;
use App\Models\User;
use App\Notifications\NotificationCenter;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Expiry alerts 30 and 7 days before (spec 6.7): the office licence (Q31). Runs inside the subscriber's context from
 * the daily notifications run and sends `document.expiring` to the office's managers. Returns what was alerted.
 */
final class ExpiryAlerts
{
    public const DAYS_BEFORE = [30, 7];

    public const LICENSE_KIND_AR = ['property_management' => 'رخصة إدارة الأملاك', 'brokerage' => 'رخصة الوساطة العقارية', 'both' => 'رخصة إدارة الأملاك والوساطة'];

    public function __construct(private NotificationCenter $notifications, private TenantContext $ctx)
    {
    }

    /** @return list<string> */
    public function run(CarbonImmutable $today): array
    {
        $sid = $this->ctx->subscriberId();
        $s = $sid ? $this->ctx->run(null, fn () => Subscriber::find($sid)) : null;
        $sent = [];
        $stage = $s?->license_expires_on ? ExpiryStage::due($today, $s->license_expires_on) : null;
        if ($stage !== null && ! ExpiryStage::alreadySent('document.expiring', $s, $stage)) {
            $managers = User::where('subscriber_id', $s->id)->where('role', 'manager')->whereNull('archived_at')->get()->all();
            $this->notifications->dispatch('document.expiring', $managers, [
                'المستند' => self::LICENSE_KIND_AR[$s->license_kind] ?? 'رخصة المكتب',
                'الرقم' => (string) $s->license_no,
                'تاريخ الانتهاء' => $s->license_expires_on->format('Y-m-d'),
                'التنبيه' => ExpiryStage::LABELS[$stage],
            ], $today->setTime(4, 0), urgent: true, subject: $s);
            $sent[] = 'office_license';
        }
        // the document vault (REQ-0270) — only inside a subscriber's context
        $r = $s ? app(\App\Documents\DocumentVault::class)->alerts($today, $this->notifications) : ['alerted' => 0];
        if ($r['alerted']) {
            $sent[] = 'documents:' . $r['alerted'];
        }
        return $sent;
    }
}
