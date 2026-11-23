<?php

namespace App\Filament\Manager\Resources\UnitTypeAssetTemplates\Pages;

use App\Filament\Manager\Resources\UnitTypeAssetTemplates\UnitTypeAssetTemplateResource;
use Filament\Resources\Pages\EditRecord;

class EditUnitTypeAssetTemplate extends EditRecord
{
    protected static string $resource = UnitTypeAssetTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
