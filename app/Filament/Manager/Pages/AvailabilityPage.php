<?php

namespace App\Filament\Manager\Pages;

use App\Leasing\LeasingService;
use App\Models\Lease;
use App\Models\Property;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Availability of a property's units on a time axis (REQ-0178, REQ-0180): leased, vacant, held, maintenance, vacating.
 * Redesigned 2026-11-23 as a timeline: each unit is a lane of continuous bars (tenant named on leased bars), months and
 * weeks on the axis, a line for today, and 30 / 90 / 180-day spans with previous / next.
 */
class AvailabilityPage extends Page
{
    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('leasing.view');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'التأجير';

    protected static ?string $navigationLabel = 'تقويم الإتاحة';

    protected static ?string $title = 'تقويم الإتاحة';

    protected static ?string $slug = 'availability';

    protected string $view = 'filament.pages.availability';

    public const SPANS = [30, 90, 180];

    #[Url]
    public ?int $property = null;

    #[Url]
    public ?string $from = null;

    #[Url]
    public int $span = 90;

    public function getHeading(): string
    {
        return '';
    }

    public function shift(int $dir): void
    {
        $this->from = $this->start()->addDays($dir * $this->span())->toDateString();
    }

    public function today(): void
    {
        $this->from = null;
    }

    private function span(): int
    {
        return in_array($this->span, self::SPANS, true) ? $this->span : 90;
    }

    private function start(): CarbonImmutable
    {
        return $this->from ? CarbonImmutable::parse($this->from)->startOfDay() : CarbonImmutable::today()->subDays(intdiv($this->span(), 6));
    }

    protected function getViewData(): array
    {
        $properties = Property::whereNull('archived_at')->orderBy('name_ar')->pluck('name_ar', 'id');
        $pid = $properties->has($this->property) ? $this->property : $properties->keys()->first();
        $span = $this->span();
        $start = $this->start();
        $end = $start->addDays($span - 1);
        $grid = $pid ? app(LeasingService::class)->availability($pid, $start, $end) : [];
        uksort($grid, fn ($a, $b) => strnatcmp((string) $a, (string) $b));
        $leases = $pid ? Lease::whereIn('leasable_unit_id', collect($grid)->pluck('unit_id'))->whereNotIn('status', ['cancelled'])
            ->where('starts_on', '<=', $end->toDateString())->where('ends_on', '>=', $start->toDateString())->with('tenant')->get()->groupBy('leasable_unit_id') : collect();

        $lanes = [];
        $totals = ['leased' => 0, 'vacant' => 0, 'held' => 0, 'maintenance' => 0];
        foreach ($grid as $code => $row) {
            $segs = [];
            $i = 0;
            foreach ($row['days'] as $day => $state) {
                $totals[$state]++;
                $last = count($segs) - 1;
                if ($last >= 0 && $segs[$last]['state'] === $state) {
                    $segs[$last]['len']++;
                    $segs[$last]['to'] = $day;
                } else {
                    $segs[] = ['state' => $state, 'at' => $i, 'len' => 1, 'from' => $day, 'to' => $day];
                }
                $i++;
            }
            foreach ($segs as &$s) {
                $s['label'] = null;
                if ($s['state'] === 'leased') {
                    $l = ($leases[$row['unit_id']] ?? collect())->first(fn ($x) => $x->starts_on->toDateString() <= $s['from'] && $x->ends_on->toDateString() >= $s['from']);
                    $s['label'] = $l?->tenant?->name_ar;
                }
            }
            unset($s);
            $lanes[] = ['code' => $code, 'unit_id' => $row['unit_id'], 'segs' => $segs, 'vacating' => $row['vacating']];
        }

        // axis: month starts and week ticks inside the window
        $months = [];
        for ($d = $start; $d <= $end; $d = $d->addDay()) {
            if ($d->day === 1 || $d->equalTo($start)) {
                $months[] = ['at' => (int) $start->diffInDays($d), 'label' => \IntlDateFormatter::formatObject($d, $span > 31 ? 'MMMM' : 'd MMMM', 'ar@calendar=gregorian;numbers=latn')];
            }
        }
        $todayAt = (int) $start->diffInDays(CarbonImmutable::today(), false);
        $cells = array_sum($totals) ?: 1;
        return [
            'properties' => $properties, 'pid' => $pid, 'start' => $start, 'end' => $end, 'spanDays' => $span, 'lanes' => $lanes, 'months' => $months,
            'todayAt' => $todayAt >= 0 && $todayAt < $span ? $todayAt : null,
            'share' => array_map(fn ($n) => intdiv($n * 100, $cells), $totals),
            'base' => filament()->getCurrentPanel()->getUrl(),
        ];
    }
}
