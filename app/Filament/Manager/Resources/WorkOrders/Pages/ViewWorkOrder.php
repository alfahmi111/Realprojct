<?php

namespace App\Filament\Manager\Resources\WorkOrders\Pages;

use App\Filament\Manager\Resources\WorkOrders\WorkOrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewWorkOrder extends ViewRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
