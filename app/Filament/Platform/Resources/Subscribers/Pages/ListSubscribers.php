<?php

namespace App\Filament\Platform\Resources\Subscribers\Pages;

use App\Filament\Platform\Resources\Subscribers\SubscriberResource;
use Filament\Resources\Pages\ListRecords;

class ListSubscribers extends ListRecords
{
    protected static string $resource = SubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
