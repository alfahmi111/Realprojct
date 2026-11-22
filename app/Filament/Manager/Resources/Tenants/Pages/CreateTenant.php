<?php

namespace App\Filament\Manager\Resources\Tenants\Pages;

use App\Filament\Manager\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
