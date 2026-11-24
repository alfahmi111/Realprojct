<?php

namespace App\Leasing;

use App\Models\BillingScheduleLine;
use App\Models\Lease;
use App\Models\RentEscalation;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Billing schedule (spec §5.5): independent from the lease term (REQ-0187) — monthly, quarterly, semi-annual,
 * annual or single payment. Scheduled escalations (REQ-0190) regenerate the schedule; before applying any
 * escalation the policies table is consulted and a frozen escalation is suspended with its reason shown to the
 * manager (REQ-0201: existing and new contracts alike). Integer halalas only.
 *
 * Rewritten in the system review M18 (the previous version rounded contract years to whole months, so a lease ending
 * mid-month lost or over-billed days, a leap-day start produced a spurious fifth year, and a date-based escalation was
 * charged from the start of its contract year):
 *  - contract years are anchored on the original start date (start + k years), the last one ends exactly on ends_on;
 *  - periods are anchored on the contract year start (start + j × step months) and the last one ends on ends_on;
 *  - a full period costs rent × step / 12; a partial one is prorated by days of its natural period;
 *  - a date-based escalation splits the period it falls in and applies from that date;
 *  - a full contract year with one rent is allocated losslessly, so it sums to the annual rent to the halala.
 */
class ScheduleGenerator
{
    public const FREQUENCY_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12];

    public function __construct(private PolicyService $policies)
    {
    }

    /** Rebuild the unbilled schedule of a lease from its terms and escalations. Already-invoiced lines are kept. */
    public function generate(Lease $lease): array
    {
        return DB::transaction(function () use ($lease) {
            $lease->loadMissing('unit.property');
            $start = CarbonImmutable::parse($lease->starts_on);
            $end = CarbonImmutable::parse($lease->ends_on);
            $step = $lease->payment_frequency === 'single' ? 12 : self::FREQUENCY_MONTHS[$lease->payment_frequency];
            $periods = [];
            foreach ($this->contractYears($start, $end) as $k => [$yStart, $yEnd, $natEnd]) {
                $segments = $this->segments($lease, $k, $yStart, $yEnd);
                $lines = [];
                $cursor = $yStart;
                for ($j = 1; $cursor <= $yEnd; $j++) {
                    $natural = LeasingService::lastDay($yStart, $yStart->addMonthsNoOverflow($j * $step));
                    $pEnd = $natural->min($yEnd);
                    $natDays = $this->days($cursor, $natural);
                    // split the period where an escalation starts inside it
                    foreach ($segments as [$sStart, $sEnd, $rent]) {
                        $a = $cursor->max($sStart);
                        $b = $pEnd->min($sEnd);
                        if ($a > $b) {
                            continue;
                        }
                        $lines[] = ['start' => $a, 'end' => $b, 'rent' => $rent, 'days' => $this->days($a, $b), 'nat' => $natDays];
                    }
                    $cursor = $pEnd->addDay();
                }
                // a full contract year at one rent: equal installments that add up exactly to the annual rent
                $homogeneous = count($segments) === 1 && $yEnd->equalTo($natEnd);
                if ($homogeneous) {
                    $amounts = $segments[0][2]->allocate(array_map(fn ($l) => $l['days'] === $l['nat'] ? 1000 : intdiv(1000 * $l['days'], $l['nat']), $lines));
                } else {
                    $amounts = array_map(fn ($l) => $l['rent']->multiplyRatio($step * $l['days'], 12 * $l['nat']), $lines);
                }
                foreach ($lines as $i => $l) {
                    $periods[] = [$l['start'], $l['end'], $amounts[$i]];
                }
            }
            if ($lease->payment_frequency === 'single') {   // one payment for the whole term
                $periods = [[$start, $end, Money::sum(array_column($periods, 2))]];
            }

            $kept = BillingScheduleLine::where('lease_id', $lease->id)->whereNull('archived_at')->where('status', 'invoiced')->get();
            BillingScheduleLine::where('lease_id', $lease->id)->whereNull('archived_at')->where('status', 'scheduled')->get()->each->archive();
            $seq = (int) $kept->max('sequence');
            $out = [];
            foreach ($periods as [$pStart, $pEnd, $amount]) {
                if ($kept->contains(fn ($l) => $l->period_start->toDateString() === $pStart->toDateString())) {
                    continue;
                }
                $out[] = BillingScheduleLine::create([
                    'lease_id' => $lease->id, 'sequence' => ++$seq, 'due_on' => $pStart->toDateString(),
                    'period_start' => $pStart->toDateString(), 'period_end' => $pEnd->toDateString(), 'amount_minor' => $amount, 'status' => 'scheduled',
                ]);
            }
            return $out;
        });
    }

    /**
     * [[start, end, naturalEnd], ...] for each contract year, anchored on the original start (start + k years); the last
     * year ends on $end and may be shorter than its natural end.
     */
    public function contractYears(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $years = [];
        $cursor = $start;
        for ($k = 1; $cursor <= $end; $k++) {
            $natural = LeasingService::lastDay($start, $start->addYearsNoOverflow($k));
            $years[] = [$cursor, $natural->min($end), $natural];
            $cursor = $natural->addDay();
        }
        return $years;
    }

    /**
     * Rent segments inside contract year $k: [[from, to, annualRent], ...]. A contract-year escalation applies from the
     * year's start; a date escalation from its date (a date before this year was applied in an earlier year). Frozen
     * escalations are suspended with the policy's reason (REQ-0201).
     */
    private function segments(Lease $lease, int $k, CarbonImmutable $yStart, CarbonImmutable $yEnd): array
    {
        static $state = [];
        $key = $lease->id;
        if ($k === 0) {
            $state[$key] = ['rent' => $lease->annual_rent_minor, 'done' => []];
        }
        $rent = $state[$key]['rent'];
        $points = [];   // [date, escalation]
        foreach (RentEscalation::where('lease_id', $lease->id)->whereIn('status', ['scheduled', 'applied', 'suspended'])->orderBy('id')->get() as $e) {
            if (in_array($e->id, $state[$key]['done'], true) || $e->status === 'suspended') {
                continue;
            }
            if ($e->contract_year !== null) {
                if ($e->contract_year === $k + 1) {
                    $points[] = [$yStart, $e];
                }
            } elseif ($e->effective_on && $e->effective_on->lessThanOrEqualTo($yEnd)) {
                $points[] = [CarbonImmutable::parse($e->effective_on->toDateString())->max($yStart), $e];
            }
        }
        usort($points, fn ($a, $b) => $a[0] <=> $b[0]);
        $segments = [];
        $from = $yStart;
        foreach ($points as [$date, $e]) {
            $state[$key]['done'][] = $e->id;
            $freeze = $this->policies->freezeFor($lease->unit->property, $lease->usage, $date);
            if ($freeze) {
                $e->forceFill(['status' => 'suspended', 'policy_id' => $freeze->id, 'suspension_reason' => 'أوقفت الزيادة بسبب: ' . $freeze->title_ar])->save();
                continue;
            }
            if ($date > $from) {
                $segments[] = [$from, $date->subDay(), $rent];
                $from = $date;
            }
            $rent = $e->kind === 'percentage' ? $rent->plus($rent->percentage($e->percentage_bp)) : $rent->plus($e->amount_minor);
            $e->forceFill(['status' => 'applied'])->save();
        }
        $segments[] = [$from, $yEnd, $rent];
        $state[$key]['rent'] = $rent;
        return $segments;
    }

    private function days(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) $from->diffInDays($to) + 1;
    }
}
