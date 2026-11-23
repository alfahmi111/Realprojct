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

    public function test_req_0656_no_global_offset_is_applied_by_the_date_api(): void
    {
        // Q19 (2026-11-23): corrections are per day, entered by the platform admin. A global offset is not applied:
        // the previous system treated a non-zero offset as untrusted, so the subscriber offset setting is never read.
        $ref = new \ReflectionClass(DateService::class);
        $code = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($ref->getFileName()));
        $this->assertStringNotContainsString('date.hijri_correction', $code);
        $this->assertStringNotContainsString('Settings', $code);
    }
    /** REQ-0345 — the anchors of the owner's previous system are reproduced, so the server is trusted to show Hijri. */
    public function test_req_0345_umm_al_qura_anchors_from_the_previous_system_are_reproduced(): void
    {
        foreach (DateService::HIJRI_ANCHORS as $g => [$y, $m, $day]) {
            $this->assertSame(['year' => $y, 'month' => $m, 'day' => $day], $this->d->hijriOfDate($g), $g);
        }
        $this->assertTrue($this->d->hijriTrusted());
    }

    /** REQ-0350 — a calendar date (lease end, due date) never slips a day whatever the office timezone. */
    public function test_req_0350_calendar_date_does_not_slip_with_the_timezone(): void
    {
        $this->assertSame(['year' => 1448, 'month' => 6, 'day' => 13], $this->d->hijriOfDate('2026-11-23'));
        $this->assertSame($this->d->formatDate('2026-11-23', 'hijri'), $this->d->formatDate(CarbonImmutable::parse('2026-11-23', 'America/New_York'), 'hijri'));
        $this->assertStringContainsString('13 جمادى الآخرة 1448', $this->d->formatDate('2026-11-23', 'hijri'));
    }

    /** REQ-0656 — one API: every day for twenty years converts Gregorian → Hijri → Gregorian back to itself. */
    public function test_req_0656_round_trip_is_exact_for_every_day_2015_to_2035(): void
    {
        for ($day = CarbonImmutable::parse('2015-01-01'); $day->year < 2036; $day = $day->addDay()) {
            $h = $this->d->hijriOfDate($day);
            $this->assertSame($day->toDateString(), $this->d->hijriToGregorian($h['year'], $h['month'], $h['day']));
        }
    }

    /** REQ-0345 — browser and server must agree on a posted Hijri date, or nothing is saved (previous system's cross-check). */
    public function test_req_0345_browser_and_server_hijri_must_agree(): void
    {
        $this->d->crossCheck('2026-11-23', '1448-06-13');
        $this->d->crossCheck('2026-11-23', '');   // nothing posted: nothing compared
        $this->expectException(\App\Support\Dates\HijriMismatchException::class);
        $this->d->crossCheck('2026-11-23', '1448-06-12');
    }
}