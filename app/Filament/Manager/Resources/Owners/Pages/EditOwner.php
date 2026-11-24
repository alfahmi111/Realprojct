<?php

namespace App\Filament\Manager\Resources\Owners\Pages;

use App\Filament\Manager\Resources\Owners\OwnerResource;
use Filament\Resources\Pages\EditRecord;

class EditOwner extends EditRecord
{
    protected static string $resource = OwnerResource::class;

    protected function getHeaderActions(): array
    {
        return [\App\Filament\Support\Ui::archive('properties.edit')];
    }
}
