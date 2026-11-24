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
        return $this->from ? CarbonImmutable::parse($this->from)->startOfDay() : \App\Support\Dates\Today::date()->subDays(intdiv($this->span(), 6));
    }

    protected function getViewData(): array
    {
        $properties = \App\Filament\Support\PropertyScope::apply(Property::query(), 'property')->whereNull('archived_at')->orderBy('name_ar')->pluck('name_ar', 'id');   // REQ-0494
        // several properties (owner request 2026-11-24): «كل العقارات» by default, each property under its own heading
        $pid = $properties->has($this->property) ? $this->property : ($properties->count() > 1 ? 0 : $properties->keys()->first());
        $span = $this->span();
        $start = $this->start();
        $end = $start->addDays($span - 1);
        $grid = [];
        foreach ($pid === 0 ? $properties->keys()->all() : array_filter([$pid]) as $one) {
            $part = app(LeasingService::class)->availability($one, $start, $end);
            uksort($part, fn ($a, $b) => strnatcmp((string) $a, (string) $b));
            foreach ($part as $code => $row) {
                $grid[$one . '|' . $code] = $row + ['property_id' => $one, 'code' => $code];
            }
        }
        $leases = $grid ? Lease::whereIn('leasable_unit_id', collect($grid)->pluck('unit_id'))->whereNotIn('status', ['cancelled'])
            ->where('starts_on', '<=', $end->toDateString())->where('ends_on', '>=', $start->toDateString())->with('tenant')->get()->groupBy('leasable_unit_id') : collect();

        $leaseById = $leases->flatten()->keyBy('id');
        $lanes = [];
        $totals = ['leased' => 0, 'vacant' => 0, 'held' => 0, 'maintenance' => 0];
        foreach ($grid as $row) {
            $code = $row['code'];
            $segs = [];
            $i = 0;
            foreach ($row['days'] as $day => $state) {
                if (empty($row['composite'])) {
                    $totals[$state]++;   // a whole-building unit repeats its parts' days: kept out of the shares (review M18)
                }
                $leaseId = $row['day_leases'][$day] ?? null;
                $last = count($segs) - 1;
                if ($last >= 0 && $segs[$last]['state'] === $state && $segs[$last]['lease_id'] === $leaseId) {
                    $segs[$last]['len']++;
                    $segs[$last]['to'] = $day;
                } else {
                    $segs[] = ['state' => $state, 'at' => $i, 'len' => 1, 'from' => $day, 'to' => $day, 'lease_id' => $leaseId];
                }
                $i++;
            }
            foreach ($segs as &$s) {
                $s['label'] = null;
                if ($s['state'] === 'leased') {
                    $l = $s['lease_id'] ? $leaseById->get($s['lease_id']) : null;
                    $s['label'] = $l?->tenant?->name_ar;
                }
            }
            unset($s);
            $lanes[] = ['code' => $code, 'unit_id' => $row['unit_id'], 'segs' => $segs, 'vacating' => $row['vacating'], 'property' => $properties[$row['property_id']] ?? ''];
        }

        // axis: month starts and week ticks inside the window
        $months = [];
        for ($d = $start; $d <= $end; $d = $d->addDay()) {
            if ($d->day === 1 || $d->equalTo($start)) {
                $months[] = ['at' => (int) $start->diffInDays($d), 'label' => \IntlDateFormatter::formatObject($d, $span > 31 ? 'MMMM' : 'd MMMM', 'ar@calendar=gregorian;numbers=latn')];
            }
        }
        $todayAt = (int) $start->diffInDays(\App\Support\Dates\Today::date(), false);
        $cells = array_sum($totals) ?: 1;
        return [
            'properties' => $properties, 'pid' => $pid, 'start' => $start, 'end' => $end, 'spanDays' => $span, 'lanes' => $lanes, 'months' => $months,
            'todayAt' => $todayAt >= 0 && $todayAt < $span ? $todayAt : null,
            'share' => array_map(fn ($n) => intdiv($n * 100, $cells), $totals),
            'base' => filament()->getCurrentPanel()->getUrl(),
        ];
    }
}
