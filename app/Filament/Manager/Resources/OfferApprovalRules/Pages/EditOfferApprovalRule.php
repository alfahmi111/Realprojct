<?php

namespace App\Filament\Manager\Resources\OfferApprovalRules\Pages;

use App\Filament\Manager\Resources\OfferApprovalRules\OfferApprovalRuleResource;
use Filament\Resources\Pages\EditRecord;

class EditOfferApprovalRule extends EditRecord
{
    protected static string $resource = OfferApprovalRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
