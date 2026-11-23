<?php

namespace Tests\Feature\Screens;

use App\Dashboards\PortfolioTree;
use App\Leasing\LeasingService;
use App\Models\LeasableUnit;
use App\Models\Lease;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Properties\BuildingWizard;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Units and leases as a tree with quick search, the lease shown like an Ejar contract, and scheduled payments with
 * property and tenant (owner requests 2026-11-23; REQ-0072, REQ-0182, REQ-0330, REQ-0494).
 */
class TreesAndContractViewTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Property $p;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->makeSubscriber();
        $this->manager = $this->makeUser($s, 'manager');
        $this->tenant()->set($s->id);
        $this->manager->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $w = app(BuildingWizard::class);
        $o = Owner::create(['kind' => 'individual', 'name_ar' => 'سعد المالك', 'national_id' => '1000000001']);
        $this->p = $w->createProperty(Portfolio::create(['name_ar' => 'م']), ['name_ar' => 'عمارة السلام', 'deed_number' => '3101'], [['owner' => $o, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $w->generate($this->p, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 2]]], ['number' => 2, 'units' => [['type' => 'apartment', 'count' => 1]]]]]);
        $w->setPrice(LeasableUnit::where('code', '102')->first(), 'year', Money::parse('45000'));
        $ls = app(LeasingService::class);
        $t = Tenant::create(['kind' => 'individual', 'name_ar' => 'خالد المستأجر', 'phone' => '966500000123', 'national_id' => '1016860767']);
        $o2 = $ls->createOffer($this->manager, LeasableUnit::where('code', '101')->first(), $t, ['usage' => 'residential', 'starts_on' => now()->subMonths(2)->toDateString(), 'duration_unit' => 'year', 'duration_count' => 1, 'annual_rent_minor' => Money::parse('70200'), 'payment_frequency' => 'quarterly', 'deposit_minor' => Money::parse('3000')]);
        $this->lease = $ls->acceptOffer($ls->sendOffer($ls->submitOffer($o2)));
        $ls->sendForRegistration($this->lease);
        $ls->markAwaitingParties($this->lease);
        $ls->register($this->lease, 'EJ-10291961792');
        $ls->activate($this->lease);
        Filament::setCurrentPanel(Filament::getPanel('manager'));
    }

    public function test_req_0072_portfolio_tree_property_floor_unit_with_lease_and_search(): void
    {
        $svc = app(PortfolioTree::class);
        $tree = $svc->build([$this->p->id], CarbonImmutable::today());
        $this->assertSame('عمارة السلام', $tree[0]['name']);
        $floors = $tree[0]['buildings'][0]['floors'];
        $this->assertCount(2, $floors);
        $u101 = collect($floors)->flatMap(fn ($f) => $f['units'])->firstWhere('code', '101');
        $this->assertSame('خالد المستأجر', $u101['lease']['tenant']);
        $this->assertSame('paid', $u101['status']);
        $u102 = collect($floors)->flatMap(fn ($f) => $f['units'])->firstWhere('code', '102');
        $this->assertNull($u102['lease']);
        $this->assertSame(4500000, $u102['asking']);
        $this->assertSame(1, $tree[0]['counts']['leased']);
        // search by tenant, phone or Ejar number narrows to that unit
        foreach (['خالد', '0000123', '10291961792'] as $q) {
            $hit = $svc->build([$this->p->id], CarbonImmutable::today(), $q);
            $codes = collect($hit[0]['buildings'][0]['floors'])->flatMap(fn ($f) => $f['units'])->pluck('code')->all();
            $this->assertSame(['101'], $codes, $q);
        }
        $this->assertSame([], $svc->build([$this->p->id], CarbonImmutable::today(), 'لا يوجد'));
    }

    public function test_req_0182_units_and_leases_pages_render_as_trees(): void
    {
        $this->actingAs($this->manager);
        $units = $this->get('/manage/leasable-units')->assertOk()->getContent();
        foreach (['الوحدات التأجيرية', 'عمارة السلام', 'خالد المستأجر', 'السعر المطلوب سنوياً', 'إدارة الوحدات'] as $n) {
            $this->assertStringContainsString($n, $units, $n);
        }
        $leases = $this->get('/manage/leases')->assertOk()->getContent();
        foreach (['العقود', 'عمارة السلام', 'خالد المستأجر', 'EJ-10291961792', 'عرض تأجير', 'كل العقود والإجراءات'] as $n) {
            $this->assertStringContainsString($n, $leases, $n);
        }
    }

    public function test_req_0182_lease_shown_like_an_ejar_contract(): void
    {
        $html = $this->actingAs($this->manager)->get('/manage/leases/' . $this->lease->id)->assertOk()->getContent();
        foreach (['بيانات العقد', 'المؤجر', 'سعد المالك', 'ممثل المؤجر', 'المستأجر', '1016860767', 'العقار ومستند الملكية', '3101', 'الوحدة الإيجارية',
            'البيانات المالية', 'ربع سنوي', 'مبلغ الضمان', 'إجمالي قيمة العقد', 'جدول سداد الدفعات', 'تاريخ الاستحقاق (هـ)', 'آخر موعد لإشعار عدم التجديد', 'EJ-10291961792'] as $n) {
            $this->assertStringContainsString($n, $html, $n);
        }
        $this->assertSame(4, substr_count($html, 'يوماً<small class="wq-sub">'));   // four quarterly lines in the schedule
    }

    public function test_owner_request_scheduled_payments_show_property_and_tenant(): void
    {
        $html = $this->actingAs($this->manager)->get('/manage/billing-schedule-lines')->assertOk()->getContent();
        foreach (['عمارة السلام', 'خالد المستأجر', 'المتبقي', 'الاستحقاق'] as $n) {
            $this->assertStringContainsString($n, $html, $n);
        }
    }
}
