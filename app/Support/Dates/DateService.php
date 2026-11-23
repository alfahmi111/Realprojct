<?php

namespace App\Support\Dates;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlCalendar;
use IntlDateFormatter;
use InvalidArgumentException;

/**
 * The single API for every date conversion and display (REQ-0656 / decision 13, REQ-0166, REQ-0350…REQ-0352).
 *
 *  - Storage: always Gregorian in UTC. Display settings never change a stored value or a computed deadline.
 *  - Hijri: Umm al-Qura through ICU (`intl`, calendar "islamic-umalqura") — REQ-0345.
 *  - Manual Hijri correction (owner's decision 2026-11-23 on Q19, amending decision 13; from the previous system):
 *    the platform admin records the official Hijri date of a Gregorian day when a sighting differs from Umm al-Qura
 *    by one day (table hijri_corrections). A correction is absolute for that day and wins over the calculation, in
 *    both directions. There is no global offset: the previous system treated a non-zero offset as untrusted.
 */
final class DateService
{
    public const HIJRI_CALENDAR = 'islamic-umalqura';

    /**
     * Umm al-Qura anchors (Gregorian => Hijri), taken from the owner's previous system (special-center-system,
     * includes/helpers.php HIJRI_ANCHORS, verified against ICU). If the server's ICU does not reproduce them, it is
     * not applying Umm al-Qura and no Hijri date is shown or printed — a dash instead of a wrong number.
     */
    public const HIJRI_ANCHORS = [
        '2026-09-04' => [1448, 3, 22],
        '2000-01-01' => [1420, 9, 24],
        '2030-06-15' => [1452, 2, 13],
    ];

    private static ?bool $trusted = null;

    private static string $untrustedReason = '';

    /** @var array<string, array{year:int,month:int,day:int}>|null Gregorian Y-m-d => corrected Hijri */
    private static ?array $corrections = null;

    private static int $correctionsLoadedAt = 0;

    /** Corrections are few (a handful a year); loaded once and refreshed every minute so queue workers follow edits. */
    public static function corrections(): array
    {
        if (self::$corrections === null || time() - self::$correctionsLoadedAt > 60) {
            self::$corrections = [];
            self::$correctionsLoadedAt = time();
            try {
                foreach (\Illuminate\Support\Facades\DB::table('hijri_corrections')->get(['gregorian_date', 'hijri_year', 'hijri_month', 'hijri_day']) as $r) {
                    self::$corrections[substr((string) $r->gregorian_date, 0, 10)] = ['year' => (int) $r->hijri_year, 'month' => (int) $r->hijri_month, 'day' => (int) $r->hijri_day];
                }
            } catch (\Throwable) {
                self::$corrections = []; // no database yet (installation): the calculation alone
            }
        }
        return self::$corrections;
    }

    public static function forgetCorrections(): void
    {
        self::$corrections = null;
    }

    /**
     * A sighting moves a month start by one day, so a valid correction is the calculated Hijri day shifted by exactly
     * one day, including across a month end (29 or 30) and the year end (§7.6ج: ±1 day).
     */
    public static function isOneDayShift(array $calculated, array $corrected): bool
    {
        [$y, $m, $d] = [$calculated['year'], $calculated['month'], $calculated['day']];
        [$cy, $cm, $cd] = [$corrected['year'], $corrected['month'], $corrected['day']];
        if ($cd < 1 || $cd > 30 || $cm < 1 || $cm > 12) {
            return false;
        }
        if ($cy === $y && $cm === $m) {
            return abs($cd - $d) === 1;
        }
        [$ny, $nm] = $m === 12 ? [$y + 1, 1] : [$y, $m + 1];
        [$py, $pm] = $m === 1 ? [$y - 1, 12] : [$y, $m - 1];
        if ($cy === $ny && $cm === $nm) {
            return $cd === 1 && $d >= 29;                       // the month ended a day early
        }
        if ($cy === $py && $cm === $pm) {
            return $d === 1 && in_array($cd, [29, 30], true);  // the previous month ran one day longer
        }
        return false;
    }

    /** Is ICU really applying Umm al-Qura on this server? Measured once per process against the anchors. */
    public function hijriTrusted(?string &$reason = null): bool
    {
        if (self::$trusted === null) {
            self::$trusted = true;
            self::$untrustedReason = '';
            if (! class_exists(IntlCalendar::class)) {
                [self::$trusted, self::$untrustedReason] = [false, 'intl extension is missing'];
            } else {
                foreach (self::HIJRI_ANCHORS as $g => [$y, $m, $d]) {
                    $got = $this->calculatedHijriOfDate($g);
                    if ($got !== ['year' => $y, 'month' => $m, 'day' => $d]) {
                        [self::$trusted, self::$untrustedReason] = [false, sprintf('Umm al-Qura anchor %s should be %04d-%02d-%02d, ICU gives %04d-%02d-%02d', $g, $y, $m, $d, $got['year'], $got['month'], $got['day'])];
                        break;
                    }
                }
            }
            if (! self::$trusted) {
                \Illuminate\Support\Facades\Log::warning('Hijri calendar not trusted: ' . self::$untrustedReason);
            }
        }
        $reason = self::$untrustedReason;
        return self::$trusted;
    }

    /**
     * Hijri parts of a calendar DATE (no time), independent of any timezone. Converted at noon, as the previous
     * system did, so a date never slips to the neighbouring day (e.g. a lease end date viewed from UTC−5).
     */
    public function hijriOfDate(DateTimeInterface|string $date): array
    {
        $ymd = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : substr(trim($date), 0, 10);
        return self::corrections()[$ymd] ?? $this->calculatedHijriOfDate($ymd);
    }

    /** Umm al-Qura alone, without any manual correction (the anchors, and the reference shown next to a correction). */
    public function calculatedHijriOfDate(DateTimeInterface|string $date): array
    {
        $ymd = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : substr(trim($date), 0, 10);
        return $this->rawParts(CarbonImmutable::parse($ymd . ' 12:00:00', 'UTC'), 'UTC');
    }

    /**
     * When a form posts both a Gregorian date and the Hijri the browser computed, they must agree; otherwise the
     * operation stops and shows both values (previous system: hijriCrossCheck). Nothing is compared if one is empty.
     *
     * @throws HijriMismatchException
     */
    public function crossCheck(?string $gregorian, ?string $postedHijri): void
    {
        $g = trim((string) $gregorian);
        $h = trim((string) $postedHijri);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $g) || ! preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $h, $m)) {
            return;
        }
        $server = $this->hijriOfDate($g);
        if ($server !== ['year' => (int) $m[1], 'month' => (int) $m[2], 'day' => (int) $m[3]]) {
            throw new HijriMismatchException(sprintf('تعارض في التاريخ: المتصفح يقول %s هـ والخادم يقول %04d-%02d-%02d هـ للتاريخ الميلادي %s. لم يُحفظ شيء؛ أعد تحميل الصفحة وحاول مرة أخرى.', $h, $server['year'], $server['month'], $server['day'], $g));
        }
    }

    /** Display a calendar DATE (no time) — same modes as format(), never shifted by a timezone. */
    public function formatDate(DateTimeInterface|string $date, string $mode, string $locale = 'ar', string $digits = 'western'): string
    {
        $ymd = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : substr(trim($date), 0, 10);
        return $this->format(CarbonImmutable::parse($ymd . ' 12:00:00', 'UTC'), $mode, 'UTC', $locale, $digits);
    }

    /** Normalise any input to an immutable UTC instant — the only form that is stored or compared. */
    public function toUtc(DateTimeInterface|string $value, ?string $fromTimezone = null): CarbonImmutable
    {
        $c = is_string($value)
            ? CarbonImmutable::parse($value, $fromTimezone ? new DateTimeZone($fromTimezone) : null)
            : CarbonImmutable::instance($value);
        return $c->utc();
    }

    /** Hijri parts of an instant as seen in a timezone: ['year'=>1448,'month'=>5,'day'=>9]. A manual correction wins. */
    public function hijriParts(DateTimeInterface $instant, string $timezone): array
    {
        $local = CarbonImmutable::instance($instant)->setTimezone($timezone)->format('Y-m-d');
        return self::corrections()[$local] ?? $this->rawParts($instant, $timezone);
    }

    private function rawParts(DateTimeInterface $instant, string $timezone): array
    {
        $cal = IntlCalendar::createInstance(new DateTimeZone($timezone), '@calendar=' . self::HIJRI_CALENDAR);
        $cal->setTime($instant->getTimestamp() * 1000);
        return [
            'year' => $cal->get(IntlCalendar::FIELD_EXTENDED_YEAR),
            'month' => $cal->get(IntlCalendar::FIELD_MONTH) + 1,
            'day' => $cal->get(IntlCalendar::FIELD_DAY_OF_MONTH),
        ];
    }

    /** Convert a Hijri (Umm al-Qura) calendar date to the Gregorian date (Y-m-d) of that day. */
    public function hijriToGregorian(int $year, int $month, int $day): string
    {
        $want = ['year' => $year, 'month' => $month, 'day' => $day];
        $hit = array_search($want, self::corrections(), true);
        if ($hit !== false) {
            return $hit;
        }
        $g = $this->calculatedHijriToGregorian($year, $month, $day);
        // The calculated day may itself be corrected to another Hijri date; the target is then a neighbouring day.
        foreach ([0, -1, 1, -2, 2] as $delta) {
            $candidate = CarbonImmutable::parse($g, 'UTC')->addDays($delta)->toDateString();
            if ($this->hijriOfDate($candidate) === $want) {
                return $candidate;
            }
        }
        throw new InvalidArgumentException("Hijri date $year-$month-$day does not exist after the manual corrections.");
    }

    private function calculatedHijriToGregorian(int $year, int $month, int $day): string
    {
        $cal = IntlCalendar::createInstance(new DateTimeZone('UTC'), '@calendar=' . self::HIJRI_CALENDAR);
        $cal->clear();
        $cal->set(IntlCalendar::FIELD_EXTENDED_YEAR, $year);
        $cal->set(IntlCalendar::FIELD_MONTH, $month - 1);
        $cal->set(IntlCalendar::FIELD_DAY_OF_MONTH, $day);
        $cal->set(IntlCalendar::FIELD_HOUR_OF_DAY, 12);
        $back = $this->rawParts(CarbonImmutable::createFromTimestamp((int) ($cal->getTime() / 1000)), 'UTC');
        if ($back !== ['year' => $year, 'month' => $month, 'day' => $day]) {
            throw new InvalidArgumentException("Invalid Hijri date $year-$month-$day.");
        }
        return CarbonImmutable::createFromTimestamp((int) ($cal->getTime() / 1000), 'UTC')->toDateString();
    }

    /**
     * Format an instant for display.
     *
     * @param  string  $mode  hijri | gregorian | both_hijri_first | both_gregorian_first (setting date.display / date.documents)
     * @param  string  $locale  ar | en
     * @param  string  $digits  western | eastern (setting numbers.digits)
     */
    public function format(DateTimeInterface $instant, string $mode, string $timezone, string $locale = 'ar', string $digits = 'western', bool $withTime = false): string
    {
        $g = $this->formatCalendar($instant, 'gregorian', $timezone, $locale, $withTime);
        // A dash is visible; a wrong Hijri number looks right. Never print one the server cannot vouch for.
        $h = $mode === 'gregorian' ? '' : ($this->hijriTrusted()
            ? $this->formatHijri($instant, $timezone, $locale, $withTime) .($locale === 'ar' ? ' هـ' : ' AH')
            : '—');
        $text = match ($mode) {
            'hijri' => $h,
            'gregorian' => $g,
            'both_hijri_first' => "$h ($g)",
            'both_gregorian_first' => "$g ($h)",
            default => throw new InvalidArgumentException("Unknown date display mode [$mode]."),
        };
        return NumberFormatter::digits($text, $digits);
    }

    /**
     * E-invoices always carry the Gregorian date as ZATCA requires (REQ-0351); Hijri may be added
     * next to it only on the printed copy.
     */
    public function eInvoiceDate(DateTimeInterface $instant, string $timezone): string
    {
        return CarbonImmutable::instance($instant)->setTimezone($timezone)->format('Y-m-d');
    }

    /** Due dates and notice deadlines are computed on stored UTC values only (REQ-0352). */
    public function addDays(DateTimeInterface $storedUtc, int $days): CarbonImmutable
    {
        return CarbonImmutable::instance($storedUtc)->utc()->addDays($days);
    }

    /** Hijri text of an instant; a corrected day is written with ICU's own month name so both read alike. */
    private function formatHijri(DateTimeInterface $instant, string $timezone, string $locale, bool $withTime): string
    {
        $local = CarbonImmutable::instance($instant)->setTimezone($timezone);
        $fix = self::corrections()[$local->format('Y-m-d')] ?? null;
        if ($fix === null) {
            return $this->formatCalendar($instant, self::HIJRI_CALENDAR, $timezone, $locale, $withTime);
        }
        $monthStart = CarbonImmutable::parse($this->calculatedHijriToGregorian($fix['year'], $fix['month'], 1) . ' 12:00:00', 'UTC');
        $fmt = new IntlDateFormatter(($locale === 'ar' ? 'ar_SA' : 'en_US') . '@calendar=' . self::HIJRI_CALENDAR . ';numbers=latn',
            IntlDateFormatter::NONE, IntlDateFormatter::NONE, new DateTimeZone('UTC'), IntlDateFormatter::TRADITIONAL, 'MMMM');
        $text = $fix['day'] . ' ' . $fmt->format($monthStart->getTimestamp()) . ' ' . $fix['year'];
        return $withTime ? $text . '، ' . $local->format('H:i') : $text;
    }

    private function formatCalendar(DateTimeInterface $instant, string $calendar, string $timezone, string $locale, bool $withTime): string
    {
        $fmt = new IntlDateFormatter(
            ($locale === 'ar' ? 'ar_SA' : 'en_US') . '@calendar=' . $calendar . ';numbers=latn',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            new DateTimeZone($timezone),
            $calendar === 'gregorian' ? IntlDateFormatter::GREGORIAN : IntlDateFormatter::TRADITIONAL,
            $withTime ? 'd MMMM y، HH:mm' : 'd MMMM y',
        );
        $out = $fmt->format($instant->getTimestamp());
        if ($out === false) {
            throw new InvalidArgumentException('Date formatting failed: ' . intl_get_error_message());
        }
        // ICU appends the era for some calendars ("هـ"/"AH"); we add our own suffix consistently.
        return trim(str_replace([' هـ', ' AH'], '', $out));
    }
}
