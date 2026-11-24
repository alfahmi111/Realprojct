<?php

namespace App\Filament\Manager\Resources\LeasableUnits\Pages;

use App\Dashboards\HomeOverview;
use App\Dashboards\PortfolioTree;
use App\Filament\Manager\Resources\LeasableUnits\LeasableUnitResource;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\ListRecords;

/**
 * Units as a tree (owner request 2026-11-23): property → building → floor → unit with status, tenant and rent; quick
 * search by unit, tenant, phone or Ejar number. The management table (price, attributes, change type, merge, split)
 * follows under it.
 */
class ListLeasableUnits extends ListRecords
{
    protected static string $resource = LeasableUnitResource::class;

    protected string $view = 'filament.manager.units-tree';

    #[\Livewire\Attributes\Url]
    public string $q = '';   // ?q=101 from the home facade opens the tree on that unit (review M18)

    public string $mode = 'tree';

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getHeading(): string
    {
        return '';
    }

    public function tree(): array
    {
        $home = app(HomeOverview::class);
        return app(PortfolioTree::class)->build($home->propertyIds(auth()->user()), CarbonImmutable::today(), $this->q);
    }
}
