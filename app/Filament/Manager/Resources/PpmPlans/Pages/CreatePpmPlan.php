<?php

namespace App\Filament\Manager\Resources\PpmPlans\Pages;

use App\Filament\Manager\Resources\PpmPlans\PpmPlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePpmPlan extends CreateRecord
{
    protected static string $resource = PpmPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
