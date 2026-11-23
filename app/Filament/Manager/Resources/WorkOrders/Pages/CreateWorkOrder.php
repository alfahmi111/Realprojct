<?php

namespace App\Filament\Manager\Resources\WorkOrders\Pages;

use App\Filament\Manager\Resources\WorkOrders\WorkOrderResource;
use App\Maintenance\MaintenanceService;
use App\Models\Asset;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWorkOrder extends CreateRecord
{
    protected static string $resource = WorkOrderResource::class;

    /** Reported through the maintenance service (numbering, priority for emergencies). */
    protected function handleRecordCreation(array $data): Model
    {
        return app(MaintenanceService::class)->report($data['type'], (int) $data['property_id'], $data['description'], 'supervisor', auth()->user(), isset($data['asset_id']) ? Asset::find($data['asset_id']) : null, null, $data['photos_report'] ?? []);
    }
}
