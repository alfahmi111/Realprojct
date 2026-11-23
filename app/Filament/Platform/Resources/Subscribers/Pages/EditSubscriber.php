<?php

namespace App\Filament\Platform\Resources\Subscribers\Pages;

use App\Filament\Platform\Resources\Subscribers\SubscriberResource;
use Filament\Resources\Pages\EditRecord;

class EditSubscriber extends EditRecord
{
    protected static string $resource = SubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
