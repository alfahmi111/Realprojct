<?php

namespace App\Filament\Support;

use App\Dashboards\Kpis;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

/**
 * KPI board for the portfolio (manager) or the owner's own properties (REQ-0281, REQ-0304). Redesigned 2026-11-23 in
 * the identity of the rest of the system: a period switch, the four rates with what each means, money for the period,
 * receivables by age as one bar, and each property side by side.
 */
abstract class KpiPage extends Page
{
    protected string $view = 'filament.pages.kpis';

    protected static ?string $title = 'مؤشرات الأداء';

    protected static ?string $navigationLabel = 'مؤشرات الأداء';

    public string $period = 'month';

    abstract protected function propertyIds(): array;

    public function getHeading(): string
    {
        return '';
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable,2:string} */
    public function range(): array
    {
        $to = CarbonImmutable::today();
        return match ($this->period) {
            'quarter' => [$to->startOfQuarter(), $to, 'هذا الربع'],
            'year' => [$to->startOfYear(), $to, 'منذ بداية السنة'],
            default => [$to->startOfMonth(), $to, 'هذا الشهر'],
        };
    }

    protected function getViewData(): array
    {
        [$from, $to, $label] = $this->range();
        $kpis = app(Kpis::class);
        $ids = $this->propertyIds();
        $perProperty = Property::whereIn('id', $ids ?: [0])->orderBy('name_ar')->get()
            ->map(fn ($p) => ['name' => $p->name_ar] + $kpis->all([$p->id], $from, $to))->all();
        return ['kpis' => $kpis->all($ids, $from, $to), 'perProperty' => count($perProperty) > 1 ? $perProperty : [], 'label' => $label];
    }
}
