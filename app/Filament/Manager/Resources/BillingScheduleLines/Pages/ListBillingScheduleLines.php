<?php

namespace App\Filament\Manager\Resources\BillingScheduleLines\Pages;

use App\Filament\Manager\Resources\BillingScheduleLines\BillingScheduleLineResource;
use Filament\Resources\Pages\ListRecords;

class ListBillingScheduleLines extends ListRecords
{
    protected static string $resource = BillingScheduleLineResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
