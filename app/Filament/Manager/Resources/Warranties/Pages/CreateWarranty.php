<?php

namespace App\Filament\Manager\Resources\Warranties\Pages;

use App\Filament\Manager\Resources\Warranties\WarrantyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWarranty extends CreateRecord
{
    protected static string $resource = WarrantyResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
