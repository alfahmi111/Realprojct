<?php

namespace App\Support\Dates;

use App\Audit\AuditLogger;
use App\Models\HijriCorrection;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Recording and removing manual Hijri corrections (Q19, owner's decision 2026-11-23; previous system
 * modules/settings/hijri.php). Platform admin only. A correction must differ from the calculation by exactly one day
 * (§7.6ج), and every change is audited with the before and after values.
 */
final class HijriCorrections
{
    public function __construct(private DateService $dates, private TenantContext $tenant)
    {
    }

    public function record(string $gregorian, int $year, int $month, int $day, ?string $note = null): HijriCorrection
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $gregorian) || CarbonImmutable::createFromFormat('!Y-m-d', $gregorian, 'UTC')?->toDateString() !== $gregorian) {
            throw new InvalidArgumentException('التاريخ الميلادي غير صالح.');
        }
        $calculated = $this->dates->calculatedHijriOfDate($gregorian);
        $corrected = ['year' => $year, 'month' => $month, 'day' => $day];
        if ($corrected === $calculated) {
            throw new InvalidArgumentException('التاريخ المُدخل مطابق لما يحسبه تقويم أم القرى أصلاً، فلا حاجة لتصحيح.');
        }
        if (! DateService::isOneDayShift($calculated, $corrected)) {
            throw new InvalidArgumentException(sprintf('التصحيح يكون بيوم واحد فقط قبل الحساب أو بعده. الحساب لهذا اليوم %04d-%02d-%02d هـ.', $calculated['year'], $calculated['month'], $calculated['day']));
        }

        return DB::transaction(function () use ($gregorian, $corrected, $calculated, $note) {
            $row = HijriCorrection::firstOrNew(['gregorian_date' => $gregorian]);
            $before = $row->exists ? ['hijri' => $row->hijri(), 'note' => $row->note] : null;
            $row->fill([
                'hijri_year' => $corrected['year'], 'hijri_month' => $corrected['month'], 'hijri_day' => $corrected['day'],
                'calculated_hijri' => sprintf('%04d-%02d-%02d', $calculated['year'], $calculated['month'], $calculated['day']),
                'note' => $note === null || trim($note) === '' ? null : trim($note),
            ]);
            $row->created_by ??= auth()->id();
            $row->save();
            $this->tenant->run(null, fn () => AuditLogger::record('hijri.correction.saved', $row, $before, ['gregorian' => $gregorian, 'hijri' => $row->hijri(), 'calculated' => $row->calculated_hijri, 'note' => $row->note]));
            return $row;
        });
    }

    /**
     * A sighting that moves a month start shifts every following day by one until the calendars meet again, so the
     * correction is carried forward day by day. It stops at day 29 of the corrected month (whether day 30 exists is
     * the next sighting's decision), or as soon as the calculation already agrees.
     *
     * @return list<HijriCorrection>
     */
    public function recordRestOfMonth(string $gregorian, int $year, int $month, int $day, ?string $note = null): array
    {
        return DB::transaction(function () use ($gregorian, $year, $month, $day, $note) {
            $rows = [$this->record($gregorian, $year, $month, $day, $note)];
            $h = ['year' => $year, 'month' => $month, 'day' => $day];
            $g = CarbonImmutable::parse($gregorian, 'UTC');
            while ($h['day'] !== 29) {
                $h = $h['day'] === 30
                    ? ['year' => $h['month'] === 12 ? $h['year'] + 1 : $h['year'], 'month' => $h['month'] === 12 ? 1 : $h['month'] + 1, 'day' => 1]
                    : ['year' => $h['year'], 'month' => $h['month'], 'day' => $h['day'] + 1];
                $g = $g->addDay();
                $calculated = $this->dates->calculatedHijriOfDate($g->toDateString());
                if ($calculated === $h || ! DateService::isOneDayShift($calculated, $h)) {
                    break; // realigned with the calculation
                }
                $rows[] = $this->record($g->toDateString(), $h['year'], $h['month'], $h['day'], $note);
            }
            return $rows;
        });
    }

    public function remove(HijriCorrection $row): void
    {
        DB::transaction(function () use ($row) {
            $before = ['gregorian' => $row->gregorian_date->toDateString(), 'hijri' => $row->hijri(), 'calculated' => $row->calculated_hijri, 'note' => $row->note];
            $row->delete();
            $this->tenant->run(null, fn () => AuditLogger::record('hijri.correction.removed', 'hijri_correction', $before, null, (string) $row->id));
        });
    }
}
