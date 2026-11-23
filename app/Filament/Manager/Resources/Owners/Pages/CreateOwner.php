<?php

namespace App\Filament\Manager\Resources\Owners\Pages;

use App\Filament\Manager\Resources\Owners\OwnerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOwner extends CreateRecord
{
    protected static string $resource = OwnerResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
