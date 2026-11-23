<?php

namespace App\Filament\Manager\Resources\Leases\Pages;

use App\Filament\Manager\Resources\Leases\LeaseResource;
use App\Finance\InvoiceService;
use App\Leasing\LeasingService;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Space;
use App\Models\Subscriber;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\ViewRecord;

/**
 * The lease shown the way Ejar shows a tenancy contract (owner request 2026-11-23, structure taken from an Ejar contract
 * the owner shared): contract data, lessor and its representative, tenant, ownership document, property, rental unit,
 * financial data and the payment schedule in both calendars, then the dates that matter (notice deadline).
 * Only data the platform holds is shown; nothing is invented to fill a field (ر-01).
 */
class ViewLease extends ViewRecord
{
    protected static string $resource = LeaseResource::class;

    protected string $view = 'filament.manager.lease-view';

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getHeading(): string
    {
        return '';
    }

    public function contract(): array
    {
        /** @var Lease $l */
        $l = $this->record->load(['unit.property.shares.owner', 'unit.spaces', 'tenant', 'scheduleLines']);
        $unit = $l->unit;
        $property = $unit->property;
        $space = $unit->spaces->first();
        $floor = $space?->parent_id ? Space::find($space->parent_id) : null;
        $starts = CarbonImmutable::parse($l->starts_on);
        $ends = CarbonImmutable::parse($l->ends_on);
        $invoices = Invoice::where('lease_id', $l->id)->get()->keyBy('billing_schedule_line_id');
        $inv = app(InvoiceService::class);
        $lines = $l->scheduleLines->sortBy('sequence')->values()->map(function ($line) use ($invoices, $inv) {
            $i = $invoices[$line->id] ?? null;
            $state = match (true) {
                $i === null => 'scheduled',
                $i->status === 'cancelled' => 'cancelled',
                $inv->outstanding($i)->isPositive() => 'due',
                default => 'paid',
            };
            return ['seq' => $line->sequence, 'due_on' => CarbonImmutable::parse($line->due_on), 'from' => CarbonImmutable::parse($line->period_start), 'to' => CarbonImmutable::parse($line->period_end),
                'days' => (int) CarbonImmutable::parse($line->period_start)->diffInDays(CarbonImmutable::parse($line->period_end)) + 1, 'amount' => $line->amount_minor, 'state' => $state, 'invoice' => $i?->number];
        })->all();
        $total = array_reduce($lines, fn ($c, $x) => $c + $x['amount']->minor(), 0);
        return [
            'l' => $l, 'unit' => $unit, 'property' => $property, 'space' => $space, 'floor' => $floor,
            'owners' => $property->shares->map(fn ($s) => ['name' => $s->owner->name_ar, 'kind' => $s->owner->kind, 'id' => $s->owner->national_id ?: $s->owner->commercial_registration, 'share' => $s->share_numerator . '/' . $s->share_denominator])->all(),
            'office' => Subscriber::find($l->subscriber_id),
            'starts' => $starts, 'ends' => $ends, 'days' => (int) $starts->diffInDays($ends) + 1,
            'notice' => app(LeasingService::class)->noticeDeadline($l),
            'lines' => $lines, 'total' => $total, 'attributes' => (array) ($space?->attributes ?? []),
        ];
    }
}
