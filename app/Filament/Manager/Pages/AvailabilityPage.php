<?php

namespace App\Filament\Manager\Pages;

use App\Leasing\LeasingService;
use App\Models\Property;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Availability grid of a property's units on a time axis (REQ-0178, REQ-0180): leased, vacant, held, maintenance, vacating. */
class AvailabilityPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'التأجير';

    protected static ?string $navigationLabel = 'تقويم الإتاحة';

    protected static ?string $title = 'تقويم الإتاحة';

    protected static ?string $slug = 'availability';

    protected string $view = 'filament.pages.availability';

    #[Url]
    public ?int $property = null;

    #[Url]
    public ?string $from = null;

    protected function getViewData(): array
    {
        $properties = Property::whereNull('archived_at')->pluck('name_ar', 'id');
        $pid = $this->property ?? $properties->keys()->first();
        $from = CarbonImmutable::parse($this->from ?? 'today')->startOfDay();
        return ['properties' => $properties, 'pid' => $pid, 'start' => $from, 'grid' => $pid ? app(LeasingService::class)->availability($pid, $from, $from->addDays(30)) : []];
    }
}
