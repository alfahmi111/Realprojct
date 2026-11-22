<?php

namespace App\Filament\Manager\Resources\OfferApprovalRules\Pages;

use App\Filament\Manager\Resources\OfferApprovalRules\OfferApprovalRuleResource;
use Filament\Resources\Pages\ListRecords;

class ListOfferApprovalRules extends ListRecords
{
    protected static string $resource = OfferApprovalRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
