<?php

namespace App\Filament\Manager\Resources\Warranties\Pages;

use App\Filament\Manager\Resources\Warranties\WarrantyResource;
use Filament\Resources\Pages\ListRecords;

class ListWarranties extends ListRecords
{
    protected static string $resource = WarrantyResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
