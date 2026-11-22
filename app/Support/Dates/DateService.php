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
 *  - Manual Hijri correction: the subscriber setting exists (date.hijri_correction) but NO correction logic is
 *    written until the owner provides the specification (decision 13). This class ignores it on purpose.
 */
final class DateService
{
    public const HIJRI_CALENDAR = 'islamic-umalqura';

    /** Normalise any input to an immutable UTC instant — the only form that is stored or compared. */
    public function toUtc(DateTimeInterface|string $value, ?string $fromTimezone = null): CarbonImmutable
    {
        $c = is_string($value)
            ? CarbonImmutable::parse($value, $fromTimezone ? new DateTimeZone($fromTimezone) : null)
            : CarbonImmutable::instance($value);
        return $c->utc();
    }

    /** Hijri (Umm al-Qura) parts of an instant as seen in a timezone: ['year'=>1448,'month'=>5,'day'=>9]. */
    public function hijriParts(DateTimeInterface $instant, string $timezone): array
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
        $cal = IntlCalendar::createInstance(new DateTimeZone('UTC'), '@calendar=' . self::HIJRI_CALENDAR);
        $cal->clear();
        $cal->set(IntlCalendar::FIELD_EXTENDED_YEAR, $year);
        $cal->set(IntlCalendar::FIELD_MONTH, $month - 1);
        $cal->set(IntlCalendar::FIELD_DAY_OF_MONTH, $day);
        $cal->set(IntlCalendar::FIELD_HOUR_OF_DAY, 12);
        $back = $this->hijriParts(CarbonImmutable::createFromTimestamp((int) ($cal->getTime() / 1000)), 'UTC');
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
        $h = $this->formatCalendar($instant, self::HIJRI_CALENDAR, $timezone, $locale, $withTime) . ($locale === 'ar' ? ' هـ' : ' AH');
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
