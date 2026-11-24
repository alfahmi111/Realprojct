<?php

namespace App\Filament\Manager\Resources\Properties\Pages;

use App\Dashboards\Kpis;
use App\Dashboards\PortfolioTree;
use App\Filament\Manager\Resources\Properties\PropertyResource;
use App\Maintenance\AssetValuation;
use App\Models\Building;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\ViewRecord;

/**
 * One property on one page (redesign 2026-11-23): photo and identity, the facade of its units in the five status
 * colours (REQ-0330), owners and shares, this month's figures, open work orders, and the value of its contents.
 */
class ViewProperty extends ViewRecord
{
    protected static string $resource = PropertyResource::class;

    protected string $view = 'filament.manager.property-view';

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getHeading(): string
    {
        return '';
    }

    public function data(): array
    {
        $p = $this->record->load(['shares.owner', 'portfolio']);
        $today = \App\Support\Dates\Today::date();
        $tree = app(PortfolioTree::class)->build([$p->id], $today)[0] ?? null;
        $building = Building::where('property_id', $p->id)->whereNull('archived_at')->orderBy('id')->first();
        return [
            'p' => $p,
            'tree' => $tree,
            'kpis' => app(Kpis::class)->all([$p->id], $today->startOfMonth(), $today),
            'assets' => $building ? app(AssetValuation::class)->building($building->id, $p->id, $today) : null,
            'wo' => WorkOrder::where('property_id', $p->id)->whereNotIn('status', ['closed', 'rejected'])->with('space')->latest('reported_at')->limit(5)->get(),
        ];
    }
}
