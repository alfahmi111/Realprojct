<?php

namespace Tests\Feature\Leasing;

use App\Finance\FinanceException;
use App\Finance\PaymentService;
use App\Leasing\LeaseLifecycle;
use App\Leasing\LeasingException;
use App\Leasing\LeasingService;
use App\Leasing\PolicyService;
use App\Models\BillingScheduleLine;
use App\Models\LeasableUnit;
use App\Models\Lease;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Tenant;
use App\Properties\BuildingWizard;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner decisions of 2026-11-24:
 *  - Q35 (REQ-0193, §5.6): a lease with no notice renews automatically for the same term, and the renewal can be ended.
 *  - Q33 (REQ-0436, §9.3–§9.4): collected funds go to the owner's account (draft REGA regulation), as a policies row.
 */
class OwnerDecisionsM19Test extends TestCase
{
    use RefreshDatabase;

    private Owner $owner;
    private Property $p;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->makeSubscriber();
        $this->makeUser($s, 'manager');
        $this->tenant()->set($s->id);
        $w = app(BuildingWizard::class);
        $this->owner = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك', 'national_id' => '1000000001']);
        $this->p = $w->createProperty(Portfolio::create(['name_ar' => 'م']), ['name_ar' => 'عمارة'], [['owner' => $this->owner, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $w->createAgreement($this->owner, [$this->p], ['fee_type' => 'percentage', 'fee_percentage_bp' => 500, 'collector' => 'owner', 'starts_on' => '2020-01-01']);
        $w->generate($this->p, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 2]]]]]);
        $this->tenant = Tenant::create(['kind' => 'individual', 'name_ar' => 'مستأجر']);
    }

    private function lease(string $code, CarbonImmutable $start, CarbonImmutable $end): Lease
    {
        $unit = LeasableUnit::where('code', $code)->first();
        $l = Lease::create(['leasable_unit_id' => $unit->id, 'tenant_id' => $this->tenant->id, 'kind' => 'lease', 'usage' => 'residential', 'track' => 'long',
            'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(), 'annual_rent_minor' => Money::parse('120000'), 'payment_frequency' => 'monthly', 'deposit_minor' => Money::zero()]);
        $l->forceFill(['status' => 'active', 'ejar_contract_number' => 'EJ-1'])->save();
        app(LeasingService::class)->occupy($unit, $start, $end, 'lease', leaseId: $l->id);
        app(\App\Leasing\ScheduleGenerator::class)->generate($l);
        return $l->fresh();
    }

    public function test_req_0193_q35_lapsed_lease_renews_for_the_same_term_and_rent(): void
    {
        $today = CarbonImmutable::parse('2027-01-10');
        $old = $this->lease('101', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'));
        $r = app(LeaseLifecycle::class)->run($today);
        $this->assertSame(1, $r['renewed']);
        $this->assertSame('renewed', $old->fresh()->status);
        $new = Lease::where('parent_lease_id', $old->id)->firstOrFail();
        $this->assertSame('active', $new->status);
        $this->assertNotNull($new->auto_renewed_at);
        $this->assertSame(['2027-01-01', '2027-12-31'], [$new->starts_on->toDateString(), $new->ends_on->toDateString()]);
        $this->assertSame(12000000, $new->annual_rent_minor->minor());
        $this->assertSame(12000000, BillingScheduleLine::where('lease_id', $new->id)->get()->sum(fn ($x) => $x->amount_minor->minor()));
        $this->assertSame(1, DB::table('notifications_outbox')->where('event', 'lease.auto_renewed')->count() > 0 ? 1 : 0);
        $this->assertSame(0, app(LeaseLifecycle::class)->run($today)['renewed']);   // never twice
    }

    public function test_req_0193_q35_renewal_blocked_by_a_later_booking_asks_a_person_once(): void
    {
        $today = CarbonImmutable::parse('2027-01-10');
        $old = $this->lease('101', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'));
        $this->lease('101', CarbonImmutable::parse('2027-02-01'), CarbonImmutable::parse('2028-01-31'));   // unit already booked
        $r = app(LeaseLifecycle::class)->run($today);
        $this->assertSame([0, 1], [$r['renewed'], $r['renewal_alerts']]);
        $this->assertSame('active', $old->fresh()->status);
        $this->assertSame(0, app(LeaseLifecycle::class)->run($today)['renewal_alerts']);
    }

    public function test_req_0193_q35_auto_renewal_can_be_ended_early_with_proration(): void
    {
        $old = $this->lease('101', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'));
        app(LeaseLifecycle::class)->run(CarbonImmutable::parse('2027-01-02'));
        $new = Lease::where('parent_lease_id', $old->id)->firstOrFail();
        $svc = app(LeasingService::class);
        $svc->terminateRenewal($new, CarbonImmutable::parse('2027-03-15'), 'tenant', 'انتقال', CarbonImmutable::parse('2027-01-05'));
        $new->refresh();
        $this->assertSame(['notice_given', '2027-03-15', 'tenant'], [$new->status, $new->ends_on->toDateString(), $new->terminated_by]);
        $live = BillingScheduleLine::where('lease_id', $new->id)->where('status', '!=', 'void')->get();
        $this->assertSame(2 * 1000000 + intdiv(1000000 * 15 + 15, 31), $live->sum(fn ($x) => $x->amount_minor->minor()));   // Jan, Feb, 15/31 of March
        $this->assertSame(9, BillingScheduleLine::where('lease_id', $new->id)->where('status', 'void')->count());
        $this->assertSame('[2027-01-01,2027-03-16)', DB::table('space_occupancies')->where('lease_id', $new->id)->value('period'));
        // a lease that was not renewed automatically cannot be ended this way
        $this->expectException(LeasingException::class);
        $svc->terminateRenewal($this->lease('102', CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-12-31')), CarbonImmutable::parse('2027-06-30'), 'lessor', 'x', CarbonImmutable::parse('2027-01-05'));
    }

    public function test_req_0436_q33_owner_account_is_the_default_and_required_mode_refuses_the_office_account(): void
    {
        $policies = app(PolicyService::class);
        $this->assertSame('default', $policies->ownerAccountMode(CarbonImmutable::parse('2026-12-01')));
        $this->assertSame('owner', array_key_first($policies->paidToOptions(CarbonImmutable::parse('2026-12-01'))));
        $this->assertStringContainsString('مسودة', $policies->paidToOptions(CarbonImmutable::parse('2026-12-01'))['manager']);
        // the rule is data: when the regulation is approved the platform switches the row to «required»
        DB::table('policies')->where('code', 'REGA-DRAFT-OWNER-ACCOUNT')->update(['payload' => json_encode(['mode' => 'required'])]);
        $this->assertSame(['owner'], array_keys($policies->paidToOptions(CarbonImmutable::parse('2026-12-01'))));
        $pay = app(PaymentService::class)->record($this->tenant, Money::parse('100'), 'bank_transfer', CarbonImmutable::parse('2026-12-01'), 'owner');
        $this->assertSame('owner', $pay->paid_to);
        $this->expectException(FinanceException::class);
        app(PaymentService::class)->record($this->tenant, Money::parse('100'), 'cash', CarbonImmutable::parse('2026-12-01'), 'manager');
    }

    public function test_req_0436_q33_required_mode_refuses_a_new_office_collection_agreement(): void
    {
        DB::table('policies')->where('code', 'REGA-DRAFT-OWNER-ACCOUNT')->update(['payload' => json_encode(['mode' => 'required'])]);
        $this->expectException(FinanceException::class);
        app(BuildingWizard::class)->createAgreement($this->owner, [$this->p], ['fee_type' => 'percentage', 'fee_percentage_bp' => 500, 'collector' => 'manager', 'starts_on' => '2027-01-01']);
    }
}
