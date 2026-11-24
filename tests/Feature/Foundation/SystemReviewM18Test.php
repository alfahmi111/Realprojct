<?php

namespace Tests\Feature\Foundation;

use App\Finance\InvoiceService;
use App\Finance\PaymentService;
use App\Finance\TrustAccounting;
use App\Leasing\LeaseLifecycle;
use App\Leasing\LeasingService;
use App\Maintenance\MaintenanceService;
use App\Models\BillingScheduleLine;
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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * System review M18 (five review agents, 2026-11-24): each test reproduces a finding and proves the fix.
 * Billing schedule (REQ-0187, REQ-0190), owner statement (REQ-0305, REQ-0436), tenant credit (REQ-0188),
 * approvals (REQ-0069), write permissions (REQ-0494), approval ceiling (REQ-0228), lease lifecycle (REQ-0193).
 */
class SystemReviewM18Test extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Owner $owner;
    private Property $p;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->makeSubscriber();
        $this->manager = $this->makeUser($s, 'manager');
        $this->tenant()->set($s->id);
        $this->manager->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $w = app(BuildingWizard::class);
        $this->owner = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك', 'national_id' => '1000000001']);
        $this->p = $w->createProperty(Portfolio::create(['name_ar' => 'م']), ['name_ar' => 'عمارة'], [['owner' => $this->owner, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $w->createAgreement($this->owner, [$this->p], ['fee_type' => 'percentage', 'fee_percentage_bp' => 500, 'collector' => 'manager', 'starts_on' => '2020-01-01', 'spend_cap_minor' => Money::parse('500')]);
        $w->generate($this->p, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 3]]]]]);
        $this->tenant = Tenant::create(['kind' => 'individual', 'name_ar' => 'مستأجر']);
        Filament::setCurrentPanel(Filament::getPanel('manager'));
    }

    /** A lease on unit $code with explicit dates (bypasses the offer for odd terms), made active. */
    private function lease(string $code, string $start, string $end, string $freq = 'monthly', string $rent = '120000'): Lease
    {
        $unit = LeasableUnit::where('code', $code)->first();
        $l = Lease::create(['leasable_unit_id' => $unit->id, 'tenant_id' => $this->tenant->id, 'kind' => 'lease', 'usage' => 'residential', 'track' => 'long',
            'starts_on' => $start, 'ends_on' => $end, 'annual_rent_minor' => Money::parse($rent), 'payment_frequency' => $freq, 'deposit_minor' => Money::zero()]);
        $l->forceFill(['status' => 'active'])->save();
        app(\App\Leasing\ScheduleGenerator::class)->generate($l);
        return $l->fresh();
    }

    private function total(Lease $l): int
    {
        return BillingScheduleLine::where('lease_id', $l->id)->whereNull('archived_at')->get()->sum(fn ($x) => $x->amount_minor->minor());
    }

    public function test_req_0187_schedule_bills_every_day_to_the_end_and_full_years_add_up(): void
    {
        $a = $this->lease('101', '2025-01-01', '2026-03-15');   // 14.5 months
        $lines = BillingScheduleLine::where('lease_id', $a->id)->whereNull('archived_at')->orderBy('period_start')->get();
        $this->assertSame('2026-03-15', $lines->last()->period_end->toDateString());   // was 2026-02-28
        $this->assertSame(12000000 + 2 * 1000000 + intdiv(1000000 * 15 * 1 + 15, 31), $this->total($a));   // 1 year + Jan + Feb + 15/31 of March
        $b = $this->lease('102', '2024-02-29', '2028-02-28', 'annual');   // leap-day start, 4 years
        $this->assertSame(48000000, $this->total($b));                       // was 49,000,000 (a spurious fifth year)
        $this->assertSame(4, BillingScheduleLine::where('lease_id', $b->id)->whereNull('archived_at')->count());
    }

    public function test_req_0190_date_escalation_applies_from_its_date_not_the_contract_year_start(): void
    {
        $unit = LeasableUnit::where('code', '103')->first();
        $l = Lease::create(['leasable_unit_id' => $unit->id, 'tenant_id' => $this->tenant->id, 'kind' => 'lease', 'usage' => 'residential', 'track' => 'long',
            'starts_on' => '2026-01-01', 'ends_on' => '2027-12-31', 'annual_rent_minor' => Money::parse('120000'), 'payment_frequency' => 'monthly', 'deposit_minor' => Money::zero()]);
        $l->forceFill(['status' => 'active'])->save();
        \App\Models\RentEscalation::create(['lease_id' => $l->id, 'kind' => 'percentage', 'percentage_bp' => 500, 'effective_on' => '2027-07-01', 'status' => 'scheduled']);
        app(\App\Leasing\ScheduleGenerator::class)->generate($l);
        $by = BillingScheduleLine::where('lease_id', $l->id)->whereNull('archived_at')->get()->keyBy(fn ($x) => $x->period_start->format('Y-m'));
        $this->assertSame(1000000, $by['2027-06']->amount_minor->minor());   // was 1,050,000
        $this->assertSame(1050000, $by['2027-07']->amount_minor->minor());
        $this->assertSame(12000000 + 6 * 1000000 + 6 * 1050000, $this->total($l));
    }

    public function test_req_0436_statement_transfers_only_what_the_office_holds(): void
    {
        $l = $this->lease('101', '2026-01-01', '2026-12-31', 'annual', '10000');
        $inv = app(InvoiceService::class)->issueRent($l->scheduleLines()->first(), CarbonImmutable::parse('2026-01-01'));
        app(PaymentService::class)->record($this->tenant, $inv->total_minor, 'bank_transfer', CarbonImmutable::parse('2026-01-05'), 'owner');
        $s = app(TrustAccounting::class)->statement($this->owner, CarbonImmutable::parse('2026-01-01'));
        $this->assertSame(1000000, $s['collected_by_owner']->minor());
        $this->assertSame(0, $s['held_by_manager']->minor());
        $this->assertTrue($s['net_to_owner']->isNegative());   // the owner owes the fee; nothing to transfer (was 9,425)
        $this->assertSame('5% من المحصَّل', TrustAccounting::feeText($s['management_fee']));
    }

    public function test_req_0188_a_payment_before_its_invoice_settles_it_when_issued(): void
    {
        $l = $this->lease('101', '2026-01-01', '2026-12-31', 'annual', '10000');
        app(PaymentService::class)->record($this->tenant, Money::parse('10000'), 'cash', CarbonImmutable::parse('2026-01-01'), 'manager');
        $inv = app(InvoiceService::class)->issueRent($l->scheduleLines()->first(), CarbonImmutable::parse('2026-01-02'));
        $this->assertTrue(app(InvoiceService::class)->outstanding($inv->fresh())->isZero());   // was 10,000 unpaid forever
    }

    public function test_req_0069_reversed_payment_frees_the_invoice_and_cannot_be_reversed_twice(): void
    {
        $l = $this->lease('101', '2026-01-01', '2026-12-31', 'annual', '10000');
        $inv = app(InvoiceService::class)->issueRent($l->scheduleLines()->first(), CarbonImmutable::parse('2026-01-01'));
        $pay = app(PaymentService::class)->record($this->tenant, Money::parse('10000'), 'cash', CarbonImmutable::parse('2026-01-02'), 'manager');
        $accountant = $this->makeUser($this->manager->subscriber, 'accountant');
        $req = app(PaymentService::class)->requestReversal($pay, $accountant, 'خطأ');
        try {
            app(PaymentService::class)->requestReversal($pay->fresh(), $accountant, 'مرة ثانية');   // a second pending request on the same payment
            $this->fail('a second pending request must be refused');
        } catch (\App\Approvals\ApprovalException) {
        }
        app(\App\Approvals\DualApproval::class)->approve($req, $this->manager, fn ($r) => app(\App\Approvals\ApprovalExecutor::class)->execute($r));
        $this->assertSame('reversed', $pay->fresh()->status);
        $this->assertFalse(app(InvoiceService::class)->hasLiveAllocations($inv));   // cancellation is possible again
        $this->expectException(\App\Finance\FinanceException::class);
        app(PaymentService::class)->requestReversal($pay->fresh(), $accountant, 'مرة ثالثة');   // already reversed
    }

    public function test_req_0494_viewing_roles_cannot_create_edit_or_archive(): void
    {
        $accountant = $this->makeUser($this->manager->subscriber, 'accountant');
        $supervisor = $this->makeUser($this->manager->subscriber, 'maintenance_supervisor');
        foreach ([$accountant, $supervisor] as $u) {
            $this->actingAs($u);
            $this->assertFalse(\App\Filament\Manager\Resources\Owners\OwnerResource::canCreate());
            $this->assertFalse(\App\Filament\Manager\Resources\Owners\OwnerResource::canEdit($this->owner));
            $this->assertFalse(\App\Filament\Manager\Resources\Properties\PropertyResource::canEdit($this->p));
            $this->assertFalse(\App\Filament\Manager\Resources\Tenants\TenantResource::canCreate());
            $this->assertFalse(\App\Filament\Manager\Resources\LeaseOffers\LeaseOfferResource::canCreate());
        }
        $this->actingAs($this->manager);
        $this->assertTrue(\App\Filament\Manager\Resources\Owners\OwnerResource::canEdit($this->owner));
    }

    public function test_owner_edit_does_not_wipe_the_encrypted_id(): void
    {
        $this->actingAs($this->manager);
        \Livewire\Livewire::test(\App\Filament\Manager\Resources\Owners\Pages\EditOwner::class, ['record' => $this->owner->id])
            ->fillForm(['name_ar' => 'مالك معدّل'])->call('save')->assertHasNoFormErrors();
        $o = Owner::find($this->owner->id);
        $this->assertSame('مالك معدّل', $o->name_ar);
        $this->assertSame('1000000001', $o->national_id);   // was wiped to null by every edit
    }

    public function test_req_0228_strictest_cap_in_force_and_actual_cost_checked_at_close(): void
    {
        $other = Owner::create(['kind' => 'individual', 'name_ar' => 'شريك']);
        app(BuildingWizard::class)->createAgreement($other, [$this->p], ['fee_type' => 'percentage', 'fee_percentage_bp' => 500, 'collector' => 'manager', 'starts_on' => '2024-01-01']);   // no cap
        app(BuildingWizard::class)->createAgreement($other, [$this->p], ['fee_type' => 'percentage', 'fee_percentage_bp' => 500, 'collector' => 'manager', 'starts_on' => '2099-01-01']);   // future: ignored
        $svc = app(MaintenanceService::class);
        $this->assertSame(50000, $svc->spendCap($this->p->id)->minor());   // the stricter co-owner's 500 still applies
        $wo = $svc->report('corrective', $this->p->id, 'تسريب', 'supervisor');
        $svc->triage($wo, 'normal');
        $svc->estimate($wo->fresh(), Money::parse('400'));
        $svc->assign($wo->fresh(), $this->makeUser($this->manager->subscriber, 'technician'));
        $svc->start($wo->fresh(), ['wo/before.jpg']);
        $svc->addCost($wo->fresh(), 'vendor', 'إصلاح', 1, Money::parse('5000'));
        $svc->complete($wo->fresh(), ['wo/after.jpg']);
        $this->expectException(\App\Maintenance\MaintenanceException::class);
        $svc->close($wo->fresh(), $this->manager);   // 5,000 > 500 without the owner's approval
    }

    public function test_req_0193_lifecycle_expires_noticed_leases_and_alerts_once(): void
    {
        $today = CarbonImmutable::today();
        $ended = $this->lease('101', $today->subYear()->subDays(10)->toDateString(), $today->subDays(11)->toDateString());
        $ended->forceFill(['status' => 'notice_given'])->save();
        $soon = $this->lease('102', $today->subYear()->addDays(70)->toDateString(), $today->addDays(69)->toDateString());   // notice deadline in ~9 days
        $lapsed = $this->lease('103', $today->subYear()->subDays(5)->toDateString(), $today->subDays(6)->toDateString());   // active, no notice
        $r = app(LeaseLifecycle::class)->run($today);
        $this->assertSame('expired', $ended->fresh()->status);
        $this->assertSame(1, $r['notice_alerts']);
        $this->assertSame(1, $r['renewal_alerts']);
        $this->assertSame('active', $lapsed->fresh()->status);   // renewal is recorded by a person, never automatic (Q35)
        $again = app(LeaseLifecycle::class)->run($today);
        $this->assertSame(0, $again['notice_alerts'] + $again['renewal_alerts']);   // once only
        $this->assertNotNull($soon);
    }

    public function test_preventive_plan_months_behind_creates_one_work_order_and_jumps_past_today(): void
    {
        $space = \App\Models\Space::where('code', '101')->first();
        $plan = \App\Models\PpmPlan::create(['space_id' => $space->id, 'title_ar' => 'فلاتر', 'every_months' => 1, 'next_due_on' => '2026-01-01']);
        $svc = app(MaintenanceService::class);
        $this->assertSame(1, $svc->generatePreventive(CarbonImmutable::parse('2026-11-24')));
        $this->assertSame('2026-12-01', $plan->fresh()->next_due_on->toDateString());
        $this->assertSame(0, $svc->generatePreventive(CarbonImmutable::parse('2026-11-25')));   // was one more every day
    }

    public function test_month_end_start_keeps_its_last_day(): void
    {
        $this->assertSame('2026-02-28', LeasingService::lastDay(CarbonImmutable::parse('2026-01-31'), CarbonImmutable::parse('2026-01-31')->addMonthsNoOverflow(1))->toDateString());
        $this->assertSame('2026-12-31', LeasingService::lastDay(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))->toDateString());
    }

    public function test_export_neutralises_spreadsheet_formulas(): void
    {
        $m = new \ReflectionMethod(\App\Migration\DataExport::class, 'cell');
        $this->assertSame("'=HYPERLINK(\"x\")", $m->invoke(null, '=HYPERLINK("x")'));
        $this->assertSame('-150', $m->invoke(null, '-150'));   // a negative number stays a number
        $this->assertSame('خالد', $m->invoke(null, 'خالد'));
    }
}
