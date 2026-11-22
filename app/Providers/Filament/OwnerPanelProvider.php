<?php

namespace App\Providers\Filament;

use App\Filament\Support\ConfiguresPanel;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;

class OwnerPanelProvider extends PanelProvider
{
    use ConfiguresPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->base($panel, 'owner', 'owner')
            
            ->discoverResources(in: app_path('Filament/Owner/Resources'), for: 'App\Filament\Owner\Resources')
            ->discoverPages(in: app_path('Filament/Owner/Pages'), for: 'App\Filament\Owner\Pages')
            ->discoverWidgets(in: app_path('Filament/Owner/Widgets'), for: 'App\Filament\Owner\Widgets')
            ->pages([Dashboard::class]);
    }
}
