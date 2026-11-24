<?php

namespace App\Console\Commands;

use App\Finance\Reconciliation;
use App\Finance\TrustAccounting;
use App\Models\Subscriber;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Nightly job per subscriber: revenue recognition + reconciliation work queue (REQ-0432, REQ-0434). */
class NightlyReconciliation extends Command
{
    protected $signature = 'finance:nightly {--date=}';

    protected $description = 'Recognise deferred rent and reconcile contracts, invoices and payments for every subscriber';

    public function handle(TenantContext $ctx): int
    {
        $today = CarbonImmutable::parse($this->option('date') ?? 'today');
        $failed = 0;
        foreach ($ctx->run(null, fn () => Subscriber::whereNull('archived_at')->pluck('id')) as $sid) {
            try {   // one office's error must not stop the run for the others (review M18)
                $ctx->run($sid, function () use ($today, $sid) {
                    // this month and the 12 before it: an invoice issued late for a past period is caught up (review M18);
                    // recognizeMonth posts each invoice-month once, so repeating is safe
                    for ($i = 12; $i >= 0; $i--) {
                        app(TrustAccounting::class)->recognizeMonth($today->startOfMonth()->subMonthsNoOverflow($i));
                    }
                    $found = app(Reconciliation::class)->run($today);
                    $this->line("subscriber $sid: " . json_encode($found));
                });
            } catch (\Throwable $e) {
                report($e);
                $this->error("subscriber $sid failed: " . $e->getMessage());
                $failed++;
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
