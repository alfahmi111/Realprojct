<?php

namespace App\Providers\Filament;

use App\Filament\Support\ConfiguresPanel;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;

class ManagerPanelProvider extends PanelProvider
{
    use ConfiguresPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->base($panel, 'manager', 'manage')
            ->default()
            ->discoverResources(in: app_path('Filament/Manager/Resources'), for: 'App\Filament\Manager\Resources')
            ->discoverPages(in: app_path('Filament/Manager/Pages'), for: 'App\Filament\Manager\Pages')
            ->discoverWidgets(in: app_path('Filament/Manager/Widgets'), for: 'App\Filament\Manager\Widgets')
            ->pages([Dashboard::class]);
    }
}
