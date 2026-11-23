<?php

namespace App\Filament\Manager\Resources\BuildingQrs\Pages;

use App\Filament\Manager\Resources\BuildingQrs\BuildingQrResource;
use Filament\Resources\Pages\ListRecords;

class ListBuildingQrs extends ListRecords
{
    protected static string $resource = BuildingQrResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
