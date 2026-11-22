<?php

namespace App\Filament\Manager\Resources\LeasableUnits\Pages;

use App\Filament\Manager\Resources\LeasableUnits\LeasableUnitResource;
use Filament\Resources\Pages\ListRecords;

class ListLeasableUnits extends ListRecords
{
    protected static string $resource = LeasableUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
