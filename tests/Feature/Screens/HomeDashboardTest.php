<?php

namespace Tests\Feature\Screens;

use App\Dashboards\HomeOverview;
use App\Leasing\LeasingService;
use App\Maintenance\MaintenanceService;
use App\Models\LeasableUnit;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Subscriber;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserScope;
use App\Properties\BuildingWizard;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manager home page (REQ-0281, REQ-0283, REQ-0285, REQ-0286, REQ-0315, REQ-0180, REQ-0330, REQ-0494): vitals, the building
 * facade in the five fixed status colours, attention list, permitted quick actions, renewals near the notice deadline;
 * scoped per user; no personal data sent to external avatar services (REQ-0444).
 */
class HomeDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Subscriber $s;
    private User $manager;
    private Property $a;
    private Property $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSubscriber();
        $this->manager = $this->makeUser($this->s, 'manager', ['name' => 'أحمد الفاهمي']);
        $this->tenant()->set($this->s->id);
        $this->manager->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $w = app(BuildingWizard::class);
        $o = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك']);
        $this->a = $w->createProperty(Portfolio::create(['name_ar' => 'أ']), ['name_ar' => 'عمارة الضوء'], [['owner' => $o, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $this->b = $w->createProperty(Portfolio::create(['name_ar' => 'ب']), ['name_ar' => 'برج الندى'], [['owner' => $o, 'numerator' => 1, 'denominator' => 1]], 'office_tower');
        $w->generate($this->a, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 2]]], ['number' => 2, 'units' => [['type' => 'apartment', 'count' => 2]]]]]);
        $w->generate($this->b, ['name_ar' => 'B', 'code_prefix' => 'T', 'floors' => [['number' => 1, 'units' => [['type' => 'office', 'count' => 3]]]]]);
        $ls = app(LeasingService::class);
        $t = Tenant::create(['kind' => 'individual', 'name_ar' => 'مستأجر']);
        $o2 = $ls->createOffer($this->manager, LeasableUnit::where('code', '101')->first(), $t, ['usage' => 'residential', 'starts_on' => now()->subMonths(2)->toDateString(), 'duration_unit' => 'year', 'duration_count' => 1, 'annual_rent_minor' => Money::parse('40000'), 'payment_frequency' => 'annual']);
        $lease = $ls->acceptOffer($ls->sendOffer($ls->submitOffer($o2)));
        $ls->sendForRegistration($lease);
        $ls->markAwaitingParties($lease);
        $ls->register($lease, 'EJ-H1');
        $ls->activate($lease);
        app(MaintenanceService::class)->report('emergency', $this->a->id, 'انقطاع كهرباء', 'tenant');
        Filament::setCurrentPanel(Filament::getPanel('manager'));
    }

    public function test_req_0315_manager_home_shows_greeting_vitals_facade_attention_and_actions(): void
    {
        $html = $this->actingAs($this->manager)->get('/manage')->assertOk()->getContent();
        foreach (['أحمد', 'نسبة الإشغال', 'معدل التحصيل', 'متأخرات مستحقة', 'أوامر عمل مفتوحة', 'واجهة المبنى اليوم', 'يحتاج انتباهك', 'بلاغ طارئ مفتوح', 'إجراءات سريعة', 'إضافة مبنى', 'تسجيل دفعة', 'هـ'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('wq-unit wq-s-paid', $html);     // leased & nothing overdue
        $this->assertStringContainsString('wq-unit wq-s-vacant', $html);
        $this->assertStringContainsString('dir="rtl"', $html);
    }

    public function test_req_0283_vitals_and_facade_numbers_are_correct(): void
    {
        $svc = app(HomeOverview::class);
        $ids = $svc->propertyIds($this->manager);
        $v = $svc->vitals($ids, CarbonImmutable::today());
        $this->assertSame(1, $v['occupancy']['leased']);
        $this->assertSame(7, $v['occupancy']['total']);
        $this->assertSame(1, $v['work_orders']['emergency']);
        $buildingA = collect($svc->buildings($ids))->first(fn ($b) => str_contains($b['name'], 'عمارة الضوء'))['id'];
        $f = $svc->facade($buildingA, CarbonImmutable::today());
        $this->assertSame(['الطابق 2', 'الطابق 1'], array_column($f['rows'], 'floor'));   // top floor first, like a facade
        $this->assertSame(['paid' => 1, 'late' => 0, 'vacant' => 3, 'maint' => 0, 'soon' => 0], $f['counts']);
    }

    public function test_req_0494_staff_see_only_their_scope_and_permitted_actions(): void
    {
        $acc = $this->makeUser($this->s, 'accountant');
        $this->tenant()->set($this->s->id);
        UserScope::create(['user_id' => $acc->id, 'scope_type' => 'property', 'scope_id' => $this->b->id]);
        $svc = app(HomeOverview::class);
        $this->assertSame([$this->b->id], $svc->propertyIds($acc));
        $this->assertSame(0, $svc->vitals($svc->propertyIds($acc), CarbonImmutable::today())['occupancy']['leased']);
        $html = $this->actingAs($acc)->get('/manage')->assertOk()->getContent();
        $this->assertStringContainsString('برج الندى', $html);
        $this->assertStringNotContainsString('عمارة الضوء', $html);
        $this->assertStringContainsString('تسجيل دفعة', $html);       // finance
        $this->assertStringNotContainsString('إضافة مبنى', $html);    // no property creation for an accountant
        $this->assertStringNotContainsString('بلاغ طارئ مفتوح', $html); // maintenance items are not theirs
    }

    /** REQ-0494, REQ-0061 — staff screens follow the role: hidden from the menu AND forbidden by URL. */
    public function test_req_0494_screens_are_gated_by_role_permission_even_by_url(): void
    {
        $acc = $this->makeUser($this->s, 'accountant');
        $leasing = $this->makeUser($this->s, 'leasing_officer');
        $this->tenant()->set($this->s->id);
        $this->actingAs($acc)->get('/manage/add-building')->assertForbidden();
        $this->actingAs($acc)->get('/manage/work-orders')->assertForbidden();
        $this->actingAs($acc)->get('/manage/invoices')->assertOk();
        $this->actingAs($leasing)->get('/manage/invoices')->assertForbidden();
        $this->actingAs($leasing)->get('/manage/lease-offers')->assertOk();
        $this->actingAs($leasing)->get('/manage/subscriber-settings')->assertForbidden();
    }

    public function test_new_office_gets_an_inviting_empty_state(): void
    {
        $fresh = $this->makeSubscriber();
        $m = $this->makeUser($fresh, 'manager');
        $this->tenant()->set($fresh->id);
        $m->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $html = $this->actingAs($m)->get('/manage')->assertOk()->getContent();
        $this->assertStringContainsString('لم تُضف مبانٍ بعد', $html);
        $this->assertStringContainsString('لا اعتمادات ولا بلاغات ولا متأخرات تنتظرك', $html);
    }

    public function test_req_0444_avatars_are_generated_locally_never_by_an_external_service(): void
    {
        $html = $this->actingAs($this->manager)->get('/manage')->getContent();
        $this->assertStringNotContainsString('ui-avatars.com', $html);
        $this->assertStringContainsString('class="wq-tb-avatar"', $html);   // the header avatar is the name's first letter, drawn locally (2026-11-24)
    }
}
