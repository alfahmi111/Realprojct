<?php

namespace App\Filament\Manager\Resources\LeaseOffers\Pages;

use App\Filament\Manager\Resources\LeaseOffers\LeaseOfferResource;
use Filament\Resources\Pages\ListRecords;

class ListLeaseOffers extends ListRecords
{
    protected static string $resource = LeaseOfferResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
