<?php

namespace App\Dashboards;

use App\Finance\InvoiceService;
use App\Finance\Ledger;
use App\Models\Invoice;
use App\Models\LeasableUnit;
use App\Models\Lease;
use App\Models\LeaseOffer;
use App\Models\PaymentAllocation;
use App\Models\RatePlan;
use App\Models\WorkOrder;
use App\Properties\UnitTypes;
use App\Support\Money\Money;
use App\Support\Money\Rounding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Manager KPIs per building and for the portfolio (spec §7.2, REQ-0281…REQ-0295); the owner sees the same for his
 * properties only (REQ-0304). Ratios are returned in basis points (integers); money in halalas.
 * Scope: a set of property ids (portfolio) optionally narrowed to one building.
 */
class Kpis
{
    public function __construct(private InvoiceService $invoices, private Ledger $ledger)
    {
    }

    public function all(array $propertyIds, CarbonImmutable $from, CarbonImmutable $to, ?int $buildingId = null): array
    {
        $units = $this->units($propertyIds, $buildingId);
        return [
            'physical_occupancy_bp' => $this->physicalOccupancy($units, $to),
            'financial_occupancy_bp' => $this->financialOccupancy($units, $to),
            'collection_rate_bp' => $this->collectionRate($propertyIds, $from, $to),
            'aging' => $this->aging($propertyIds, $to),
            'avg_vacancy_days' => $this->averageVacancyDays($units),
            'renewal_rate_bp' => $this->renewalRate($units, $from, $to),
            'maintenance_cost' => $this->maintenanceCost($propertyIds, $units, $from, $to),
            'noi' => $this->noi($propertyIds, $from, $to),
            'avoided_vacancy_days' => $this->avoidedVacancyDays($units),
        ];
    }

    /** Leased units ÷ total units (REQ-0283). */
    public function physicalOccupancy($units, CarbonImmutable $on): int
    {
        $total = $units->count();
        if ($total === 0) {
            return 0;
        }
        $leased = $units->filter(fn ($u) => $this->isLeasedOn($u, $on))->count();
        return intdiv($leased * 10000, $total);
    }

    /** Actual rent ÷ potential rent if everything were let (REQ-0284). */
    public function financialOccupancy($units, CarbonImmutable $on): int
    {
        $actual = Money::zero();
        $potential = Money::zero();
        foreach ($units as $u) {
            $lease = Lease::where('leasable_unit_id', $u->id)->whereIn('status', ['registered', 'active', 'notice_given'])
                ->where('starts_on', '<=', $on->toDateString())->where('ends_on', '>=', $on->toDateString())->first();
            $listed = RatePlan::where('leasable_unit_id', $u->id)->where('duration_unit', 'year')->whereNull('archived_at')->first()?->price_minor;
            $potentialUnit = $listed ?? $lease?->annual_rent_minor;
            if ($potentialUnit) {
                $potential = $potential->plus($potentialUnit);
            }
            if ($lease) {
                $actual = $actual->plus($lease->annual_rent_minor);
            }
        }
        return $potential->isPositive() ? Money::divide($actual->minor() * 10000, $potential->minor(), Rounding::Down) : 0;
    }

    /** Collected ÷ due in the period (REQ-0285). */
    public function collectionRate(array $propertyIds, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $invoices = Invoice::whereIn('property_id', $propertyIds)->where('status', 'issued')->whereBetween('period_start', [$from->toDateString(), $to->toDateString()])->get();
        $due = Money::sum($invoices->map(fn ($i) => $i->total_minor)->all());
        $collected = Money::sum(PaymentAllocation::whereIn('invoice_id', $invoices->pluck('id'))->whereHas('payment', fn ($q) => $q->where('status', 'recorded'))->get()->map(fn ($a) => $a->amount_minor)->all());
        return $due->isPositive() ? Money::divide($collected->minor() * 10000, $due->minor(), Rounding::Down) : 0;
    }

    /** Receivables aging 0–30 / 31–60 / 61–90 / +90 days (REQ-0286). */
    public function aging(array $propertyIds, CarbonImmutable $on): array
    {
        $buckets = ['0_30' => Money::zero(), '31_60' => Money::zero(), '61_90' => Money::zero(), '90_plus' => Money::zero()];
        foreach (Invoice::whereIn('property_id', $propertyIds)->where('status', 'issued')->get() as $inv) {
            $out = $this->invoices->outstanding($inv);
            if (! $out->isPositive()) {
                continue;
            }
            $dueOn = CarbonImmutable::parse($inv->period_start ?? $inv->issue_date);
            if ($dueOn > $on) {
                continue;
            }
            $days = (int) $dueOn->diffInDays($on);
            $key = $days <= 30 ? '0_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : '90_plus'));
            $buckets[$key] = $buckets[$key]->plus($out);
        }
        return $buckets;
    }

    /** Average days between a tenant's exit and the next one's entry (REQ-0287). */
    public function averageVacancyDays($units): ?int
    {
        $gaps = [];
        foreach ($units as $u) {
            $leases = Lease::where('leasable_unit_id', $u->id)->whereNotIn('status', ['draft', 'cancelled'])->where('kind', '<>', 'sublease')->orderBy('starts_on')->get();
            for ($i = 1; $i < $leases->count(); $i++) {
                $gaps[] = max(0, (int) CarbonImmutable::parse($leases[$i - 1]->ends_on)->diffInDays(CarbonImmutable::parse($leases[$i]->starts_on)) - 1);
            }
        }
        return $gaps ? intdiv(array_sum($gaps), count($gaps)) : null;
    }

    /** Renewed ÷ ended contracts in the period (REQ-0288). */
    public function renewalRate($units, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $ended = Lease::whereIn('leasable_unit_id', $units->pluck('id'))->whereBetween('ends_on', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', ['expired', 'renewed', 'settled_closed', 'notice_given'])->get();
        if ($ended->isEmpty()) {
            return 0;
        }
        return intdiv($ended->where('status', 'renewed')->count() * 10000, $ended->count());
    }

    /** Maintenance cost per unit, per m², and as a share of revenue (REQ-0289). */
    public function maintenanceCost(array $propertyIds, $units, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orders = WorkOrder::whereIn('property_id', $propertyIds)->whereBetween('reported_at', [$from->startOfDay(), $to->endOfDay()])->with('costLines')->get();
        $total = Money::sum($orders->flatMap(fn ($w) => $w->costLines->map(fn ($c) => $c->total_minor))->all());
        $area = 0;
        foreach ($units as $u) {
            foreach ($u->spaces as $s) {
                $area += isset($s->attributes['area_sqm']) ? UnitTypes::areaHundredths((string) $s->attributes['area_sqm']) : 0;
            }
        }
        $revenue = $this->revenue($propertyIds, $from, $to);
        return [
            'total' => $total,
            'per_unit' => $units->count() ? $total->multiplyRatio(1, $units->count()) : null,
            'per_sqm' => $area > 0 ? $total->multiplyRatio(100, $area) : null,
            'share_of_revenue_bp' => $revenue->isPositive() ? Money::divide($total->minor() * 10000, $revenue->minor(), Rounding::Down) : null,
        ];
    }

    /** Net operating income = revenue − operating expenses (REQ-0291). */
    public function noi(array $propertyIds, CarbonImmutable $from, CarbonImmutable $to): Money
    {
        $expenses = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('e.property_id', $propertyIds)->whereBetween('e.entry_date', [$from->toDateString(), $to->toDateString()])
            ->where('l.account', 'like', 'expense_on_behalf:%')->selectRaw('coalesce(sum(l.debit_minor) - sum(l.credit_minor), 0) as v')->value('v');
        return $this->revenue($propertyIds, $from, $to)->minus(Money::of((int) $expenses));
    }

    /** Days between exit and entry for replacements who came from the waitlist (REQ-0292). */
    public function avoidedVacancyDays($units): ?int
    {
        $days = [];
        foreach (LeaseOffer::whereIn('leasable_unit_id', $units->pluck('id'))->whereNotNull('source_prospect_id')->where('status', 'accepted')->get() as $offer) {
            $new = Lease::where('lease_offer_id', $offer->id)->first();
            $prev = Lease::where('leasable_unit_id', $offer->leasable_unit_id)->where('id', '<>', $new?->id)->where('ends_on', '<', $new?->starts_on)->orderByDesc('ends_on')->first();
            if ($new && $prev) {
                $days[] = (int) CarbonImmutable::parse($prev->ends_on)->diffInDays(CarbonImmutable::parse($new->starts_on)) - 1;
            }
        }
        return $days ? array_sum($days) : null;
    }

    private function revenue(array $propertyIds, CarbonImmutable $from, CarbonImmutable $to): Money
    {
        $v = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('e.property_id', $propertyIds)->whereBetween('e.entry_date', [$from->toDateString(), $to->toDateString()])
            ->where('l.account', 'like', 'rent_income:%')->selectRaw('coalesce(sum(l.credit_minor) - sum(l.debit_minor), 0) as v')->value('v');
        return Money::of((int) $v);
    }

    private function units(array $propertyIds, ?int $buildingId)
    {
        return LeasableUnit::whereIn('property_id', $propertyIds)->whereNull('archived_at')->where('is_composite', false)
            ->when($buildingId, fn ($q) => $q->whereHas('spaces', fn ($s) => $s->where('building_id', $buildingId)))->with('spaces')->get();
    }

    private function isLeasedOn(LeasableUnit $u, CarbonImmutable $on): bool
    {
        return DB::table('space_occupancies')->whereIn('space_id', $u->spaces->pluck('id'))->where('kind', 'lease')
            ->whereRaw('period @> ?::date', [$on->toDateString()])->exists();
    }
}
