<?php

namespace App\Dashboards;

use App\Finance\InvoiceService;
use App\Models\Building;
use App\Models\Invoice;
use App\Models\LeasableUnit;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Space;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The portfolio as a tree (owner request 2026-11-23): property → building → floor → leasable unit, each unit with its
 * status today in the five fixed colours (REQ-0330), its current lease and tenant, and the number of past leases.
 * Used by the units page and the leases page; scoped by the caller's property ids (REQ-0494) on top of RLS.
 */
class PortfolioTree
{
    public const LIVE = ['registered', 'active', 'notice_given', 'draft', 'sent_for_registration', 'awaiting_parties'];

    /** @var array<int,int> leasable unit id => asking yearly price in halalas */
    private array $asking = [];

    public function __construct(private InvoiceService $invoices)
    {
    }

    /**
     * @return list<array{id:int,name:string,city:?string,photo:?string,counts:array,buildings:list<array>}>
     */
    public function build(array $propertyIds, CarbonImmutable $today, string $search = ''): array
    {
        $search = trim($search);
        $properties = Property::whereIn('id', $propertyIds ?: [0])->whereNull('archived_at')->orderBy('name_ar')->get();
        $units = LeasableUnit::whereIn('property_id', $propertyIds ?: [0])->whereNull('archived_at')->with('spaces')->get();
        $unitBySpace = [];
        foreach ($units as $u) {
            foreach ($u->spaces as $s) {
                $unitBySpace[$s->id][] = $u;   // every unit containing the space, single and composite (review M18)
            }
        }
        $leases = Lease::whereIn('leasable_unit_id', $units->pluck('id'))->with('tenant')->orderByDesc('starts_on')->get()->groupBy('leasable_unit_id');
        $overdue = Invoice::whereIn('lease_id', $leases->flatten()->pluck('id'))->where('status', 'issued')->where('period_start', '<=', $today->toDateString())->get()
            ->filter(fn ($i) => $this->invoices->outstanding($i)->isPositive())->pluck('lease_id')->flip();
        // asking price per year from the rate plans (monthly × 12 when only a monthly price exists)
        $asking = DB::table('rate_plans')->whereIn('leasable_unit_id', $units->pluck('id'))->whereNull('archived_at')->whereIn('duration_unit', ['year', 'month'])->get()
            ->groupBy('leasable_unit_id')->map(fn ($rows) => ($y = $rows->firstWhere('duration_unit', 'year')) ? (int) $y->price_minor : (int) $rows->first()->price_minor * 12);
        $this->asking = $asking->all();
        $maint = DB::table('space_occupancies')->where('kind', 'maintenance')->whereRaw('period @> ?::date', [$today->toDateString()])->pluck('space_id')->flip();
        $spaces = Space::whereIn('property_id', $properties->pluck('id'))->whereNull('archived_at')->orderBy('sort')->orderBy('code')->get();
        $buildings = Building::whereIn('property_id', $properties->pluck('id'))->whereNull('archived_at')->orderBy('id')->get()->groupBy('property_id');

        $out = [];
        foreach ($properties as $p) {
            $pCounts = ['paid' => 0, 'late' => 0, 'vacant' => 0, 'maint' => 0, 'soon' => 0, 'total' => 0];
            $bOut = [];
            foreach ($buildings[$p->id] ?? [] as $b) {
                $floors = $spaces->where('building_id', $b->id)->where('kind', 'floor')->sortByDesc(fn ($f) => (int) preg_replace('/\D/', '', (string) $f->code));
                $fOut = [];
                foreach ($floors as $f) {
                    $rows = [];
                    foreach ($spaces->where('parent_id', $f->id)->where('kind', 'unit') as $s) {
                        // the unit that holds a live lease today wins (a whole-building lease shows on each flat); else the single unit
                        $candidates = $unitBySpace[$s->id] ?? [];
                        $u = collect($candidates)->first(fn ($c) => ($leases[$c->id] ?? collect())->contains(fn ($l) => in_array($l->status, self::LIVE, true) && $l->starts_on <= $today && $l->ends_on >= $today))
                            ?? collect($candidates)->first(fn ($c) => ! $c->is_composite) ?? ($candidates[0] ?? null);
                        $row = $this->unitRow($s, $u, $u ? ($leases[$u->id] ?? collect()) : collect(), $today, $overdue, $maint);
                        if ($search !== '' && ! $this->matches($row, $search)) {
                            continue;
                        }
                        $rows[] = $row;
                        $pCounts[$row['status']]++;
                        $pCounts['total']++;
                    }
                    if ($rows) {
                        $fOut[] = ['name' => $f->name_ar, 'units' => $rows];
                    }
                }
                if ($fOut) {
                    $bOut[] = ['id' => $b->id, 'name' => $b->name_ar, 'floors' => $fOut];
                }
            }
            if ($bOut || ($search === '' && $buildings->has($p->id))) {
                $leased = $pCounts['paid'] + $pCounts['late'] + $pCounts['soon'];
                $out[] = ['id' => $p->id, 'name' => $p->name_ar, 'city' => $p->city, 'photo' => $p->photoUrl(), 'buildings' => $bOut,
                    'counts' => $pCounts + ['leased' => $leased, 'occupancy_bp' => $pCounts['total'] ? intdiv($leased * 10000, $pCounts['total']) : 0]];
            }
        }
        return $out;
    }

    private function unitRow(Space $s, ?LeasableUnit $u, $unitLeases, CarbonImmutable $today, $overdue, $maint): array
    {
        $current = $unitLeases->first(fn (Lease $l) => in_array($l->status, self::LIVE, true) && $l->starts_on <= $today && $l->ends_on >= $today)
            ?? $unitLeases->first(fn (Lease $l) => in_array($l->status, self::LIVE, true) && $l->starts_on > $today);
        $status = 'vacant';
        if (isset($maint[$s->id])) {
            $status = 'maint';
        } elseif ($current && $current->starts_on <= $today) {
            $status = $current->status === 'notice_given' ? 'soon' : (isset($overdue[$current->id]) ? 'late' : 'paid');
        }
        $ends = $current ? CarbonImmutable::parse($current->ends_on) : null;
        return [
            'space_id' => $s->id, 'unit_id' => $u?->id, 'code' => $u?->code ?? $s->code, 'name' => $u?->name_ar ?? $s->name_ar,
            'type' => $u?->unit_type ?? $s->unit_type, 'composite' => (bool) $u?->is_composite, 'status' => $status,
            'rent' => $current?->annual_rent_minor,
            'asking' => $u ? ($this->asking[$u->id] ?? null) : null,
            'lease' => $current ? [
                'id' => $current->id, 'status' => $current->status, 'tenant' => $current->tenant?->name_ar, 'tenant_id' => $current->tenant_id,
                'phone' => $current->tenant?->phone, 'ejar' => $current->ejar_contract_number,
                'starts_on' => CarbonImmutable::parse($current->starts_on), 'ends_on' => $ends,
                'days_left' => $ends ? (int) $today->diffInDays($ends, false) : null,
                'elapsed_pct' => $ends ? max(0, min(100, intdiv(max(0, (int) CarbonImmutable::parse($current->starts_on)->diffInDays($today, false)) * 100, max(1, (int) CarbonImmutable::parse($current->starts_on)->diffInDays($ends))))) : 0,
                'future' => CarbonImmutable::parse($current->starts_on) > $today,
            ] : null,
            'history' => $unitLeases->reject(fn ($l) => $current && $l->id === $current->id)->map(fn (Lease $l) => [
                'id' => $l->id, 'tenant' => $l->tenant?->name_ar, 'status' => $l->status, 'starts_on' => CarbonImmutable::parse($l->starts_on), 'ends_on' => CarbonImmutable::parse($l->ends_on),
            ])->values()->all(),
        ];
    }

    private function matches(array $row, string $q): bool
    {
        $hay = [$row['code'], $row['name'], $row['lease']['tenant'] ?? '', $row['lease']['phone'] ?? '', $row['lease']['ejar'] ?? ''];
        foreach ($row['history'] as $h) {
            $hay[] = $h['tenant'];
        }
        foreach ($hay as $h) {
            if ($h !== null && $h !== '' && mb_stripos((string) $h, $q) !== false) {
                return true;
            }
        }
        return false;
    }
}
