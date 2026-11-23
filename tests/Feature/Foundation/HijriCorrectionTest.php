<?php

namespace Tests\Feature\Foundation;

use App\Filament\Platform\Pages\HijriCorrectionsPage;
use App\Models\AuditLog;
use App\Models\HijriCorrection;
use App\Models\User;
use App\Support\Dates\DateService;
use App\Support\Dates\HijriCorrections;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * REQ-0346, REQ-0656 — manual Hijri correction (Q19, owner's decision 2026-11-23, amending decision 13; taken from the
 * owner's previous system): per-day corrections by the platform admin, ±1 day, applied everywhere, audited.
 */
class HijriCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private DateService $d;

    protected function setUp(): void
    {
        parent::setUp();
        DateService::forgetCorrections();
        $this->d = app(DateService::class);
    }

    protected function tearDown(): void
    {
        DateService::forgetCorrections();
        parent::tearDown();
    }

    public function test_req_0346_a_correction_is_one_day_either_side_including_month_ends(): void
    {
        $h = fn ($y, $m, $d) => ['year' => $y, 'month' => $m, 'day' => $d];
        $this->assertTrue(DateService::isOneDayShift($h(1448, 6, 13), $h(1448, 6, 12)));
        $this->assertTrue(DateService::isOneDayShift($h(1448, 6, 13), $h(1448, 6, 14)));
        $this->assertTrue(DateService::isOneDayShift($h(1448, 8, 29), $h(1448, 9, 1)));   // Ramadan a day early
        $this->assertTrue(DateService::isOneDayShift($h(1448, 9, 1), $h(1448, 8, 30)));   // Sha'ban ran 30 days
        $this->assertTrue(DateService::isOneDayShift($h(1448, 12, 30), $h(1449, 1, 1)));  // across the year end
        $this->assertFalse(DateService::isOneDayShift($h(1448, 6, 13), $h(1448, 6, 15)));
        $this->assertFalse(DateService::isOneDayShift($h(1448, 6, 13), $h(1448, 7, 13)));
        $this->assertFalse(DateService::isOneDayShift($h(1448, 8, 27), $h(1448, 9, 1)));
    }

    public function test_req_0656_a_correction_wins_everywhere_and_removing_it_restores_the_calculation(): void
    {
        $svc = app(HijriCorrections::class);
        // The first day of Rajab 1448 by the calculation; a sighting says Jumada al-Akhirah ran one day longer.
        $first = $this->d->hijriToGregorian(1448, 7, 1);
        $before = $this->d->calculatedHijriOfDate(CarbonImmutable::parse($first)->subDay());
        $this->assertSame(1448, $before['year']);
        if ($before['day'] !== 29) {
            $this->markTestSkipped('Jumada al-Akhirah 1448 already has 30 days in the calculation.');
        }

        $row = $svc->record($first, 1448, 6, 30, 'إعلان رؤية');
        $this->assertSame('1448-07-01', $row->calculated_hijri);
        $this->assertSame(['year' => 1448, 'month' => 6, 'day' => 30], $this->d->hijriOfDate($first));
        $this->assertSame(['year' => 1448, 'month' => 6, 'day' => 30], $this->d->hijriParts(CarbonImmutable::parse($first . ' 09:00', 'Asia/Riyadh'), 'Asia/Riyadh'));
        $this->assertSame($first, $this->d->hijriToGregorian(1448, 6, 30));
        $this->assertStringStartsWith('30 ', $this->d->formatDate($first, 'hijri'));
        $this->assertStringContainsString('1448 هـ', $this->d->formatDate($first, 'hijri'));
        $this->assertTrue($this->d->hijriTrusted(), 'anchors use the calculation, never a correction');
        $this->assertSame(1, AuditLog::where('action', 'hijri.correction.saved')->count());

        $svc->remove($row->fresh());
        $this->assertSame(['year' => 1448, 'month' => 7, 'day' => 1], $this->d->hijriOfDate($first));
        $this->assertSame(1, AuditLog::where('action', 'hijri.correction.removed')->count());
        $this->assertSame(0, HijriCorrection::count());
    }

    public function test_req_0346_corrections_that_are_not_needed_or_too_far_are_refused(): void
    {
        $svc = app(HijriCorrections::class);
        $h = $this->d->calculatedHijriOfDate('2026-11-23');
        try {
            $svc->record('2026-11-23', $h['year'], $h['month'], $h['day']);
            $this->fail('same as the calculation must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('لا حاجة لتصحيح', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $svc->record('2026-11-23', $h['year'], $h['month'], $h['day'] + 2);
    }

    public function test_req_0346_platform_admin_records_a_correction_from_the_screen(): void
    {
        $this->tenant()->clear();
        $this->tenant()->setPlatform(true);
        $admin = User::factory()->create(['subscriber_id' => null, 'role' => 'platform_admin']);
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs($admin)->get('/platform/hijri-corrections')->assertOk()->assertSee('تصحيح التاريخ الهجري');

        $h = $this->d->calculatedHijriOfDate('2026-11-23');
        Livewire::actingAs($admin)->test(HijriCorrectionsPage::class)
            ->fillForm(['gregorian' => '2026-11-23', 'day' => $h['day'] - 1, 'month' => $h['month'], 'year' => $h['year'], 'note' => 'اختبار'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame($h['day'] - 1, HijriCorrection::firstOrFail()->hijri_day);
        $this->assertSame($admin->id, HijriCorrection::firstOrFail()->created_by);
    }
}
