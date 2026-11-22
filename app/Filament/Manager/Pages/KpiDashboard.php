<?php

namespace App\Filament\Manager\Pages;

use App\Filament\Support\KpiPage;
use App\Models\Property;

class KpiDashboard extends KpiPage
{
    protected static ?string $slug = 'kpis';

    protected function propertyIds(): array
    {
        return Property::whereNull('archived_at')->pluck('id')->all();   // RLS: this subscriber only
    }
}
