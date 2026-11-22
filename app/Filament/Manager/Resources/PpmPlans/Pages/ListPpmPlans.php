<?php

namespace App\Filament\Manager\Resources\PpmPlans\Pages;

use App\Filament\Manager\Resources\PpmPlans\PpmPlanResource;
use Filament\Resources\Pages\ListRecords;

class ListPpmPlans extends ListRecords
{
    protected static string $resource = PpmPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
