<?php

namespace Tests\Feature\Maintenance;

use App\Dashboards\HomeOverview;
use App\Filament\Manager\Resources\Assets\AssetResource;
use App\Leasing\LeasingService;
use App\Maintenance\AssetValuation;
use App\Models\Asset;
use App\Models\Building;
use App\Models\LeasableUnit;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Space;
use App\Models\Tenant;
use App\Models\User;
use App\Properties\BuildingWizard;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Asset value and building outlook (owner decisions 2026-11-23 on REQ-0211 cost + useful life, REQ-0262 replacement
 * budget and inventory completion, REQ-0294, REQ-0397): straight-line depreciation by rate, yearly amount or useful
 * life with optional salvage; nothing guessed for incomplete assets; the building contents page; the home outlook over
 * 90 / 60 / 30 days; the optional property photo served only through RLS.
 */
class AssetValueAndOutlookTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Property $p;
    private Building $b;
    private Space $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->makeSubscriber();
        $this->manager = $this->makeUser($s, 'manager');
        $this->tenant()->set($s->id);
        $this->manager->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $w = app(BuildingWizard::class);
        $o = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك']);
        $this->p = $w->createProperty(Portfolio::create(['name_ar' => 'م']), ['name_ar' => 'عمارة الريم'], [['owner' => $o, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $w->generate($this->p, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 4]]]]]);
        $this->b = Building::where('property_id', $this->p->id)->first();
        $this->unit = Space::where('code', '101')->first();
        Filament::setCurrentPanel(Filament::getPanel('manager'));
    }

    private function asset(array $a): Asset
    {
        return Asset::create($a + ['space_id' => $this->unit->id, 'qr_code' => 'A-' . uniqid(), 'category' => 'hvac', 'type' => 'split_ac', 'name_ar' => 'مكيف']);
    }

    public function test_req_0211_straight_line_by_yearly_rate(): void
    {
        $on = CarbonImmutable::parse('2026-01-01');
        $a = $this->asset(['cost_minor' => Money::parse('3000'), 'installed_on' => '2021-01-01', 'depreciation_method' => 'percent', 'depreciation_rate_bp' => 1000]);
        $v = app(AssetValuation::class)->value($a, $on);
        $this->assertSame(30000, $v['yearly']);                       // 10 % of 3,000.00 = 300.00
        $this->assertSame(120, $v['life_months']);                    // 10 years
        $this->assertSame('2031-01-01', $v['ends_on']->toDateString());
        $this->assertSame(intdiv(30000 * 1826, 365), $v['depreciated']); // 1,826 days in service
        $this->assertSame(300000 - $v['depreciated'], $v['book']);
        $this->assertSame('ok', $v['state']);
        $this->assertIsInt($v['book']);
    }

    public function test_req_0211_yearly_amount_with_salvage_and_end_of_service(): void
    {
        $a = $this->asset(['cost_minor' => Money::parse('1000'), 'installed_on' => '2020-01-01', 'depreciation_method' => 'amount', 'depreciation_annual_minor' => 20000, 'salvage_minor' => 20000]);
        $v = app(AssetValuation::class)->value($a, CarbonImmutable::parse('2026-01-01'));
        $this->assertSame(48, $v['life_months']);                      // (1,000 − 200) ÷ 200 a year = 4 years
        $this->assertSame(80000, $v['depreciated']);                   // capped at cost − salvage
        $this->assertSame(20000, $v['book']);                          // salvage remains
        $this->assertSame(10000, $v['pct_bp']);
        $this->assertSame('ended', $v['state']);
    }

    public function test_req_0211_useful_life_basis_near_end_and_incomplete_is_not_guessed(): void
    {
        $svc = app(AssetValuation::class);
        $near = $this->asset(['cost_minor' => Money::parse('850'), 'installed_on' => '2018-06-01', 'useful_life_months' => 96]);
        $this->assertSame('near', $svc->value($near, CarbonImmutable::parse('2026-01-01'))['state']);   // ends 2026-06
        $noCost = $this->asset(['installed_on' => '2020-01-01', 'useful_life_months' => 96]);
        $noDate = $this->asset(['cost_minor' => Money::parse('500'), 'useful_life_months' => 60]);
        $noBasis = $this->asset(['cost_minor' => Money::parse('500'), 'installed_on' => '2020-01-01']);
        foreach ([$noCost, $noDate, $noBasis] as $a) {
            $v = $svc->value($a, CarbonImmutable::parse('2026-01-01'));
            $this->assertSame('incomplete', $v['state']);
            $this->assertNull($v['book']);
        }
    }

    public function test_req_0262_building_contents_totals_budget_and_inventory(): void
    {
        $on = CarbonImmutable::parse('2026-01-01');
        $this->asset(['cost_minor' => Money::parse('2400'), 'installed_on' => '2017-01-01', 'depreciation_method' => 'percent', 'depreciation_rate_bp' => 1000, 'condition' => 2]);   // ends 2027
        $this->asset(['cost_minor' => Money::parse('6000'), 'installed_on' => '2025-01-01', 'useful_life_months' => 60, 'condition' => 4]);                                      // ends 2030
        $this->asset(['name_ar' => 'بلا تكلفة', 'installed_on' => '2025-01-01', 'useful_life_months' => 60]);
        $d = app(AssetValuation::class)->building($this->b->id, $this->p->id, $on);
        $this->assertSame(3, $d['totals']['count']);
        $this->assertSame(1, $d['totals']['incomplete']);
        $this->assertSame(1, $d['totals']['near']);
        $this->assertSame(240000, $d['budget'][2027]);
        $this->assertSame(600000, $d['budget'][2030]);
        $this->assertSame(30, $d['totals']['condition_avg10']);       // (2 + 4) ÷ 2 = 3.0
        $this->assertSame(['units' => 4, 'done' => 0, 'bp' => 0], $d['inventory']);
        $this->assertCount(5, $d['budget']);
    }

    public function test_req_0262_building_contents_page_renders_and_lives_under_maintenance(): void
    {
        $this->asset(['cost_minor' => Money::parse('2400'), 'installed_on' => '2017-01-01', 'depreciation_method' => 'percent', 'depreciation_rate_bp' => 1000]);
        $html = $this->actingAs($this->manager)->get('/manage/building-contents')->assertOk()->getContent();
        foreach (['محتوى عمارة الريم', 'القيمة الحالية', 'الإهلاك السنوي', 'ميزانية الاستبدال المقترحة', 'ما يحتويه المبنى', 'اكتمال جرد التأسيس', 'كل الأصول'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertSame('الصيانة', \App\Filament\Manager\Pages\BuildingContentsPage::getNavigationGroup());
        $tech = $this->makeUser($this->manager->subscriber, 'tenant');
        $this->actingAs($tech)->get('/manage/building-contents')->assertForbidden();
    }

    public function test_req_0211_form_percentage_becomes_basis_points_and_unused_basis_is_cleared(): void
    {
        $data = AssetResource::prepareDepreciation(['depreciation_method' => 'percent', 'depreciation_annual_minor' => 5000], ['depreciation_rate_pct' => '12.5']);
        $this->assertSame(1250, $data['depreciation_rate_bp']);
        $this->assertNull($data['depreciation_annual_minor']);
        $data = AssetResource::prepareDepreciation(['depreciation_method' => 'amount', 'depreciation_annual_minor' => 5000], ['depreciation_rate_pct' => '10']);
        $this->assertNull($data['depreciation_rate_bp']);
        $this->assertSame(5000, $data['depreciation_annual_minor']);
    }

    public function test_req_0397_outlook_over_90_60_30_days_and_home_panel(): void
    {
        $ls = app(LeasingService::class);
        $today = CarbonImmutable::today();
        $lease = function (string $code, CarbonImmutable $start) use ($ls) {
            $o = $ls->createOffer($this->manager, LeasableUnit::where('code', $code)->first(), Tenant::create(['kind' => 'individual', 'name_ar' => 'م' . $code]),
                ['usage' => 'residential', 'starts_on' => $start->toDateString(), 'duration_unit' => 'year', 'duration_count' => 1, 'annual_rent_minor' => Money::parse('40000'), 'payment_frequency' => 'annual']);
            $l = $ls->acceptOffer($ls->sendOffer($ls->submitOffer($o)));
            $ls->sendForRegistration($l);
            $ls->markAwaitingParties($l);
            $ls->register($l, 'EJ-' . $code);
            $ls->activate($l);
            return $l->fresh();
        };
        $ending = $lease('101', $today->subYear()->addDays(76));     // ends in ~75 days; notice still allowed (60-day rule)
        $lease('102', $today->subMonths(2));                          // ends in ~10 months
        $ls->giveNotice($ending, 'tenant', $today);
        $o = app(HomeOverview::class)->buildingOutlook($this->b->id, $today);
        $this->assertSame(4, $o['total']);
        $this->assertSame(2, $o['leased']);
        $this->assertSame(5000, $o['occupancy_bp']);
        $this->assertSame([90, 60, 30], array_keys($o['windows']));
        $this->assertSame(1, $o['windows'][90]['vacating']);
        $this->assertSame(0, $o['windows'][60]['vacating']);
        $this->assertSame(1, $o['windows'][60]['staying'] - 1);
        $this->assertSame(0, $o['windows'][30]['vacating']);
        $this->assertSame(2, $o['windows'][30]['staying']);
        $html = $this->actingAs($this->manager)->get('/manage')->assertOk()->getContent();
        foreach (['نظرة على عمارة الريم', 'نسبة الإشغال الحالية', 'خلال 90 يوماً', 'خلال 30 يوماً', 'سيشغر', 'صُرف على صيانته هذا العام', 'إهلاك أصوله السنوي'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
    }

    public function test_owner_request_property_photo_is_stored_privately_and_served_only_within_the_subscriber(): void
    {
        Storage::fake('local');
        $path = UploadedFile::fake()->image('b.jpg', 40, 40)->store('properties/' . $this->manager->subscriber_id, 'local');
        $this->p->forceFill(['photo_path' => $path])->save();
        $this->assertStringContainsString('/media/property/' . $this->p->id . '/photo', $this->p->photoUrl());
        $this->actingAs($this->manager)->get($this->p->photoUrl())->assertOk();
        $this->get('/logout');
        $other = $this->makeSubscriber('office', 'مكتب آخر');
        $stranger = $this->makeUser($other, 'manager');
        $this->tenant()->set($other->id);
        $this->actingAs($stranger)->get('/media/property/' . $this->p->id . '/photo')->assertNotFound();   // RLS hides it
    }
}
