<?php

namespace App\Filament\Manager\Resources\Leases\Pages;

use App\Dashboards\HomeOverview;
use App\Dashboards\PortfolioTree;
use App\Filament\Manager\Resources\Leases\LeaseResource;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\ListRecords;

/**
 * Leases as a tree (owner request 2026-11-23): property → unit → current tenant and lease (period, days left, Ejar
 * number, rent) with the unit's earlier leases folded under it, and a quick search. The full list with the lease
 * life-cycle actions (registration, notice, renewal) is the second view.
 */
class ListLeases extends ListRecords
{
    protected static string $resource = LeaseResource::class;

    protected string $view = 'filament.manager.leases-tree';

    public string $q = '';

    public string $mode = 'tree';

    public bool $onlyLeased = false;

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
        $tree = app(PortfolioTree::class)->build($home->propertyIds(auth()->user()), CarbonImmutable::today(), $this->q);
        if (! $this->onlyLeased) {
            return $tree;
        }
        foreach ($tree as &$p) {
            foreach ($p['buildings'] as &$b) {
                foreach ($b['floors'] as &$f) {
                    $f['units'] = array_values(array_filter($f['units'], fn ($u) => $u['lease'] !== null));
                }
                $b['floors'] = array_values(array_filter($b['floors'], fn ($f) => $f['units']));
            }
        }
        return $tree;
    }
}
