<?php

namespace App\Filament\Owner\Pages;

use App\Filament\Support\KpiPage;
use App\Models\Owner;
use App\Models\OwnershipShare;

/** The owner sees the manager's indicators for his own properties only, in simple language (REQ-0304). */
class KpiDashboard extends KpiPage
{
    protected static ?string $slug = 'kpis';

    protected function propertyIds(): array
    {
        $owner = Owner::where('user_id', auth()->id())->first();
        return $owner ? OwnershipShare::where('owner_id', $owner->id)->pluck('property_id')->all() : [];
    }
}
