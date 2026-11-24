<?php

namespace App\Filament\Manager\Resources\Vendors\Pages;

use App\Filament\Manager\Resources\Vendors\VendorResource;
use Filament\Resources\Pages\EditRecord;

class EditVendor extends EditRecord
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [\App\Filament\Support\Ui::archive('maintenance.edit')];
    }
}
