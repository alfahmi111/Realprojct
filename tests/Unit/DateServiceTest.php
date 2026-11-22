<?php

namespace Tests\Unit;

use App\Support\Dates\DateService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * REQ-0166, REQ-0350 — dates stored Gregorian UTC, displayed Hijri (Umm al-Qura) / Gregorian / both by setting.
 * REQ-0345, REQ-0491 — Umm al-Qura via intl. REQ-0656 — one API for every conversion, no correction logic.
 * REQ-0351 — e-invoice carries the Gregorian date. REQ-0352 — deadlines computed on the stored value.
 */
class DateServiceTest extends TestCase
{
    private DateService $d;

    protected function setUp(): void
    {
        parent::setUp();
        $this->d = app(DateService::class);
    }

    public function test_req_0345_umm_al_qura_conversion_matches_known_dates(): void
    {
        $instant = CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC');
        $this->assertSame(['year' => 1448, 'month' => 6, 'day' => 10], $this->d->hijriParts($instant, 'Asia/Riyadh'));
        $this->assertSame('2026-11-20', $this->d->hijriToGregorian(1448, 6, 10));
    }

    public function test_req_0656_hijri_round_trip_over_many_days(): void
    {
        $day = CarbonImmutable::parse('2026-01-01 12:00', 'UTC');
        for ($i = 0; $i < 400; $i += 7) {
            $g = $day->addDays($i);
            $h = $this->d->hijriParts($g, 'UTC');
            $this->assertSame($g->toDateString(), $this->d->hijriToGregorian($h['year'], $h['month'], $h['day']));
        }
    }

    public function test_req_0656_invalid_hijri_dates_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->d->hijriToGregorian(1448, 13, 1);
    }

    public function test_req_0166_display_modes_change_text_only(): void
    {
        $instant = CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC');
        $this->assertSame('10 جمادى الآخرة 1448 هـ', $this->d->format($instant, 'hijri', 'Asia/Riyadh'));
        $this->assertSame('20 نوفمبر 2026', $this->d->format($instant, 'gregorian', 'Asia/Riyadh'));
        $this->assertSame('10 جمادى الآخرة 1448 هـ (20 نوفمبر 2026)', $this->d->format($instant, 'both_hijri_first', 'Asia/Riyadh'));
        $this->assertSame('20 نوفمبر 2026 (10 جمادى الآخرة 1448 هـ)', $this->d->format($instant, 'both_gregorian_first', 'Asia/Riyadh'));
        $this->assertSame('١٠ جمادى الآخرة ١٤٤٨ هـ', $this->d->format($instant, 'hijri', 'Asia/Riyadh', 'ar', 'eastern'));
        $this->assertSame('10 Jumada II 1448 AH', $this->d->format($instant, 'hijri', 'Asia/Riyadh', 'en'));
    }

    public function test_req_0350_storage_is_always_utc_whatever_the_input_timezone(): void
    {
        $u = $this->d->toUtc('2026-11-20 02:00:00', 'Asia/Riyadh');
        $this->assertSame('UTC', $u->getTimezone()->getName());
        $this->assertSame('2026-11-19 23:00:00', $u->format('Y-m-d H:i:s'));
    }

    public function test_req_0350_the_local_day_decides_the_hijri_date_shown(): void
    {
        // 22:30 UTC on Nov 20 is already Nov 21 in Riyadh — the Hijri day follows the subscriber's timezone.
        $instant = CarbonImmutable::parse('2026-11-20 22:30:00', 'UTC');
        $this->assertSame(11, $this->d->hijriParts($instant, 'Asia/Riyadh')['day']);
        $this->assertSame(10, $this->d->hijriParts($instant, 'UTC')['day']);
    }

    public function test_req_0351_einvoice_date_is_gregorian(): void
    {
        $this->assertSame('2026-11-20', $this->d->eInvoiceDate(CarbonImmutable::parse('2026-11-20 12:00', 'UTC'), 'Asia/Riyadh'));
    }

    public function test_req_0352_deadlines_are_computed_on_the_stored_value_regardless_of_display(): void
    {
        $end = CarbonImmutable::parse('2027-03-31 00:00:00', 'UTC');
        $notice = $this->d->addDays($end, -60);
        $this->assertSame('2027-01-30 00:00:00', $notice->format('Y-m-d H:i:s'));
        foreach (['hijri', 'gregorian', 'both_hijri_first'] as $mode) {
            $this->d->format($notice, $mode, 'Asia/Riyadh');
            $this->assertSame('2027-01-30 00:00:00', $notice->format('Y-m-d H:i:s'));
        }
    }

    public function test_req_0656_the_correction_setting_is_not_applied_by_the_date_api(): void
    {
        // Decision 13: no correction logic until specified — the API has no correction parameter at all.
        $ref = new \ReflectionClass(DateService::class);
        foreach ($ref->getMethods() as $m) {
            foreach ($m->getParameters() as $p) {
                $this->assertStringNotContainsStringIgnoringCase('correction', $p->getName());
            }
        }
        $this->assertStringNotContainsString('hijri_correction', file_get_contents($ref->getFileName()) === false ? '' : preg_replace('#/\*.*?\*/#s', '', file_get_contents($ref->getFileName())));
    }
}
