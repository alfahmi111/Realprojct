<?php

namespace App\Filament\Manager\Resources\Warranties\Pages;

use App\Filament\Manager\Resources\Warranties\WarrantyResource;
use Filament\Resources\Pages\EditRecord;

class EditWarranty extends EditRecord
{
    protected static string $resource = WarrantyResource::class;

    protected function getHeaderActions(): array
    {
        return [\App\Filament\Support\Ui::archive('maintenance.edit')];
    }
}
