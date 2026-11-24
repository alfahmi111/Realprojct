<?php

namespace App\Support\Dates;

use Carbon\CarbonImmutable;

/**
 * "Today" as the office lives it (review M18 L13): the calendar date in Riyadh, returned as midnight UTC so it compares
 * correctly with the date columns (stored as Gregorian dates, read as UTC midnight). Between midnight and 03:00 in Riyadh
 * UTC still shows yesterday; with this, due dates, expiries and "today" lists turn over at Riyadh's midnight.
 */
final class Today
{
    public static function date(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(config('app.display_timezone', 'Asia/Riyadh'))->toDateString(), 'UTC');
    }

    public static function string(): string
    {
        return self::date()->toDateString();
    }
}
