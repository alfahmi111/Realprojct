<?php

namespace App\Filament\Manager\Resources\Assets\Pages;

use App\Filament\Manager\Resources\Assets\AssetResource;
use Filament\Resources\Pages\EditRecord;

class EditAsset extends EditRecord
{
    protected static string $resource = AssetResource::class;

    protected function getHeaderActions(): array
    {
        return [\App\Filament\Support\Ui::archive('assets.edit')];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return AssetResource::prepareDepreciation($data, $this->data ?? []);
    }
}
