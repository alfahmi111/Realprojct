<?php

namespace App\Filament\Manager\Resources\Leases\Pages;

use App\Filament\Manager\Resources\Leases\LeaseResource;
use Filament\Resources\Pages\ListRecords;

class ListLeases extends ListRecords
{
    protected static string $resource = LeaseResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
