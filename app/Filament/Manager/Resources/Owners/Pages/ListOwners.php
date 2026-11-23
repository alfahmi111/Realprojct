<?php

namespace App\Filament\Manager\Resources\Owners\Pages;

use App\Filament\Manager\Resources\Owners\OwnerResource;
use Filament\Resources\Pages\ListRecords;

class ListOwners extends ListRecords
{
    protected static string $resource = OwnerResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
