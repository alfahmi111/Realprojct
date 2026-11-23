<?php

namespace Tests\Feature\Leasing;

use App\Leasing\LeasingException;
use App\Leasing\LeasingService;
use App\Leasing\ScheduleGenerator;
use App\Models\BillingScheduleLine;
use App\Models\LeasableUnit;
use App\Models\Lease;
use App\Models\OfferApprovalRule;
use App\Models\Owner;
use App\Models\Policy;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\RentEscalation;
use App\Models\Tenant;
use App\Models\User;
use App\Properties\BuildingWizard;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REQ-0438, REQ-0457, REQ-0458, REQ-0460 (Ejar: no public API, manual-assisted plan via EjarGateway).
 * Spec §5 leasing engine. Covered IDs:
 * REQ-0170, REQ-0171, REQ-0172, REQ-0173, REQ-0174, REQ-0175 (duration units and tracks), REQ-0177 (rate plans),
 * REQ-0178, REQ-0179, REQ-0180 (availability, no overlap), REQ-0181, REQ-0182, REQ-0183, REQ-0184, REQ-0185, REQ-0186 (lifecycle, offer),
 * REQ-0187, REQ-0188, REQ-0189, REQ-0190 (schedule, escalations, freeze), REQ-0191, REQ-0194, REQ-0195 (renewal),
 * REQ-0196, REQ-0197, REQ-0198, REQ-0199, REQ-0200, REQ-0201, REQ-0202, REQ-0203, REQ-0204 (policies),
 * REQ-0048, REQ-0049, REQ-0139, REQ-0140, REQ-0141, REQ-0149, REQ-0150, REQ-0459, REQ-0461, REQ-0573, REQ-0678.
 */
class LeasingEngineTest extends TestCase
{
    use RefreshDatabase;

    private LeasingService $svc;
    private User $officer;
    private User $manager;
    private Property $property;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->makeSubscriber();
        $this->officer = $this->makeUser($s, 'leasing_officer');
        $this->manager = $this->makeUser($s, 'manager');
        $this->tenant()->set($s->id);
        $this->svc = app(LeasingService::class);
        $w = app(BuildingWizard::class);
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك']);
        $pf = Portfolio::create(['name_ar' => 'محفظة']);
        $this->property = $w->createProperty($pf, ['name_ar' => 'عمارة', 'region' => 'makkah', 'city' => 'jeddah'], [['owner' => $owner, 'numerator' => 1, 'denominator' => 1]], 'mixed_building');
        $b = $w->generate($this->property, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 2]]]]]);
        $w->wholeBuilding($b, 'ALL', 'المبنى كاملاً');
        $w->setPrice($this->unit('101'), 'year', Money::parse('60000'));
        $this->tenant = Tenant::create(['kind' => 'individual', 'name_ar' => 'مستأجر']);
    }

    private function unit(string $code): LeasableUnit
    {
        return LeasableUnit::where('code', $code)->firstOrFail();
    }

    private function offer(string $code = '101', array $terms = []): \App\Models\LeaseOffer
    {
        return $this->svc->createOffer($this->officer, $this->unit($code), $this->tenant, $terms + [
            'usage' => 'residential', 'starts_on' => '2027-01-01', 'duration_unit' => 'year', 'duration_count' => 1,
            'annual_rent_minor' => Money::parse('60000'), 'payment_frequency' => 'monthly', 'deposit_minor' => Money::parse('5000'),
        ]);
    }

    private function leaseFor(string $code = '101', array $terms = []): Lease
    {
        $o = $this->svc->submitOffer($this->offer($code, $terms));
        $token = $this->svc->sendOffer($o);
        return $this->svc->acceptOffer($token);
    }

    public function test_req_0184_offer_goes_through_role_and_discount_approvals_then_becomes_a_draft_without_reentry(): void
    {
        OfferApprovalRule::create(['step' => 1, 'approver_role' => 'manager', 'min_discount_bp' => 500]);   // ≥5% discount needs the manager
        $noDiscount = $this->svc->submitOffer($this->offer());
        $this->assertSame('approved', $noDiscount->status);

        $discounted = $this->svc->submitOffer($this->offer('102', ['annual_rent_minor' => Money::parse('54000')]));
        $this->assertSame(0, $this->svc->discountBp($discounted));   // 102 has no listed price
        $o = $this->offer('101', ['starts_on' => '2028-01-01', 'annual_rent_minor' => Money::parse('54000')]);
        $this->assertSame(1000, $this->svc->discountBp($o));
        $this->svc->submitOffer($o);
        $this->assertSame('pending_approval', $o->status);
        try {
            $this->svc->decideOffer($o, $this->officer, true);
            $this->fail('wrong approver accepted');
        } catch (LeasingException) {
        }
        $this->svc->decideOffer($o, $this->manager, true);
        $this->assertSame('approved', $o->status);

        $lease = $this->svc->acceptOffer($this->svc->sendOffer($o));
        $this->assertSame('draft', $lease->status);
        $this->assertSame('2028-01-01', $lease->starts_on->toDateString());
        $this->assertSame('2028-12-31', $lease->ends_on->toDateString());
        $this->assertSame(5400000, $lease->annual_rent_minor->minor());
        $this->assertSame('accepted', $o->fresh()->status);
    }

    public function test_req_0184_rejected_or_expired_offers_are_archived_with_the_loss_reason(): void
    {
        OfferApprovalRule::create(['step' => 1, 'approver_role' => 'manager', 'min_discount_bp' => 0]);
        $o = $this->svc->submitOffer($this->offer());
        $this->svc->decideOffer($o, $this->manager, false, 'سعر منخفض');
        $this->assertSame('rejected', $o->fresh()->status);
        $this->assertNotNull($o->fresh()->archived_at);
        $this->assertSame('سعر منخفض', $o->fresh()->loss_reason);
    }

    public function test_req_0179_no_overlapping_leases_even_between_a_composite_and_its_parts(): void
    {
        $this->leaseFor('101');
        try {
            $this->leaseFor('ALL');   // whole building while 101 is leased
            $this->fail('composite leased over a leased part');
        } catch (LeasingException $e) {
            $this->assertStringContainsString('already leased', $e->getMessage());
        }
        $this->leaseFor('102');       // the other apartment is free
        // Direct SQL bypassing the service is also rejected by PostgreSQL.
        $space = $this->unit('101')->spaces->first();
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::insert("insert into space_occupancies (subscriber_id, space_id, kind, period, created_at, updated_at) values (?, ?, 'lease', '[2027-06-01,2027-06-30]', now(), now())", [$space->subscriber_id, $space->id]);
    }

    public function test_req_0182_lifecycle_follows_the_state_machine_with_manual_ejar_registration(): void
    {
        $lease = $this->leaseFor();
        $package = $this->svc->sendForRegistration($lease);
        $this->assertSame('manual', $package['mode']);
        $this->svc->markAwaitingParties($lease);
        $this->assertFalse($this->svc->canInvoice($lease));          // REQ-0185
        $this->svc->register($lease, 'EJ-2027-0001');
        $this->assertSame('EJ-2027-0001', $lease->fresh()->ejar_contract_number);   // REQ-0186
        $this->assertTrue($this->svc->canInvoice($lease));
        $this->assertSame(6000000, $this->unit('101')->last_registered_rent_minor->minor());   // REQ-0199
        $this->svc->activate($lease);
        $this->expectException(LeasingException::class);
        $this->svc->transition($lease, 'draft');   // no going back
    }

    public function test_req_0185_no_rent_invoice_before_registered_or_active(): void
    {
        $lease = $this->leaseFor();
        foreach (['draft', 'sent_for_registration', 'awaiting_parties'] as $status) {
            $lease->forceFill(['status' => $status]);
            $this->assertFalse($this->svc->canInvoice($lease), $status);
        }
        foreach (['registered', 'active'] as $status) {
            $lease->forceFill(['status' => $status]);
            $this->assertTrue($this->svc->canInvoice($lease), $status);
        }
    }

    public function test_req_0187_schedule_is_independent_from_the_term_and_never_loses_a_halala(): void
    {
        $lease = $this->leaseFor('101', ['duration_count' => 2, 'payment_frequency' => 'quarterly', 'annual_rent_minor' => Money::parse('60001')]);
        $lines = $lease->scheduleLines;
        $this->assertCount(8, $lines);
        $this->assertSame(12000200, Money::sum($lines->pluck('amount_minor')->all())->minor());
        $this->assertSame('2027-01-01', $lines[0]->due_on->toDateString());
        $this->assertSame('2027-03-31', $lines[0]->period_end->toDateString());

        foreach (['monthly' => 24, 'semiannual' => 4, 'annual' => 2, 'single' => 1] as $freq => $count) {
            $lease->forceFill(['payment_frequency' => $freq])->save();
            app(ScheduleGenerator::class)->generate($lease);
            $this->assertCount($count, $lease->fresh()->scheduleLines, $freq);
            $this->assertSame(12000200, Money::sum($lease->fresh()->scheduleLines->pluck('amount_minor')->all())->minor());
        }
    }

    public function test_req_0190_scheduled_escalations_regenerate_the_schedule(): void
    {
        $lease = $this->leaseFor('101', ['duration_count' => 3, 'payment_frequency' => 'annual', 'escalations' => [
            ['kind' => 'percentage', 'percentage_bp' => 500, 'contract_year' => 2],   // +5% from year 2
            ['kind' => 'amount', 'amount_minor' => 100000, 'effective_on' => '2029-01-01'],   // +1,000 from 2029
        ]]);
        $amounts = $lease->scheduleLines->map(fn ($l) => $l->amount_minor->minor())->all();
        $this->assertSame([6000000, 6300000, 6400000], $amounts);
        $this->assertSame(2, RentEscalation::where('status', 'applied')->count());
    }

    public function test_req_0201_riyadh_freeze_suspends_escalations_in_existing_and_new_leases_with_a_reason(): void
    {
        $this->property->update(['region' => 'riyadh', 'city' => 'riyadh', 'within_urban_boundary' => true]);
        $lease = $this->leaseFor('101', ['duration_count' => 2, 'payment_frequency' => 'annual', 'escalations' => [['kind' => 'percentage', 'percentage_bp' => 500, 'contract_year' => 2]]]);
        $this->assertSame([6000000, 6000000], $lease->scheduleLines->map(fn ($l) => $l->amount_minor->minor())->all());
        $e = RentEscalation::first();
        $this->assertSame('suspended', $e->status);
        $this->assertStringContainsString('الرياض', $e->suspension_reason);

        // Outside the urban boundary the freeze does not apply.
        $this->property->update(['within_urban_boundary' => false]);
        $e->forceFill(['status' => 'scheduled'])->save();
        app(ScheduleGenerator::class)->generate($lease->refresh());
        $this->assertSame(6300000, $lease->fresh()->scheduleLines[1]->amount_minor->minor());
    }

    public function test_req_0198_renewal_rent_cannot_exceed_last_registered_rent_inside_the_freeze(): void
    {
        $this->property->update(['region' => 'riyadh', 'city' => 'riyadh', 'within_urban_boundary' => true]);
        $lease = $this->leaseFor();
        $this->svc->sendForRegistration($lease);
        $this->svc->markAwaitingParties($lease);
        $this->svc->register($lease, 'EJ-1');
        $this->svc->activate($lease);
        try {
            $this->svc->renew($lease->fresh(), Money::parse('63000'));
            $this->fail('higher renewal accepted in freeze');
        } catch (LeasingException) {
        }
        $renewal = $this->svc->renew($lease->fresh(), Money::parse('60000'));
        $this->assertSame('renewal', $renewal->kind);
        $this->assertSame('2028-01-01', $renewal->starts_on->toDateString());
        $this->assertSame('renewed', $lease->fresh()->status);
    }

    public function test_req_0197_vacant_unit_price_is_fixed_at_last_registered_rent_inside_the_freeze(): void
    {
        $this->property->update(['region' => 'riyadh', 'city' => 'riyadh', 'within_urban_boundary' => true]);
        $this->unit('102')->forceFill(['last_registered_rent_minor' => Money::parse('50000')])->save();
        $this->expectException(LeasingException::class);
        $this->offer('102', ['annual_rent_minor' => Money::parse('55000')]);
    }

    public function test_req_0200_extending_the_freeze_to_another_city_is_a_new_row_not_code(): void
    {
        $this->assertNull(app(\App\Leasing\PolicyService::class)->freezeFor($this->property, 'residential', CarbonImmutable::parse('2027-01-01')));
        Policy::create(['kind' => 'rent_freeze', 'region' => 'makkah', 'city' => 'jeddah', 'effective_from' => '2026-12-01', 'code' => 'JEDDAH-TEST', 'title_ar' => 'تجميد جدة', 'payload' => ['usages' => ['residential']]]);
        $this->assertNotNull(app(\App\Leasing\PolicyService::class)->freezeFor($this->property, 'residential', CarbonImmutable::parse('2027-01-01')));
        $this->assertNull(app(\App\Leasing\PolicyService::class)->freezeFor($this->property, 'commercial', CarbonImmutable::parse('2027-01-01')));
    }

    public function test_req_0196_seeded_policies_come_from_the_spec_and_are_flagged_for_official_check(): void
    {
        $f = Policy::where('code', 'RIYADH-RENT-FREEZE-2025')->firstOrFail();
        $this->assertSame('2025-09-25', $f->effective_from->toDateString());
        $this->assertSame(['existing', 'new'], $f->payload['applies_to']);   // REQ-0678
        $this->assertTrue($f->needs_official_check);
        $this->assertSame(3, Policy::where('kind', 'non_renewal_reason')->count());
        $this->assertSame(0, Policy::where('kind', 'ejar_fee')->count());   // REQ-0204: amounts not in spec, none invented
    }

    public function test_req_0195_lessor_refusal_needs_a_reason_code_and_document_in_riyadh(): void
    {
        $this->property->update(['region' => 'riyadh', 'city' => 'riyadh']);
        $lease = $this->leaseFor();
        $lease->forceFill(['status' => 'active'])->save();
        $on = CarbonImmutable::parse('2027-09-01');
        foreach ([[null, null], ['RIYADH-NR-NONPAYMENT', null], ['MADE-UP', 'doc.pdf']] as [$code, $doc]) {
            try {
                $this->svc->giveNotice($lease, 'lessor', $on, $code, $doc);
                $this->fail('refusal accepted without code+document');
            } catch (LeasingException) {
            }
        }
        $this->svc->giveNotice($lease, 'lessor', $on, 'RIYADH-NR-NONPAYMENT', 'docs/report.pdf');
        $this->assertSame('notice_given', $lease->fresh()->status);
    }

    public function test_req_0191_auto_renewal_unless_notice_before_the_60_day_deadline(): void
    {
        $lease = $this->leaseFor();
        $lease->forceFill(['status' => 'active'])->save();
        $this->assertSame('2027-11-01', $this->svc->noticeDeadline($lease)->toDateString());   // 2027-12-31 − 60 days
        $this->expectException(LeasingException::class);
        $this->svc->giveNotice($lease, 'tenant', CarbonImmutable::parse('2027-11-15'));
    }

    public function test_req_0194_renewals_board_groups_leases_by_30_60_90_180_days(): void
    {
        $lease = $this->leaseFor();
        $lease->forceFill(['status' => 'active'])->save();
        $board = $this->svc->renewalsBoard(CarbonImmutable::parse('2027-10-15'));
        $this->assertSame([], $board[30]);
        $this->assertCount(1, $board[90]);
        $this->assertCount(1, $board[180]);
        $this->assertSame('2027-11-01', $board[90][0]['notice_deadline']);
    }

    public function test_req_0202_sublease_rent_cannot_exceed_the_original(): void
    {
        $lease = $this->leaseFor();
        $sub = Tenant::create(['kind' => 'company', 'name_ar' => 'شركة']);
        $this->svc->createSublease($lease, $sub, Money::parse('60000'), CarbonImmutable::parse('2027-03-01'), CarbonImmutable::parse('2027-12-31'));
        $this->expectException(LeasingException::class);
        $this->svc->createSublease($lease, $sub, Money::parse('60000.01'), CarbonImmutable::parse('2027-03-01'), CarbonImmutable::parse('2027-12-31'));
    }

    public function test_req_0172_daily_and_weekly_letting_waits_for_phase_3(): void
    {
        $this->expectException(LeasingException::class);
        $this->offer('101', ['duration_unit' => 'day', 'duration_count' => 3]);
    }

    public function test_req_0180_availability_grid_shows_leased_held_vacant_and_vacating(): void
    {
        $lease = $this->leaseFor('101');
        $o = $this->svc->submitOffer($this->offer('102', ['starts_on' => '2027-01-10']));
        $this->svc->sendOffer($o);   // tentative hold
        $lease->forceFill(['status' => 'notice_given'])->save();
        $grid = $this->svc->availability($this->property->id, CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-01-12'));
        $this->assertSame('leased', $grid['101']['days']['2027-01-05']);
        $this->assertSame('vacant', $grid['102']['days']['2027-01-05']);
        $this->assertSame('held', $grid['102']['days']['2027-01-11']);
        $this->assertSame('confirmed', $grid['101']['vacating']['confidence']);
        $this->assertSame('leased', $grid['ALL']['days']['2027-01-05']);   // the composite shares 101's space
    }

    public function test_req_0189_deposit_is_kept_as_a_liability_amount_on_the_lease_not_as_rent(): void
    {
        $lease = $this->leaseFor();
        $this->assertSame(500000, $lease->deposit_minor->minor());
        $this->assertSame(6000000, Money::sum($lease->scheduleLines->pluck('amount_minor')->all())->minor());   // deposit not in rent schedule
    }

    public function test_req_0168_leases_and_schedule_lines_are_never_deleted(): void
    {
        $lease = $this->leaseFor();
        try {
            DB::transaction(fn () => Lease::whereKey($lease->id)->delete());
            $this->fail('lease deleted');
        } catch (\Illuminate\Database\QueryException) {
        }
        $this->expectException(\Illuminate\Database\QueryException::class);
        BillingScheduleLine::where('lease_id', $lease->id)->delete();
    }
}
