<?php

namespace App\Filament\Platform\Pages;

use App\Plans\PlatformOverview;
use Carbon\CarbonImmutable;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;

/** Platform admin home (replaces Filament's empty dashboard): states, attention list, plans, revenue, health. */
class PlatformHome extends Dashboard
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $navigationLabel = 'لوحة المنصة';

    protected static ?string $title = 'لوحة المنصة';

    protected string $view = 'filament.platform.home';

    protected function getViewData(): array
    {
        return ['o' => app(PlatformOverview::class)->build(CarbonImmutable::today())];
    }
}
