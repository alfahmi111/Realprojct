<?php

namespace App\Providers\Filament;

use App\Filament\Support\ConfiguresPanel;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;

class TechnicianPanelProvider extends PanelProvider
{
    use ConfiguresPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->base($panel, 'technician', 'tech')
            
            ->discoverResources(in: app_path('Filament/Technician/Resources'), for: 'App\Filament\Technician\Resources')
            ->discoverPages(in: app_path('Filament/Technician/Pages'), for: 'App\Filament\Technician\Pages')
            ->discoverWidgets(in: app_path('Filament/Technician/Widgets'), for: 'App\Filament\Technician\Widgets')
            ->pages([Dashboard::class]);
    }
}
