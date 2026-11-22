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
        foreach ($ctx->run(null, fn () => Subscriber::whereNull('archived_at')->pluck('id')) as $sid) {
            $ctx->run($sid, function () use ($today, $sid) {
                app(TrustAccounting::class)->recognizeMonth($today);
                $found = app(Reconciliation::class)->run($today);
                $this->line("subscriber $sid: " . json_encode($found));
            });
        }
        return self::SUCCESS;
    }
}
