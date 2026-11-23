<?php

namespace App\Filament\Manager\Resources\Assets\Pages;

use App\Filament\Manager\Resources\Assets\AssetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAsset extends CreateRecord
{
    protected static string $resource = AssetResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return AssetResource::prepareDepreciation($data, $this->data ?? []);
    }
}
