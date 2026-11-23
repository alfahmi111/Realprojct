<?php

namespace App\Console\Commands;

use App\Models\Subscriber;
use App\Privacy\DataRetention;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Daily: apply each office's retention periods (REQ-0371). Offices without periods lose nothing. */
class ApplyDataRetention extends Command
{
    protected $signature = 'data:retention';

    protected $description = "Apply each subscriber's data retention periods";

    public function handle(TenantContext $ctx): int
    {
        $now = CarbonImmutable::now();
        $total = 0;
        foreach ($ctx->run(null, fn () => Subscriber::whereNull('archived_at')->pluck('id')) as $sid) {
            $done = $ctx->run($sid, fn () => app(DataRetention::class)->apply($now));
            foreach ($done as $type => $count) {
                $this->line("subscriber $sid: $type −$count");
                $total += $count;
            }
        }
        $this->info("Retention applied: $total records removed.");
        return self::SUCCESS;
    }
}
