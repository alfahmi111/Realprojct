<?php

namespace App\Filament\Manager\Resources\WorkOrders\Pages;

use App\Filament\Manager\Resources\WorkOrders\WorkOrderResource;
use App\Models\WorkOrder;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

/** Work orders by stage (redesign 2026-11-23): tabs with counts, the open ones first. */
class ListWorkOrders extends ListRecords
{
    protected static string $resource = WorkOrderResource::class;

    public function getTabs(): array
    {
        $counts = WorkOrder::selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');
        $tabs = ['open' => Tab::make('المفتوحة')->badge($counts->except(['closed', 'rejected'])->sum() ?: null)
            ->modifyQueryUsing(fn ($query) => $query->whereNotIn('status', ['closed', 'rejected']))];
        foreach (WorkOrderResource::STAGES as $key => $stage) {
            $n = $counts->only($stage['statuses'])->sum();
            $tabs[$key] = Tab::make($stage['label'])->badge($n ?: null)->badgeColor($key === 'triage' ? 'danger' : ($key === 'owner' ? 'warning' : 'gray'))
                ->modifyQueryUsing(fn ($query) => $query->whereIn('status', $stage['statuses']));
        }
        return $tabs + ['all' => Tab::make('الكل')];
    }
}
