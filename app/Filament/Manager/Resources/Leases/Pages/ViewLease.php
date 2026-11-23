<?php

namespace App\Filament\Manager\Resources\Leases\Pages;

use App\Filament\Manager\Resources\Leases\LeaseResource;
use Filament\Resources\Pages\ViewRecord;

class ViewLease extends ViewRecord
{
    protected static string $resource = LeaseResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
