<?php

namespace App\Console\Commands;

use App\Maintenance\MaintenanceService;
use App\Models\Subscriber;
use App\Notifications\NotificationCenter;
use App\Notifications\PaymentReminders;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Every few minutes: deliver due notifications and escalate; daily: reminders, digests, preventive work orders. */
class RunNotifications extends Command
{
    protected $signature = 'notifications:run {--daily}';

    protected $description = 'Deliver queued notifications, escalations, and (daily) reminders and digests';

    public function handle(TenantContext $ctx): int
    {
        $now = CarbonImmutable::now();
        foreach ($ctx->run(null, fn () => Subscriber::whereNull('archived_at')->pluck('id')) as $sid) {
            $ctx->run($sid, function () use ($now) {
                if ($this->option('daily')) {
                    app(PaymentReminders::class)->run($now->startOfDay());
                    app(NotificationCenter::class)->sendDigests($now);
                    app(MaintenanceService::class)->generatePreventive($now->startOfDay());
                    app(\App\BuildingQr\BuildingQrService::class)->alertLongWaitlistWhileVacant($now->startOfDay());   // REQ-0409
                    app(\App\Plans\SubscriptionNotices::class)->run($now->startOfDay());                              // Q24
                }
                app(NotificationCenter::class)->escalate($now);
                app(NotificationCenter::class)->deliver($now);
            });
        }
        return self::SUCCESS;
    }
}
