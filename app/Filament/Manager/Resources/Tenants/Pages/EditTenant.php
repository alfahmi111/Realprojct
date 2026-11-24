<?php

namespace App\Filament\Manager\Resources\Tenants\Pages;

use App\Filament\Manager\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\EditRecord;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [\App\Filament\Support\Ui::archive('leasing.edit')];
    }
}
