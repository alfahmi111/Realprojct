<?php

namespace Tests\Feature\BuildingQr;

use App\BuildingQr\BuildingQrException;
use App\BuildingQr\BuildingQrService;
use App\Leasing\LeasingService;
use App\Models\Building;
use App\Models\BuildingQr;
use App\Models\LeasableUnit;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\Prospect;
use App\Models\Tenant;
use App\Models\User;
use App\Properties\BuildingWizard;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REQ-0064 (prospect: anyone who scanned the building QR).
 * Spec §8 building QR, free version. Covered IDs:
 * REQ-0377, REQ-0378, REQ-0379, REQ-0380, REQ-0381, REQ-0388, REQ-0389, REQ-0391, REQ-0392, REQ-0393, REQ-0394, REQ-0395,
 * REQ-0396, REQ-0397, REQ-0398, REQ-0399, REQ-0404, REQ-0405, REQ-0406, REQ-0407, REQ-0410, REQ-0147, REQ-0148, REQ-0156,
 * REQ-0293, REQ-0299, REQ-0309, REQ-0440, REQ-0444, REQ-0452, REQ-0453, REQ-0585, REQ-0645.
 */
class BuildingQrTest extends TestCase
{
    use RefreshDatabase;

    private BuildingQrService $svc;
    private User $manager;
    private User $ownerUser;
    private Building $building;
    private BuildingQr $qr;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->makeSubscriber('office', 'مكتب النخبة');
        $this->manager = $this->makeUser($s, 'manager');
        $this->ownerUser = $this->makeUser($s, 'owner');
        $this->tenant()->set($s->id);
        $w = app(BuildingWizard::class);
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك', 'user_id' => $this->ownerUser->id]);
        $p = $w->createProperty(Portfolio::create(['name_ar' => 'م']), ['name_ar' => 'عمارة', 'region' => 'makkah', 'city' => 'jeddah'], [['owner' => $owner, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $this->building = $w->generate($p, ['name_ar' => 'عمارة الورد', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 2]]]]]);
        $w->setAttributes(\App\Models\Space::where('code', '101')->first(), ['area_sqm' => '120', 'rooms' => 3, 'bathrooms' => 2, 'furnished' => false, 'electricity_meter_no' => 'E', 'water_account_no' => 'W']);
        $w->setPrice(LeasableUnit::where('code', '101')->first(), 'year', Money::parse('48000'));
        $this->svc = app(BuildingQrService::class);
        $this->qr = $this->svc->create($this->building);
    }

    public function test_req_0393_permanent_short_code_that_never_changes(): void
    {
        $this->assertMatchesRegularExpression('/^[2-9A-HJ-NP-Z]{6}$/', $this->qr->code);
        $this->building->update(['name_ar' => 'اسم جديد']);
        $this->assertSame($this->qr->code, BuildingQr::first()->code);
        $this->expectException(QueryException::class);
        DB::table('qr_codes')->where('code', $this->qr->code)->update(['code' => 'ZZZZZZ']);
    }

    public function test_req_0389_units_are_listed_only_with_a_valid_ad_license_shown_prominently(): void
    {
        $today = CarbonImmutable::parse('2027-01-01');
        $this->assertSame('no_valid_ad_license', $this->svc->page($this->qr, $today)['state']);
        $this->svc->setAdLicense($this->qr, 'AD-7788', CarbonImmutable::parse('2026-12-31'));
        $this->assertSame('no_valid_ad_license', $this->svc->page($this->qr->fresh(), $today)['state']);   // expired
        $this->svc->setAdLicense($this->qr, 'AD-7788', CarbonImmutable::parse('2027-12-31'));
        $page = $this->svc->page($this->qr->fresh(), $today);
        $this->assertSame('live', $page['state']);
        $this->assertSame('AD-7788', $page['ad_license_no']);
        $this->assertSame('مكتب النخبة', $page['publisher_ar']);   // REQ-0452: under the subscriber's name
    }

    public function test_req_0378_vacant_now_and_req_0379_upcoming_by_month_without_tenant_data(): void
    {
        $this->svc->setAdLicense($this->qr, 'AD-1', CarbonImmutable::parse('2028-01-01'));
        $ls = app(LeasingService::class);
        $t = Tenant::create(['kind' => 'individual', 'name_ar' => 'محمد السري']);
        $o = $ls->createOffer($this->manager, LeasableUnit::where('code', '102')->first(), $t, ['usage' => 'residential', 'starts_on' => '2026-06-01', 'duration_unit' => 'year', 'duration_count' => 1, 'annual_rent_minor' => Money::parse('40000'), 'payment_frequency' => 'annual']);
        $lease = $ls->acceptOffer($ls->sendOffer($ls->submitOffer($o)));
        $lease->forceFill(['status' => 'active'])->save();
        $ls->giveNotice($lease, 'tenant', CarbonImmutable::parse('2027-02-01'));

        $page = $this->svc->page($this->qr->fresh(), CarbonImmutable::parse('2027-02-10'));
        $this->assertSame([['code' => '101', 'type' => 'apartment', 'area_sqm' => '120', 'annual_price' => '48000.00']], $page['vacant']);
        $this->assertSame([['code' => '102', 'type' => 'apartment', 'available_from_month' => '2027-06']], $page['upcoming']);
        $this->assertStringNotContainsString('محمد', json_encode($page, JSON_UNESCAPED_UNICODE));   // REQ-0404

        // Live: the unit disappears once leased, returns when it ends (REQ-0381).
        $this->assertCount(1, $page['vacant']);
        $after = $this->svc->page($this->qr->fresh(), CarbonImmutable::parse('2027-06-15'));
        $this->assertCount(2, $after['vacant']);
    }

    public function test_req_0392_riyadh_shows_only_the_last_registered_rent(): void
    {
        $this->building->property->update(['region' => 'riyadh', 'city' => 'riyadh', 'within_urban_boundary' => true]);
        LeasableUnit::where('code', '101')->first()->forceFill(['last_registered_rent_minor' => Money::parse('45000')])->save();
        $this->svc->setAdLicense($this->qr, 'AD-1', CarbonImmutable::parse('2028-01-01'));
        $page = $this->svc->page($this->qr->fresh(), CarbonImmutable::parse('2027-01-01'));
        $this->assertSame('45000.00', $page['vacant'][0]['annual_price']);
    }

    public function test_req_0395_pause_without_removing_the_board(): void
    {
        $this->svc->setAdLicense($this->qr, 'AD-1', CarbonImmutable::parse('2028-01-01'));
        $this->svc->pause($this->qr, true);
        $this->assertSame('paused', $this->svc->page($this->qr->fresh(), CarbonImmutable::parse('2027-01-01'))['state']);
    }

    public function test_req_0394_scans_are_counted_with_their_source_through_the_public_route(): void
    {
        $this->svc->setAdLicense($this->qr, 'AD-1', CarbonImmutable::parse('2099-01-01'));
        $this->tenant()->clear();
        $this->get('/b/' . $this->qr->code . '?src=board')->assertOk()->assertSee('مكتب النخبة')->assertSee('AD-1')->assertSee('dir="rtl"', false);
        $this->get('/b/' . $this->qr->code)->assertOk();
        $this->tenant()->set($this->qr->subscriber_id);
        $this->assertSame(2, (int) BuildingQr::first()->scan_count);
        $this->assertSame(1, DB::table('qr_scans')->where('source', 'board')->count());
        $this->get('/b/AAAAAA')->assertNotFound();
    }

    public function test_req_0380_free_registration_with_explicit_consent_and_req_0309_owner_notified(): void
    {
        $now = CarbonImmutable::parse('2027-01-01 10:00');
        try {
            $this->svc->register($this->qr, ['name' => 'سارة', 'phone' => '0500000000'], false, $now);
            $this->fail('registered without consent');
        } catch (BuildingQrException) {
        }
        $p = $this->svc->register($this->qr, ['name' => 'سارة', 'phone' => '0500000000', 'unit_type' => 'apartment', 'budget_minor' => 4500000, 'desired_month' => '2027-06'], true, $now);
        $this->assertNotSame('سارة', DB::table('prospects')->where('id', $p->id)->value('name'));   // encrypted at rest
        $this->assertSame('سارة', $p->fresh()->name);
        $recipients = DB::table('notifications_outbox')->where('event', 'waitlist.new_prospect')->pluck('recipient_id')->all();
        $this->assertContains($this->manager->id, $recipients);
        $this->assertContains($this->ownerUser->id, $recipients);
        $this->assertSame(['apartment' => 1], $this->svc->waitlistLength($this->qr));   // REQ-0407
        $this->assertSame(0, DB::table('journal_lines')->count());   // no fee charged (decision 3)
    }

    public function test_req_0405_unconfirmed_prospects_are_removed_after_60_days_and_their_data_erased(): void
    {
        $now = CarbonImmutable::parse('2027-01-01');
        $keep = $this->svc->register($this->qr, ['name' => 'أ', 'phone' => '1'], true, $now);
        $drop = $this->svc->register($this->qr, ['name' => 'ب', 'phone' => '2'], true, $now);
        $this->svc->confirmInterest($keep, $now->addDays(50));
        $this->assertSame(1, $this->svc->prune($now->addDays(61)));
        $this->assertSame('removed', $drop->fresh()->status);
        $this->assertSame('—', $drop->fresh()->name);
        $this->assertSame('active', $keep->fresh()->status);
    }

    public function test_req_0380_public_form_registration_through_http(): void
    {
        $this->svc->setAdLicense($this->qr, 'AD-1', CarbonImmutable::parse('2099-01-01'));
        $this->tenant()->clear();
        $this->post('/b/' . $this->qr->code, ['name' => 'خالد', 'phone' => '0555', 'consent' => '1'])->assertRedirect();
        $this->post('/b/' . $this->qr->code, ['name' => 'بلا موافقة', 'phone' => '0556'])->assertSessionHasErrors('consent');
        $this->tenant()->set($this->qr->subscriber_id);
        $this->assertSame(1, Prospect::count());
    }
    /** REQ-0409 (Q15 settled 2026-11-23: threshold 3) — long waitlist while a matching unit is vacant → owner and manager alerted, once a week. */
    public function test_req_0409_owner_is_alerted_when_the_waitlist_is_long_and_a_unit_is_vacant(): void
    {
        $now = CarbonImmutable::parse('2027-01-10 09:00');
        foreach (['أ', 'ب'] as $i => $n) {
            $this->svc->register($this->qr, ['name' => $n, 'phone' => '05000000' . $i, 'unit_type' => 'apartment'], true, $now);
        }
        $this->assertSame(0, $this->svc->alertLongWaitlistWhileVacant($now->startOfDay()));   // 2 < 3: no alert
        $this->svc->register($this->qr, ['name' => 'ج', 'phone' => '0500000009', 'unit_type' => 'apartment'], true, $now);
        $this->assertSame(1, $this->svc->alertLongWaitlistWhileVacant($now->startOfDay()));
        $sent = DB::table('notifications_outbox')->where('event', 'waitlist.long_while_vacant')->pluck('recipient_id')->all();
        $this->assertContains($this->ownerUser->id, $sent);
        $this->assertContains($this->manager->id, $sent);
        $this->assertSame(0, $this->svc->alertLongWaitlistWhileVacant($now->addDays(2)->startOfDay()));   // not again within the week
        $this->assertSame(1, $this->svc->alertLongWaitlistWhileVacant($now->addDays(8)->startOfDay()));
        app(\App\Settings\Settings::class)->setSubscriber('waitlist.long_threshold', 10);   // the office can raise it
        $this->assertSame(0, $this->svc->alertLongWaitlistWhileVacant($now->addDays(20)->startOfDay()));
    }
}