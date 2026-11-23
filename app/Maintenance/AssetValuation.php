<?php

namespace App\Maintenance;

use App\Models\Asset;
use App\Models\InventoryCount;
use App\Models\Space;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a building actually contains and what it is worth (owner decision 2026-11-23 on top of REQ-0211 cost + useful
 * life and REQ-0262: inventory completion, asset condition, replacement budget for the owner).
 *
 * Straight-line depreciation, integers in halalas only (ر-02):
 *   yearly amount = fixed amount, or cost × rate, or (cost − salvage) ÷ useful life;
 *   depreciated   = min(cost − salvage, yearly × days in service ÷ 365);
 *   book value    = cost − depreciated;  end of service = installed + (cost − salvage) ÷ yearly.
 * An asset without cost, install date or any depreciation basis is reported as "incomplete" rather than guessed (ر-01).
 */
class AssetValuation
{
    /** Remaining life under this many months counts as "nearing end of service". */
    public const NEAR_END_MONTHS = 12;

    /** Years shown in the replacement budget. */
    public const BUDGET_YEARS = 5;

    /** @return array{yearly:?int,depreciated:?int,book:?int,pct_bp:?int,life_months:?int,remaining_months:?int,ends_on:?CarbonImmutable,state:string} */
    public function value(Asset $a, CarbonImmutable $on): array
    {
        $cost = $a->cost_minor?->minor();
        $salvage = min((int) ($a->salvage_minor ?? 0), (int) $cost);
        $yearly = $this->yearly($a, $cost, $salvage);
        $empty = ['yearly' => $yearly, 'depreciated' => null, 'book' => null, 'pct_bp' => null, 'life_months' => null, 'remaining_months' => null, 'ends_on' => null, 'state' => 'incomplete'];
        if (! $cost || ! $yearly || ! $a->installed_on) {
            return $empty;
        }
        $base = $cost - $salvage;
        $installed = CarbonImmutable::parse($a->installed_on);
        $days = max(0, (int) $installed->diffInDays($on, false));
        $depreciated = min($base, intdiv($yearly * $days, 365));
        $lifeMonths = max(1, intdiv($base * 12 + $yearly - 1, $yearly));          // rounded up
        $endsOn = $installed->addMonths($lifeMonths);
        $remaining = (int) floor($on->diffInMonths($endsOn, false));
        $state = $remaining <= 0 ? 'ended' : ($remaining <= self::NEAR_END_MONTHS ? 'near' : 'ok');
        return [
            'yearly' => $yearly, 'depreciated' => $depreciated, 'book' => $cost - $depreciated,
            'pct_bp' => $base > 0 ? intdiv($depreciated * 10000, $base) : 10000,
            'life_months' => $lifeMonths, 'remaining_months' => max(0, $remaining), 'ends_on' => $endsOn, 'state' => $state,
        ];
    }

    /** Yearly depreciation in halalas, or null when the asset gives no basis for it. */
    public function yearly(Asset $a, ?int $cost, int $salvage = 0): ?int
    {
        return match (true) {
            $a->depreciation_method === 'amount' && $a->depreciation_annual_minor > 0 => (int) $a->depreciation_annual_minor,
            $a->depreciation_method === 'percent' && $a->depreciation_rate_bp > 0 && $cost > 0 => max(1, intdiv($cost * (int) $a->depreciation_rate_bp, 10000)),
            $a->useful_life_months > 0 && $cost > $salvage => max(1, intdiv(($cost - $salvage) * 12, (int) $a->useful_life_months)),
            default => null,
        };
    }

    /** Space ids of a building (the building node and everything under it). */
    public function buildingSpaceIds(int $buildingId): array
    {
        return Space::where('building_id', $buildingId)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** Spend on maintenance (every cost line of every work order) per asset or for a set of spaces, from a date. */
    public function maintenanceSpend(array $spaceIds, int $propertyId, ?CarbonImmutable $from = null): int
    {
        $q = DB::table('cost_lines')->join('work_orders', 'work_orders.id', '=', 'cost_lines.work_order_id')
            ->where(fn ($w) => $w->whereIn('work_orders.space_id', $spaceIds ?: [0])->orWhere(fn ($x) => $x->whereNull('work_orders.space_id')->where('work_orders.property_id', $propertyId)));
        if ($from) {
            $q->where('cost_lines.created_at', '>=', $from->startOfDay());
        }
        return (int) $q->sum('cost_lines.total_minor');
    }

    /** @return array<int,int> asset id => total maintenance spend */
    public function spendByAsset(array $assetIds): array
    {
        return DB::table('cost_lines')->join('work_orders', 'work_orders.id', '=', 'cost_lines.work_order_id')
            ->whereIn('work_orders.asset_id', $assetIds ?: [0])->groupBy('work_orders.asset_id')
            ->selectRaw('work_orders.asset_id as id, sum(cost_lines.total_minor) as total')->pluck('total', 'id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Everything the building contains: totals, by category, replacement budget by year, assets nearing end of service,
     * founding-inventory completion, and each asset with its valuation.
     */
    public function building(int $buildingId, int $propertyId, CarbonImmutable $on): array
    {
        $spaceIds = $this->buildingSpaceIds($buildingId);
        $spaces = Space::whereIn('id', $spaceIds ?: [0])->get()->keyBy('id');
        /** @var Collection<int,Asset> $assets */
        $assets = Asset::whereIn('space_id', $spaceIds ?: [0])->whereNull('archived_at')->orderBy('category')->orderBy('name_ar')->get();
        $spend = $this->spendByAsset($assets->pluck('id')->all());

        $rows = [];
        $totals = ['count' => 0, 'cost' => 0, 'book' => 0, 'depreciated' => 0, 'yearly' => 0, 'incomplete' => 0, 'near' => 0, 'ended' => 0, 'base' => 0, 'condition_sum' => 0, 'condition_n' => 0];
        $byCategory = [];
        $budget = array_fill_keys(range($on->year, $on->year + self::BUDGET_YEARS - 1), 0);
        foreach ($assets as $a) {
            $v = $this->value($a, $on);
            $cost = (int) $a->cost_minor?->minor();
            $space = $spaces[$a->space_id] ?? null;
            $rows[] = ['asset' => $a, 'v' => $v, 'cost' => $cost, 'spend' => $spend[$a->id] ?? 0, 'where' => $space ? $space->name_ar : '', 'where_code' => $space?->code];
            $totals['count']++;
            $totals['cost'] += $cost;
            if ($a->condition) {
                $totals['condition_sum'] += (int) $a->condition;
                $totals['condition_n']++;
            }
            $cat = $a->category;
            $byCategory[$cat] ??= ['label' => AssetTaxonomy::CATEGORIES[$cat]['ar'] ?? $cat, 'count' => 0, 'cost' => 0, 'book' => 0];
            $byCategory[$cat]['count']++;
            $byCategory[$cat]['cost'] += $cost;
            if ($v['state'] === 'incomplete') {
                $totals['incomplete']++;
                $totals['book'] += $cost;
                $byCategory[$cat]['book'] += $cost;
                continue;
            }
            $totals['book'] += $v['book'];
            $totals['depreciated'] += $v['depreciated'];
            $totals['base'] += $cost - (int) ($a->salvage_minor ?? 0);
            $byCategory[$cat]['book'] += $v['book'];
            if ($v['state'] !== 'ended') {
                $totals['yearly'] += $v['yearly'];
            }
            if ($v['state'] !== 'ok') {
                $totals[$v['state']]++;
            }
            // replacement at today's cost in the year the asset leaves service; already ended → this year
            $year = max($on->year, $v['ends_on']->year);
            if (isset($budget[$year])) {
                $budget[$year] += $cost;
            }
        }
        uasort($byCategory, fn ($x, $y) => $y['cost'] <=> $x['cost']);
        $near = collect($rows)->filter(fn ($r) => in_array($r['v']['state'], ['near', 'ended'], true))->sortBy(fn ($r) => $r['v']['remaining_months'])->values()->all();

        return [
            'totals' => $totals + [
                'pct_bp' => $totals['base'] > 0 ? intdiv($totals['depreciated'] * 10000, $totals['base']) : 0,
                'condition_avg10' => $totals['condition_n'] ? intdiv($totals['condition_sum'] * 10, $totals['condition_n']) : null,
                'spend_year' => $this->maintenanceSpend($spaceIds, $propertyId, $on->startOfYear()),
                'spend_all' => $this->maintenanceSpend($spaceIds, $propertyId),
            ],
            'categories' => array_values($byCategory),
            'budget' => $budget,
            'near' => $near,
            'rows' => $rows,
            'inventory' => $this->inventoryCompletion($spaceIds),
        ];
    }

    /** Founding inventory completion: units with a signed founding count ÷ units (REQ-0262, REQ-0294). */
    public function inventoryCompletion(array $spaceIds): array
    {
        $units = Space::whereIn('id', $spaceIds ?: [0])->where('kind', 'unit')->whereNull('archived_at')->pluck('id');
        $done = InventoryCount::whereIn('space_id', $units)->where('type', 'founding')->where('status', 'submitted')->distinct()->count('space_id');
        return ['units' => $units->count(), 'done' => $done, 'bp' => $units->count() ? intdiv($done * 10000, $units->count()) : 0];
    }
}
