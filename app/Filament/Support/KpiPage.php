<?php

namespace App\Filament\Support;

use App\Dashboards\Kpis;
use App\Models\OwnershipShare;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

/** KPI board for the portfolio (manager) or the owner's own properties (REQ-0281, REQ-0304). */
abstract class KpiPage extends Page
{
    protected string $view = 'filament.pages.kpis';

    protected static ?string $title = 'مؤشرات الأداء';

    protected static ?string $navigationLabel = 'مؤشرات الأداء';

    abstract protected function propertyIds(): array;

    protected function getViewData(): array
    {
        $to = CarbonImmutable::today();
        return ['kpis' => app(Kpis::class)->all($this->propertyIds(), $to->startOfMonth(), $to)];
    }
}
