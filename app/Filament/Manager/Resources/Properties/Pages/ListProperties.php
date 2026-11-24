<?php

namespace App\Filament\Manager\Resources\Properties\Pages;

use App\Dashboards\HomeOverview;
use App\Dashboards\PortfolioTree;
use App\Filament\Manager\Resources\Properties\PropertyResource;
use App\Models\Property;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\ListRecords;

/**
 * Properties as cards (owner request 2026-11-23): photo, city, owners, units, occupancy bar in the status colours,
 * rent roll and open work orders, with links to the property, its units and its contents. The table stays as the
 * second view for archive and edit.
 */
class ListProperties extends ListRecords
{
    protected static string $resource = PropertyResource::class;

    protected string $view = 'filament.manager.properties-cards';

    public string $mode = 'cards';

    public string $q = '';

    /** name | occupancy | vacant | roll | open_wo | units (owner request 2026-11-24) */
    public string $sort = 'name';

    /** none | city */
    public string $group = 'none';

    public const SORTS = ['name' => 'الاسم', 'occupancy' => 'الأقل إشغالاً', 'vacant' => 'الأكثر شغوراً', 'roll' => 'الأعلى إيجاراً', 'open_wo' => 'الأكثر أوامر عمل', 'units' => 'الأكثر وحدات'];

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getHeading(): string
    {
        return '';
    }

    public function cards(): array
    {
        $ids = app(HomeOverview::class)->propertyIds(auth()->user());
        $tree = collect(app(PortfolioTree::class)->build($ids, \App\Support\Dates\Today::date()))->keyBy('id');
        $props = Property::whereIn('id', $ids ?: [0])->whereNull('archived_at')->with(['shares.owner', 'buildings'])
            ->when(trim($this->q) !== '', fn ($q) => $q->where(fn ($w) => $w->where('name_ar', 'ilike', '%' . trim($this->q) . '%')->orWhere('city', 'ilike', '%' . trim($this->q) . '%')->orWhere('deed_number', 'ilike', '%' . trim($this->q) . '%')))
            ->orderBy('name_ar')->get();
        $wo = WorkOrder::whereIn('property_id', $props->pluck('id'))->whereNotIn('status', ['closed', 'rejected'])->selectRaw('property_id, count(*) n')->groupBy('property_id')->pluck('n', 'property_id');
        return $props->map(function (Property $p) use ($tree, $wo) {
            $t = $tree[$p->id] ?? null;
            $roll = 0;
            foreach ($t['buildings'] ?? [] as $b) {
                foreach ($b['floors'] as $f) {
                    foreach ($f['units'] as $u) {
                        $roll += $u['rent'] instanceof \App\Support\Money\Money ? $u['rent']->minor() : (int) $u['rent'];
                    }
                }
            }
            return ['id' => $p->id, 'name' => $p->name_ar, 'city' => $p->city, 'lat' => $p->latitude !== null ? (float) $p->latitude : null, 'lng' => $p->longitude !== null ? (float) $p->longitude : null, 'photo' => $p->photoUrl(), 'deed' => $p->deed_number,
                'owners' => $p->shares->map(fn ($s) => $s->owner->name_ar)->unique()->values()->all(), 'buildings' => $p->buildings->count(),
                'counts' => $t['counts'] ?? ['total' => 0, 'leased' => 0, 'vacant' => 0, 'paid' => 0, 'late' => 0, 'maint' => 0, 'soon' => 0, 'occupancy_bp' => 0],
                'roll' => $roll, 'open_wo' => (int) ($wo[$p->id] ?? 0)];
        })->sortBy(fn ($c) => match ($this->sort) {
            'occupancy' => $c['counts']['occupancy_bp'],
            'vacant' => -$c['counts']['vacant'],
            'roll' => -$c['roll'],
            'open_wo' => -$c['open_wo'],
            'units' => -$c['counts']['total'],
            default => $c['name'],
        })->values()->all();
    }

    /** Cards grouped by city when asked: [city => cards]. */
    public function grouped(array $cards): array
    {
        if ($this->group !== 'city') {
            return ['' => $cards];
        }
        $g = [];
        foreach ($cards as $c) {
            $g[trim((string) $c['city']) ?: 'بلا مدينة'][] = $c;
        }
        uksort($g, fn ($a, $b) => ($a === 'بلا مدينة') <=> ($b === 'بلا مدينة') ?: strcmp($a, $b));
        return $g;
    }
}
