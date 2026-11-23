<?php

namespace App\Filament\Manager\Resources\ReconciliationIssues\Pages;

use App\Filament\Manager\Resources\ReconciliationIssues\ReconciliationIssueResource;
use Filament\Resources\Pages\ListRecords;

class ListReconciliationIssues extends ListRecords
{
    protected static string $resource = ReconciliationIssueResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
