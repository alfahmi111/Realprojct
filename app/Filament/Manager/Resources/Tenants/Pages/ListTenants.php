<?php

namespace App\Filament\Manager\Resources\Tenants\Pages;

use App\Filament\Manager\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\ListRecords;

/** Tenants with what the office knows about each (unit, lease end, balance, contact) and a summary strip above. */
class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;

    protected string $view = 'filament.manager.tenants';

    public function getHeading(): string
    {
        return '';
    }

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
