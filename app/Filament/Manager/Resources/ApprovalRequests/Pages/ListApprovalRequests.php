<?php

namespace App\Filament\Manager\Resources\ApprovalRequests\Pages;

use App\Filament\Manager\Resources\ApprovalRequests\ApprovalRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListApprovalRequests extends ListRecords
{
    protected static string $resource = ApprovalRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
