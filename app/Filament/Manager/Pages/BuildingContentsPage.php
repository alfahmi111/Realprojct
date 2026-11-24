<?php

namespace App\Filament\Manager\Pages;

use App\Dashboards\HomeOverview;
use App\Maintenance\AssetValuation;
use App\Models\Building;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * "What my building contains" (owner request 2026-11-23, REQ-0262, REQ-0211, REQ-0294): every asset with its
 * straight-line depreciation, remaining life and expected end of service, value by category, a replacement budget for
 * the coming years, maintenance spend, and founding-inventory completion. Lives under Maintenance, where the count is.
 */
class BuildingContentsPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'الصيانة';

    protected static ?string $navigationLabel = 'محتوى المبنى والجرد';

    protected static ?string $title = 'محتوى المبنى والجرد';

    protected static ?string $slug = 'building-contents';

    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.manager.building-contents';

    #[\Livewire\Attributes\Url]
    public ?int $building = null;

    public string $filter = 'all';

    public int $limit = 20;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('assets.view');
    }

    public function getHeading(): string
    {
        return '';
    }

    protected function getViewData(): array
    {
        $home = app(HomeOverview::class);
        $buildings = $home->buildings($home->propertyIds(auth()->user()));
        $id = collect($buildings)->firstWhere('id', $this->building)['id'] ?? ($buildings[0]['id'] ?? null);
        $b = $id ? Building::with('property')->whereIn('property_id', $home->propertyIds(auth()->user()) ?: [0])->find($id) : null;   // REQ-0494
        $data = $b ? app(AssetValuation::class)->building($b->id, $b->property_id, CarbonImmutable::today()) : null;
        if ($data && $this->filter !== 'all') {
            $data['rows'] = array_values(array_filter($data['rows'], fn ($r) => $r['v']['state'] === $this->filter));
        }
        return ['buildings' => $buildings, 'b' => $b, 'data' => $data, 'base' => filament()->getCurrentPanel()->getUrl()];
    }
}
