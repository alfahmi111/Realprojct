<?php

namespace App\Filament\Manager\Resources\LeaseOffers\Pages;

use App\Filament\Manager\Resources\LeaseOffers\LeaseOfferResource;
use App\Leasing\LeasingService;
use App\Models\LeasableUnit;
use App\Models\Tenant;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLeaseOffer extends CreateRecord
{
    protected static string $resource = LeaseOfferResource::class;

    /** Created through the leasing engine so track, regional price and discount rules apply. */
    protected function handleRecordCreation(array $data): Model
    {
        $unit = LeasableUnit::findOrFail($data['leasable_unit_id']);
        $tenant = Tenant::findOrFail($data['tenant_id']);
        $data['escalations'] = array_values(array_map(fn ($e) => array_filter(self::bp($e) + ['percentage_bp' => 0], fn ($v) => $v !== null && $v !== ''), $data['escalations'] ?? []));
        $terms = collect($data)->except(['leasable_unit_id', 'tenant_id'])->all();
        $terms['deposit_minor'] ??= 0;
        try {
            return app(LeasingService::class)->createOffer(auth()->user(), $unit, $tenant, $terms);
        } catch (\App\Leasing\LeasingException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['data.annual_rent_minor' => $e->getMessage()]);
        }
    }

    /** The form takes a plain percentage (5 or 2.5); the offer stores basis points (500, 250). */
    private static function bp(array $e): array
    {
        if (isset($e['percentage']) && $e['percentage'] !== '') {
            $e['percentage_bp'] = (int) round(((float) $e['percentage']) * 100);
        }
        unset($e['percentage']);
        return $e;
    }
}
