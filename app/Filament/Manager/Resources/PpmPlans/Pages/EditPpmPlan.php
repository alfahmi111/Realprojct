<?php

namespace App\Filament\Manager\Resources\PpmPlans\Pages;

use App\Filament\Manager\Resources\PpmPlans\PpmPlanResource;
use Filament\Resources\Pages\EditRecord;

class EditPpmPlan extends EditRecord
{
    protected static string $resource = PpmPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [\App\Filament\Support\Ui::archive()];
    }
}
