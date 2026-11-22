<?php

namespace App\Filament\Manager\Resources\WorkOrders\Pages;

use App\Filament\Manager\Resources\WorkOrders\WorkOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListWorkOrders extends ListRecords
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
