<?php

namespace Tests\Feature\Screens;

use App\Filament\Manager\Pages\AddBuildingWizard;
use App\Filament\Manager\Resources\Owners\RelationManagers\AgreementsRelationManager;
use App\Integrations\NationalAddress\NationalAddressGateway;
use App\Leasing\LeasingService;
use App\Migration\ExcelTemplate;
use App\Migration\QuickMigration;
use App\Models\Announcement;
use App\Models\LeasableUnit;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Properties\BuildingWizard;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * M17 — the owner's review of 2026-11-24: owner decides maintenance approval (REQ-0228), Excel template for quick
 * migration (REQ-0122), circulars with media (REQ-0278), tenants tree, availability across properties (REQ-0178),
 * properties by city with a map, location on a map with the national short address (REQ-0448), portfolio per client
 * (Q32), office logo in the setup steps (REQ-0315).
 */
class OwnerFeedbackM17Test extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Property $a;
    private Property $b;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->makeSubscriber();
        $this->manager = $this->makeUser($s, 'manager');
        $this->tenant()->set($s->id);
        $this->manager->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $w = app(BuildingWizard::class);
        $o = Owner::create(['kind' => 'individual', 'name_ar' => 'سعد المالك']);
        $this->a = $w->createProperty(Portfolio::create(['name_ar' => 'م']), ['name_ar' => 'عمارة الرياض', 'city' => 'الرياض', 'latitude' => '24.7136000', 'longitude' => '46.6753000'], [['owner' => $o, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $this->b = $w->createProperty(Portfolio::create(['name_ar' => 'ن']), ['name_ar' => 'برج جدة', 'city' => 'جدة'], [['owner' => $o, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $w->generate($this->a, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 2]]]]]);
        $w->generate($this->b, ['name_ar' => 'B', 'code_prefix' => 'J', 'floors' => [['number' => 1, 'units' => [['type' => 'office', 'count' => 1]]]]]);
        Filament::setCurrentPanel(Filament::getPanel('manager'));
    }

    public function test_req_0228_owner_approval_rule_all_up_to_or_never(): void
    {
        $this->assertSame(0, AgreementsRelationManager::cap(['approval_rule' => 'all']));
        $this->assertSame(150000, AgreementsRelationManager::cap(['approval_rule' => 'up_to', 'spend_cap_minor' => 150000]));
        $this->assertNull(AgreementsRelationManager::cap(['approval_rule' => 'none', 'spend_cap_minor' => 150000]));
        $this->assertSame('كل صرف بموافقته', AgreementsRelationManager::ruleText(Money::of(0)));
        $this->assertSame('لا يشترط موافقته', AgreementsRelationManager::ruleText(null));
        $this->assertStringContainsString('بموافقته فوق', AgreementsRelationManager::ruleText(Money::of(150000)));
    }

    public function test_req_0122_excel_template_round_trip_one_batch_per_contract(): void
    {
        $t = app(ExcelTemplate::class);
        $path = $t->build(sys_get_temp_dir() . '/m17-template.xlsx');
        $rows = $t->parse($path);
        $f = collect($rows[0]['fields'])->keyBy('field');
        $this->assertSame('quarterly', $f['payment_frequency']['value']);
        $this->assertSame('residential', $f['usage']['value']);
        $this->assertSame(100, $f['annual_rent']['confidence']);
        // a CSV in the same columns, with a missing required value and an unknown choice flagged at confidence 0
        $csv = sys_get_temp_dir() . '/m17.csv';
        $head = array_column(array_values(ExcelTemplate::COLUMNS), 0);
        file_put_contents($csv, "\xEF\xBB\xBF" . implode(',', $head) . "\n" . implode(',', ['1', 'خالد', 'فرد', '', '', '101', 'سكني', '2026-01-01', '2026-12-31', '', 'كل أسبوع', '']) . "\n" . implode(',', ['2', 'نورة', 'منشأة', '', '', '102', 'تجاري', '2026-02-01', '2027-01-31', '60000', 'سنوي', '']) . "\n");
        $parsed = $t->parse($csv);
        $this->assertCount(2, $parsed);
        $first = collect($parsed[0]['fields'])->keyBy('field');
        $this->assertSame(0, $first['annual_rent']['confidence']);
        $this->assertSame(0, $first['payment_frequency']['confidence']);
        $batches = app(QuickMigration::class)->stageTemplate($this->manager, $csv);
        $this->assertCount(2, $batches);
        $this->expectException(\App\Migration\MigrationException::class);
        file_put_contents($csv, "عمود,آخر\n1,2\n");
        $t->parse($csv);
    }

    public function test_req_0278_circular_media_served_only_to_tenants_who_may_see_it(): void
    {
        Storage::fake('local');
        $path = UploadedFile::fake()->image('eid.jpg', 20, 20)->store('announcements/x', 'local');
        $ls = app(LeasingService::class);
        $user = $this->makeUser($this->manager->subscriber, 'tenant');
        $t = Tenant::create(['kind' => 'individual', 'name_ar' => 'م', 'user_id' => $user->id]);
        $o = $ls->createOffer($this->manager, LeasableUnit::where('code', '101')->first(), $t, ['usage' => 'residential', 'starts_on' => now()->subMonth()->toDateString(), 'duration_unit' => 'year', 'duration_count' => 1, 'annual_rent_minor' => Money::parse('30000'), 'payment_frequency' => 'annual']);
        $lease = $ls->acceptOffer($ls->sendOffer($ls->submitOffer($o)));
        $ls->sendForRegistration($lease);
        $ls->markAwaitingParties($lease);
        $ls->register($lease, 'EJ-C1');
        $ls->activate($lease);
        $mine = Announcement::create(['title_ar' => 'عيد مبارك', 'body_ar' => 'تهنئة', 'published_at' => now()->subHour(), 'attachments' => [$path]]);
        $other = Announcement::create(['title_ar' => 'لبرج جدة', 'body_ar' => 'x', 'published_at' => now()->subHour(), 'attachments' => [$path],
            'building_ids' => [\App\Models\Building::where('property_id', $this->b->id)->value('id')]]);
        $this->actingAs($user)->get(route('media.announcement', [$mine->id, 0]))->assertOk();
        $this->actingAs($user)->get(route('media.announcement', [$other->id, 0]))->assertForbidden();
        $this->actingAs($this->manager)->get('/manage/announcements')->assertOk()->assertSee('تعاميم للمستأجرين');
    }

    public function test_req_0178_availability_shows_all_properties_grouped(): void
    {
        $html = $this->actingAs($this->manager)->get('/manage/availability')->assertOk()->getContent();
        foreach (['كل العقارات', 'wq-tl-group', 'عمارة الرياض', 'برج جدة', 'J1'] as $n) {
            $this->assertStringContainsString($n, $html, $n);
        }
    }

    public function test_m17_properties_by_city_sorted_with_portfolio_map_and_tenants_tree(): void
    {
        $this->actingAs($this->manager);
        \Livewire\Livewire::test(\App\Filament\Manager\Resources\Properties\Pages\ListProperties::class)
            ->set('group', 'city')->assertSee('wq-citygroup', false)->assertSee('الرياض')->assertSee('جدة')
            ->assertSee('خريطة العقارات')->assertSee('1 على الخريطة')->assertSee('بلا موقع محدد');
        $cards = (new \App\Filament\Manager\Resources\Properties\Pages\ListProperties())->cards();
        $this->assertSame(['برج جدة', 'عمارة الرياض'], array_column($cards, 'name'));   // sorted by name
        $this->get('/manage/tenants')->assertOk()->assertSee('حسب المبنى')->assertSee('عرض الكل');
    }

    public function test_req_0448_national_address_simulator_and_refusal_without_spl(): void
    {
        config(['integrations.national_address' => 'simulator']);
        $this->app->forgetInstance(NationalAddressGateway::class);
        $sim = new \App\Integrations\NationalAddress\NationalAddressSimulator();
        $a = $sim->byCoordinates(21.5432870, 39.1727600);
        $this->assertMatchesRegularExpression('/^[A-Z]{4}\d{4}$/', $a['short']);
        $this->assertSame('جدة', $a['city']);
        $this->assertSame($a['short'], $sim->byCoordinates(21.5432870, 39.1727600)['short']);   // stable
        $this->expectException(\App\Integrations\IntegrationNotConfigured::class);
        (new \App\Integrations\NationalAddress\UnconfiguredNationalAddress())->byCoordinates(21.5, 39.1);
    }

    public function test_q32_wizard_creates_a_portfolio_per_owner_when_none_is_chosen(): void
    {
        $o = Owner::create(['kind' => 'individual', 'name_ar' => 'عائلة الشهري']);
        $p1 = AddBuildingWizard::portfolioFor(['owners' => [['owner_id' => $o->id]]]);
        $this->assertSame('محفظة عائلة الشهري', $p1->name_ar);
        $this->assertSame($o->id, $p1->owner_id);
        $this->assertSame($p1->id, AddBuildingWizard::portfolioFor(['owners' => [['owner_id' => $o->id]]])->id);   // reused
        $chosen = Portfolio::create(['name_ar' => 'محفظة مختارة']);
        $this->assertSame($chosen->id, AddBuildingWizard::portfolioFor(['portfolio_id' => $chosen->id, 'owners' => [['owner_id' => $o->id]]])->id);
    }

    /** REQ-0074, Q32 decided 2026-11-24: the office chooses its portfolio mode in its settings. */
    public function test_req_0074_office_chooses_portfolio_mode_in_settings(): void
    {
        $settings = app(\App\Settings\Settings::class);
        $this->assertSame('per_owner', $settings->get('properties.portfolio_mode'));
        $o = Owner::create(['kind' => 'company', 'name_ar' => 'شركة الأفق']);
        $settings->setSubscriber('properties.portfolio_mode', 'office');
        $office = AddBuildingWizard::portfolioFor(['owners' => [['owner_id' => $o->id]]]);
        $this->assertSame('محفظة ' . $this->manager->subscriber->name_ar, $office->name_ar);
        $this->assertNull($office->owner_id);
        $other = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك آخر']);
        $this->assertSame($office->id, AddBuildingWizard::portfolioFor(['owners' => [['owner_id' => $other->id]]])->id);   // one for the whole office
        $html = $this->actingAs($this->manager)->get('/manage/subscriber-settings')->assertOk()->getContent();
        foreach (['كيف تنظّم محافظك؟', 'محفظة لكل عميل (مالك)', 'محفظة عامة واحدة للمكتب', 'أختار المحفظة عند إضافة كل عقار'] as $n) {
            $this->assertStringContainsString($n, $html, $n);
        }
        $this->assertStringNotContainsString('اجلب العنوان من الموقع', $this->get('/manage/properties/' . $this->a->id . '/edit')->getContent());   // SPL deferred (Q34)
    }

    public function test_req_0315_setup_steps_include_office_logo_and_official_data(): void
    {
        $steps = collect(app(\App\Dashboards\HomeOverview::class)->setupSteps($this->manager))->keyBy('key');
        $this->assertFalse($steps['identity']['done']);
        app(\App\Settings\Settings::class)->setSubscriber('theme.logo_light', 'branding/' . $this->manager->subscriber_id . '/logo.png');
        $steps = collect(app(\App\Dashboards\HomeOverview::class)->setupSteps($this->manager))->keyBy('key');
        $this->assertTrue($steps['identity']['done']);
    }
}
