<?php

namespace App\Filament\Manager\Pages;

use App\Filament\Support\KpiPage;
use App\Models\Property;

class KpiDashboard extends KpiPage
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('reports.view');
    }
    protected static ?string $slug = 'kpis';

    protected function propertyIds(): array
    {
        return \App\Filament\Support\PropertyScope::apply(Property::query(), 'property')->whereNull('archived_at')->pluck('id')->all();   // RLS + the user's scope (REQ-0494)
    }
}
