<?php

namespace App\Filament\Manager\Resources\Properties\Pages;

use App\Filament\Manager\Resources\Properties\PropertyResource;
use Filament\Resources\Pages\EditRecord;

class EditProperty extends EditRecord
{
    protected static string $resource = PropertyResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
