<?php

namespace App\Filament\Manager\Resources\UnitTypeAssetTemplates\Pages;

use App\Filament\Manager\Resources\UnitTypeAssetTemplates\UnitTypeAssetTemplateResource;
use Filament\Resources\Pages\ListRecords;

class ListUnitTypeAssetTemplates extends ListRecords
{
    protected static string $resource = UnitTypeAssetTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
