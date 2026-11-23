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
 * manager (REQ-0201: existing and new contracts alike). Integer halalas only; each contract year's rent is split
 * across its periods with lossless allocation.
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
            $years = $this->contractYears($start, $end);
            $rents = $this->annualRents($lease, $years);

            $periods = [];
            foreach ($years as $k => [$yStart, $yEnd, $months]) {
                $yearTotal = $rents[$k]->multiplyRatio($months, 12);
                $step = $lease->payment_frequency === 'single' ? null : self::FREQUENCY_MONTHS[$lease->payment_frequency];
                $bounds = [];
                if ($step === null) {
                    $bounds[] = [$yStart, $yEnd];
                } else {
                    for ($m = 0; $m < $months; $m += $step) {
                        $pStart = $yStart->addMonthsNoOverflow($m);
                        $pEnd = min($yStart->addMonthsNoOverflow(min($m + $step, $months))->subDay(), $yEnd);
                        $bounds[] = [$pStart, $pEnd];
                    }
                }
                $weights = array_map(fn ($b) => $this->monthsBetween($b[0], $b[1]), $bounds);
                foreach ($yearTotal->allocate($weights) as $i => $amount) {
                    $periods[] = [$bounds[$i][0], $bounds[$i][1], $amount];
                }
            }
            if ($lease->payment_frequency === 'single') {   // one payment for the whole term
                $periods = [[$start, $end, Money::sum(array_column($periods, 2))]];
            }

            $kept = BillingScheduleLine::where('lease_id', $lease->id)->whereNull('archived_at')->where('status', 'invoiced')->get();
            BillingScheduleLine::where('lease_id', $lease->id)->whereNull('archived_at')->where('status', 'scheduled')->get()->each->archive();
            $seq = (int) $kept->max('sequence');
            $lines = [];
            foreach ($periods as [$pStart, $pEnd, $amount]) {
                if ($kept->contains(fn ($l) => $l->period_start->toDateString() === $pStart->toDateString())) {
                    continue;
                }
                $lines[] = BillingScheduleLine::create([
                    'lease_id' => $lease->id, 'sequence' => ++$seq, 'due_on' => $pStart->toDateString(),
                    'period_start' => $pStart->toDateString(), 'period_end' => $pEnd->toDateString(), 'amount_minor' => $amount, 'status' => 'scheduled',
                ]);
            }
            return $lines;
        });
    }

    /** [[start, end, months], ...] for each contract year; the last one may be shorter. */
    public function contractYears(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $years = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $yEnd = min($cursor->addYearNoOverflow()->subDay(), $end);
            $years[] = [$cursor, $yEnd, $this->monthsBetween($cursor, $yEnd)];
            $cursor = $yEnd->addDay();
        }
        return $years;
    }

    /** Annual rent applicable in each contract year after escalations (suspended ones are skipped). */
    private function annualRents(Lease $lease, array $years): array
    {
        $rent = $lease->annual_rent_minor;
        $escalations = RentEscalation::where('lease_id', $lease->id)->whereIn('status', ['scheduled', 'applied', 'suspended'])->orderBy('id')->get();
        $out = [];
        foreach ($years as $k => [$yStart]) {
            foreach ($escalations as $e) {
                $applies = $e->contract_year !== null ? $e->contract_year === $k + 1 : ($e->effective_on && $e->effective_on->between($yStart, $yStart->addYearNoOverflow()->subDay()));
                if (! $applies || $e->status === 'suspended') {
                    continue;
                }
                $freeze = $this->policies->freezeFor($lease->unit->property, $lease->usage, $yStart);
                if ($freeze) {
                    $e->forceFill(['status' => 'suspended', 'policy_id' => $freeze->id, 'suspension_reason' => 'أوقفت الزيادة بسبب: ' . $freeze->title_ar])->save();
                    continue;
                }
                $rent = $e->kind === 'percentage' ? $rent->plus($rent->percentage($e->percentage_bp)) : $rent->plus($e->amount_minor);
                $e->forceFill(['status' => 'applied'])->save();
            }
            $out[$k] = $rent;
        }
        return $out;
    }

    private function monthsBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return max(1, (int) round($from->diffInMonths($to->addDay())));
    }
}
