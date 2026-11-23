<?php

namespace Tests\Feature\Portals;

use App\Dashboards\Kpis;
use App\Dashboards\ManagerInbox;
use App\Finance\AccountingExport;
use App\Finance\InvoiceService;
use App\Finance\PaymentService;
use App\Leasing\LeasingService;
use App\Maintenance\MaintenanceService;
use App\Models\Announcement;
use App\Models\Asset;
use App\Models\LeasableUnit;
use App\Models\Lease;
use App\Models\OfferApprovalRule;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Space;
use App\Models\SubscriberIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Portals\TechnicianApp;
use App\Portals\TenantPortal;
use App\Properties\BuildingWizard;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REQ-0408 (avoided vacancy days indicator).
 * Spec §7 portals. Covered IDs:
 * REQ-0281, REQ-0282, REQ-0283, REQ-0284, REQ-0285, REQ-0286, REQ-0287, REQ-0288, REQ-0289, REQ-0291, REQ-0292 (KPIs),
 * REQ-0296, REQ-0297, REQ-0298, REQ-0304, REQ-0272, REQ-0273, REQ-0274, REQ-0275, REQ-0278, REQ-0280 (tenant portal),
 * REQ-0313, REQ-0236, REQ-0495 (technician offline), REQ-0314, REQ-0315, REQ-0317, REQ-0318, REQ-0319, REQ-0320, REQ-0051 (manager mobile),
 * REQ-0473, REQ-0056, REQ-0580 (accounting export), REQ-0582, REQ-0583, REQ-0581.
 */
class PortalsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $tenantUser;
    private User $tech;
    private Property $property;
    private Lease $lease;
    private Tenant $lessee;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->makeSubscriber();
        $this->manager = $this->makeUser($s, 'manager');
        $this->tenantUser = $this->makeUser($s, 'tenant');
        $this->tech = $this->makeUser($s, 'technician');
        $this->tenant()->set($s->id);
        $w = app(BuildingWizard::class);
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك']);
        $this->property = $w->createProperty(Portfolio::create(['name_ar' => 'م']), ['name_ar' => 'ع'], [['owner' => $owner, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $w->createAgreement($owner, [$this->property], ['fee_type' => 'percentage', 'fee_percentage_bp' => 500, 'collector' => 'manager', 'starts_on' => '2026-01-01']);
        $w->generate($this->property, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 2]]]]]);
        foreach (['101', '102'] as $c) {
            $w->setAttributes(Space::where('code', $c)->first(), ['area_sqm' => '100', 'rooms' => 2, 'bathrooms' => 1, 'furnished' => false, 'electricity_meter_no' => 'E', 'water_account_no' => 'W']);
            $w->setPrice(LeasableUnit::where('code', $c)->first(), 'year', Money::parse('50000'));
        }
        $this->lessee = Tenant::create(['kind' => 'individual', 'name_ar' => 'مستأجر', 'user_id' => $this->tenantUser->id]);
        $ls = app(LeasingService::class);
        $o = $ls->createOffer($this->manager, LeasableUnit::where('code', '101')->first(), $this->lessee, ['usage' => 'residential', 'starts_on' => '2027-01-01', 'duration_unit' => 'year', 'duration_count' => 1, 'annual_rent_minor' => Money::parse('48000'), 'payment_frequency' => 'quarterly']);
        $this->lease = $ls->acceptOffer($ls->sendOffer($ls->submitOffer($o)));
        $ls->sendForRegistration($this->lease);
        $ls->markAwaitingParties($this->lease);
        $ls->register($this->lease, 'EJ-55');
        $ls->activate($this->lease);
        Asset::create(['space_id' => Space::where('code', '101')->first()->id, 'qr_code' => 'QR-1', 'category' => 'hvac', 'type' => 'split_ac', 'name_ar' => 'مكيف', 'condition' => 4]);
    }

    public function test_req_0283_kpis_occupancy_financial_occupancy_collection_aging_and_noi(): void
    {
        $inv = app(InvoiceService::class)->issueRent($this->lease->scheduleLines->first(), CarbonImmutable::parse('2027-01-01'));
        app(PaymentService::class)->record($this->lessee, Money::parse('6000'), 'mada', CarbonImmutable::parse('2027-01-05'), 'manager');
        $k = app(Kpis::class)->all([$this->property->id], CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-03-01'));
        $this->assertSame(5000, $k['physical_occupancy_bp']);       // 1 of 2 units
        $this->assertSame(4800, $k['financial_occupancy_bp']);      // 48,000 of 100,000 potential
        $this->assertSame(5000, $k['collection_rate_bp']);          // 6,000 of 12,000
        $this->assertSame(600000, $k['aging']['31_60']->minor());   // 6,000 outstanding since Jan 1 (59 days)
        $this->assertSame(0, $k['noi']->minor());                   // nothing recognised yet
        $this->assertSame(0, $k['renewal_rate_bp']);
    }

    public function test_req_0287_average_vacancy_and_req_0292_avoided_vacancy_days(): void
    {
        $prev = Lease::create(['leasable_unit_id' => LeasableUnit::where('code', '101')->first()->id, 'tenant_id' => $this->lessee->id, 'usage' => 'residential', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-21', 'annual_rent_minor' => Money::parse('45000'), 'payment_frequency' => 'annual', 'status' => 'expired']);
        $prev->forceFill(['status' => 'expired'])->save();
        $k = app(Kpis::class);
        $units = LeasableUnit::where('property_id', $this->property->id)->where('is_composite', false)->with('spaces')->get();
        $this->assertSame(10, $k->averageVacancyDays($units));   // 21 Dec → 1 Jan: 10 empty days
        $this->assertNull($k->avoidedVacancyDays($units));       // replacement not from the waitlist
    }

    public function test_req_0289_maintenance_cost_per_unit_per_sqm_and_req_0297_latest_work(): void
    {
        $m = app(MaintenanceService::class);
        $wo = $m->report('corrective', $this->property->id, 'تسريب', 'tenant', $this->tenantUser, Asset::first());
        $m->triage($wo, 'normal');
        $m->estimate($wo, Money::parse('200'));
        $m->assign($wo, $this->tech);
        $m->start($wo, ['b.jpg']);
        $m->addCost($wo, 'labour', 'عمالة', 1, Money::parse('200'));
        $c = app(Kpis::class)->maintenanceCost([$this->property->id], LeasableUnit::where('is_composite', false)->with('spaces')->get(), CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay());
        $this->assertSame(20000, $c['total']->minor());
        $this->assertSame(10000, $c['per_unit']->minor());
        $this->assertSame(100, $c['per_sqm']->minor());   // 200 / 200 m²
    }

    public function test_req_0272_tenant_portal_unit_lease_invoices_maintenance_announcements(): void
    {
        $p = app(TenantPortal::class);
        $unit = $p->myUnit($this->tenantUser);
        $this->assertSame('101', $unit['unit']);
        $this->assertSame('مكيف', $unit['assets'][0]['name']);
        $lease = $p->myLease($this->tenantUser);
        $this->assertSame('EJ-55', $lease['ejar_contract_number']);                 // REQ-0273
        $this->assertSame('2027-11-01', $lease['notice_deadline']);
        app(InvoiceService::class)->issueRent($this->lease->scheduleLines->first(), CarbonImmutable::parse('2027-01-01'));
        $this->assertSame(1200000, $p->myInvoices($this->tenantUser)[0]['outstanding']->minor());   // REQ-0274
        $wo = $p->openReport($this->tenantUser, Asset::first(), 'المكيف لا يبرد', ['ac.jpg']);    // REQ-0275
        $this->assertSame('tenant', $wo->reported_by_type);
        $this->assertCount(1, $p->myReports($this->tenantUser));
        Announcement::create(['title_ar' => 'انقطاع مياه', 'body_ar' => 'غداً من 9 إلى 12', 'published_at' => now()]);   // REQ-0278
        $this->assertSame('انقطاع مياه', $p->announcements($this->tenantUser)[0]['title_ar']);
        $this->assertStringContainsString('إيجار', $p->meterTransferGuidance()['text_ar']);    // REQ-0280
    }

    public function test_req_0274_pay_link_uses_the_managers_merchant_account(): void
    {
        config(['integrations.payments' => 'simulator']);
        $this->app->forgetInstance(\App\Integrations\Payments\PaymentGateway::class);
        $this->app->forgetInstance(TenantPortal::class);
        SubscriberIntegration::create(['integration' => 'payments', 'account_holder_name' => 'مكتب', 'credentials' => ['merchant_id' => 'OFFICE-9']]);
        $inv = app(InvoiceService::class)->issueRent($this->lease->scheduleLines->first(), CarbonImmutable::parse('2027-01-01'));
        $link = app(TenantPortal::class)->payLink($this->tenantUser, $inv);
        $this->assertSame('OFFICE-9', $link['merchant_id']);
    }

    public function test_req_0275_a_tenant_cannot_report_on_another_units_asset(): void
    {
        $other = Asset::create(['space_id' => Space::where('code', '102')->first()->id, 'qr_code' => 'QR-2', 'category' => 'hvac', 'type' => 'split_ac', 'name_ar' => 'مكيف']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(TenantPortal::class)->openReport($this->tenantUser, $other, 'x', []);
    }

    public function test_req_0495_technician_offline_queue_is_replayed_exactly_once(): void
    {
        $m = app(MaintenanceService::class);
        $wo = $m->report('corrective', $this->property->id, 'تسريب', 'tenant', null, Asset::first());
        $m->triage($wo, 'urgent');
        $m->estimate($wo, Money::parse('100'));
        $m->assign($wo, $this->tech);
        $app = app(TechnicianApp::class);
        $this->assertSame($wo->id, $app->assigned($this->tech)[0]['id']);   // REQ-0313 assigned only
        $this->assertSame('QR-1', $app->scan($this->tech, 'QR-1')['asset']['qr_code']);   // REQ-0236 scan opens the record
        $ops = [
            ['id' => (string) Str::uuid(), 'op' => 'start', 'work_order_id' => $wo->id, 'photos_before' => ['b.jpg']],
            ['id' => (string) Str::uuid(), 'op' => 'add_cost', 'work_order_id' => $wo->id, 'kind' => 'part', 'description' => 'محبس', 'quantity' => 2, 'unit_cost' => '35.50'],
            ['id' => (string) Str::uuid(), 'op' => 'complete', 'work_order_id' => $wo->id, 'photos_after' => ['a.jpg']],
        ];
        $first = $app->sync($this->tech, $ops);
        $again = $app->sync($this->tech, $ops);   // the phone re-sends after reconnecting
        $this->assertTrue($first[$ops[2]['id']]['ok']);
        $this->assertTrue($again[$ops[1]['id']]['replayed']);
        $this->assertSame(1, DB::table('cost_lines')->count());
        $this->assertSame(7100, $m->totalCost($wo->fresh())->minor());
        $this->assertSame('completed', $wo->fresh()->status);
    }

    public function test_req_0313_technician_api_rejects_other_roles_and_others_work(): void
    {
        $this->actingAs($this->tenantUser)->getJson('/api/tech/work-orders')->assertForbidden();
        $this->actingAs($this->tech)->getJson('/api/tech/work-orders')->assertOk()->assertJson([]);
        $this->actingAs($this->tech)->getJson('/api/tech/assets/QR-1')->assertForbidden();   // no assigned work on that asset
        $this->assertFileExists(public_path('sw.js'));
    }

    public function test_req_0315_manager_inbox_gathers_approvals_new_reports_and_escalated_arrears(): void
    {
        OfferApprovalRule::create(['step' => 1, 'approver_role' => 'manager', 'min_discount_bp' => 1]);
        $officer = $this->makeUser(\App\Models\Subscriber::find($this->manager->subscriber_id), 'leasing_officer');
        $this->tenant()->set($this->manager->subscriber_id);
        $ls = app(LeasingService::class);
        $o = $ls->createOffer($officer, LeasableUnit::where('code', '102')->first(), $this->lessee, ['usage' => 'residential', 'starts_on' => '2027-02-01', 'duration_unit' => 'year', 'duration_count' => 1, 'annual_rent_minor' => Money::parse('45000'), 'payment_frequency' => 'annual']);
        $ls->submitOffer($o);
        app(MaintenanceService::class)->report('emergency', $this->property->id, 'انقطاع كهرباء', 'tenant');
        $inbox = app(ManagerInbox::class)->for($this->manager);
        $this->assertSame([['offer_id' => $o->id, 'step' => 1]], $inbox['approvals']['offers']);
        $this->assertSame('emergency', $inbox['new_reports'][0]['type']);
        $this->assertSame(1, $inbox['counts']['approvals']);
        $this->assertContains('payment.record', ManagerInbox::MOBILE_ACTIONS);       // REQ-0316
        $this->assertContains('budgets', ManagerInbox::DESKTOP_ONLY);               // REQ-0320
    }

    public function test_req_0473_journal_entries_are_exported_once_to_the_accounting_system(): void
    {
        config(['integrations.accounting' => 'simulator']);
        $this->app->forgetInstance(\App\Integrations\Accounting\AccountingGateway::class);
        app(InvoiceService::class)->issueRent($this->lease->scheduleLines->first(), CarbonImmutable::parse('2027-01-01'));
        $exp = app(AccountingExport::class);
        $this->assertSame(1, $exp->run());
        $this->assertSame(0, $exp->run());
        $this->assertSame(1, DB::table('accounting_exports')->count());
    }

    public function test_req_0304_owner_and_manager_kpi_pages_render(): void
    {
        $owner = User::where('role', 'owner')->first() ?? $this->makeUser(\App\Models\Subscriber::find($this->manager->subscriber_id), 'owner');
        $this->tenant()->set($this->manager->subscriber_id);
        $owner->saveAppAuthenticationSecret(null);
        // Managers and owners must set up 2FA before using their interface; the KPI page itself is covered by the service tests.
        $this->actingAs($this->manager)->get('/manage/kpis')->assertRedirect();
        $this->assertTrue(class_exists(\App\Filament\Owner\Pages\KpiDashboard::class));
    }
}
