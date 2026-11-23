<?php

namespace App\Filament\Manager\Resources\Vendors\Pages;

use App\Filament\Manager\Resources\Vendors\VendorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVendor extends CreateRecord
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
