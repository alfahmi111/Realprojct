<?php

namespace App\Dashboards;

use App\Finance\InvoiceService;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * What the office knows about each tenant, in one place (owner request 2026-11-23): current unit and property, when the
 * lease ends, what is outstanding and how many invoices are overdue, and how many leases the tenant has had.
 * Integers in halalas (ر-02); RLS limits everything to the subscriber.
 */
class TenantProfiles
{
    private ?array $cache = null;

    public function __construct(private InvoiceService $invoices)
    {
    }

    /** @return array<int,array{unit:?string,property:?string,lease_id:?int,ends_on:?CarbonImmutable,days_left:?int,outstanding:int,overdue:int,leases:int}> */
    public function all(?CarbonImmutable $today = null): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $today ??= \App\Support\Dates\Today::date();
        $leases = Lease::whereIn('status', PortfolioTree::LIVE)->with('unit.property')->orderByDesc('starts_on')->get()->groupBy('tenant_id');
        $counts = Lease::selectRaw('tenant_id, count(*) n')->groupBy('tenant_id')->pluck('n', 'tenant_id');
        $open = Invoice::where('status', 'issued')->whereNotNull('tenant_id')->get()->groupBy('tenant_id');
        $out = [];
        foreach (Tenant::pluck('id') as $id) {
            $l = ($leases[$id] ?? collect())->first(fn ($x) => $x->ends_on >= $today);
            $outstanding = 0;
            $overdue = 0;
            foreach ($open[$id] ?? [] as $inv) {
                $due = $this->invoices->outstanding($inv)->minor();
                $outstanding += $due;
                if ($due > 0 && CarbonImmutable::parse($inv->period_start ?? $inv->issue_date) <= $today) {
                    $overdue++;
                }
            }
            $ends = $l ? CarbonImmutable::parse($l->ends_on) : null;
            $out[$id] = ['unit' => $l?->unit?->code, 'property' => $l?->unit?->property?->name_ar, 'lease_id' => $l?->id, 'ends_on' => $ends,
                'days_left' => $ends ? (int) $today->diffInDays($ends, false) : null, 'outstanding' => $outstanding, 'overdue' => $overdue, 'leases' => (int) ($counts[$id] ?? 0)];
        }
        return $this->cache = $out;
    }

    public function summary(): array
    {
        $all = collect($this->all());
        return [
            'total' => $all->count(),
            'active' => $all->whereNotNull('lease_id')->count(),
            'arrears' => $all->where('outstanding', '>', 0)->count(),
            'arrears_total' => $all->sum('outstanding'),
            'ending' => $all->filter(fn ($p) => $p['days_left'] !== null && $p['days_left'] <= 90)->count(),
        ];
    }
}
