<?php

namespace Tests\Feature\Finance;

use App\Approvals\ApprovalExecutor;
use App\Filament\Manager\Pages\OwnerStatementPage;
use App\Filament\Manager\Resources\ApprovalRequests\Pages\ListApprovalRequests;
use App\Filament\Manager\Resources\Payments\Pages\CreatePayment;
use App\Finance\FinanceException;
use App\Finance\InvoiceService;
use App\Finance\PaymentService;
use App\Finance\TrustAccounting;
use App\Leasing\LeasingService;
use App\Maintenance\MaintenanceException;
use App\Maintenance\MaintenanceService;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\LeasableUnit;
use App\Models\Lease;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Properties\BuildingWizard;
use App\Settings\Settings;
use App\Support\Money\Money;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M22 trust safety, from the audit of 2026-11-24 (section 7 closing example of the Riyadh operating guide):
 *  - REQ-0316 / REQ-0188: the same external payment transaction is recorded once — a service check plus a partial
 *    unique index, so concurrent requests cannot both land; changing the tenant or the method does not get around it;
 *    two genuine payments for one instalment still work.
 *  - REQ-0069 / REQ-0428 / REQ-0429 / REQ-0431: paying out of trust (owner transfer or maintenance) is checked against
 *    THAT owner's available trust balance, under a per-owner lock, in the same transaction as the posting; the
 *    statement shows what was transferred and what is still available; rent the owner collected directly is not trust.
 *  - REQ-0130: on a property with several owners, maintenance cost and deposit deductions are no longer charged to
 *    the first owner on file; the employee is told why before anything is posted.
 */
class TrustSafetyM22Test extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $accountant;
    private Tenant $tRes;
    private Tenant $tCom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-12-01 09:00:00');
        $s = $this->makeSubscriber('office');
        $this->manager = $this->makeUser($s, 'manager');
        $this->accountant = $this->makeUser($s, 'accountant');
        $this->tenant()->set($s->id);
        $this->manager->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->accountant->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        app(Settings::class)->setSubscriber('finance.vat_registered', true);
        $this->tRes = Tenant::create(['kind' => 'individual', 'name_ar' => 'مستأجر الشقة']);
        $this->tCom = Tenant::create(['kind' => 'company', 'name_ar' => 'مستأجر المحل', 'vat_number' => '311111111100003']);
        Filament::setCurrentPanel(Filament::getPanel('manager'));
    }

    private function clock(string $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    /** @return array{0: Property, 1: LeasableUnit, 2: LeasableUnit} [property, apartment, shop] */
    private function building(array $owners): array
    {
        $w = app(BuildingWizard::class);
        $p = $w->createProperty(Portfolio::create(['name_ar' => 'م']), ['name_ar' => 'عمارة الرياض', 'region' => 'riyadh', 'city' => 'riyadh', 'within_urban_boundary' => true], $owners, 'mixed_building');
        $w->createAgreement($owners[0]['owner'], [$p], ['fee_type' => 'percentage', 'fee_percentage_bp' => 500, 'collector' => 'manager', 'starts_on' => '2027-01-01']);
        $w->generate($p, ['name_ar' => 'A', 'floors' => [['number' => 0, 'units' => [['type' => 'shop', 'count' => 1]]], ['number' => 1, 'units' => [['type' => 'apartment', 'count' => 1]]]]]);
        $units = LeasableUnit::where('property_id', $p->id)->get();
        return [$p, $units->firstWhere('unit_type', 'apartment'), $units->firstWhere('unit_type', 'shop')];
    }

    private function lease(LeasableUnit $unit, Tenant $t, string $usage, string $annual): Lease
    {
        $svc = app(LeasingService::class);
        $o = $svc->createOffer($this->manager, $unit, $t, ['usage' => $usage, 'starts_on' => '2027-01-01', 'duration_unit' => 'year', 'duration_count' => 1,
            'annual_rent_minor' => Money::parse($annual), 'payment_frequency' => 'monthly']);
        $lease = $svc->acceptOffer($svc->sendOffer($svc->submitOffer($o)));
        $svc->sendForRegistration($lease);
        $svc->markAwaitingParties($lease);
        $svc->register($lease, 'EJ-' . $unit->id);
        $svc->activate($lease);
        return $lease->fresh();
    }

    private function firstInvoice(Lease $l): Invoice
    {
        return app(InvoiceService::class)->issueRent($l->scheduleLines->sortBy('period_start')->first(), CarbonImmutable::parse('2027-01-01'));
    }

    /**
     * Section 7 of the guide: flat 8,000 paid straight to the owner, shop 11,500 (10,000 + 1,500 VAT) paid to the office,
     * maintenance 2,300 from trust, fee 5% of 18,000 = 900 + 135 VAT deducted → 8,165 left for the owner.
     */
    private function closingMonth(bool $shopPaidToOwner = false, bool $withMaintenance = true): array
    {
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك المثال', 'vat_registered' => true, 'vat_number' => '300000000000043']);
        [$p, $apt, $shop] = $this->building([['owner' => $owner, 'numerator' => 1, 'denominator' => 1]]);
        $res = $this->lease($apt, $this->tRes, 'residential', '96000');
        $com = $this->lease($shop, $this->tCom, 'commercial', '120000');
        $this->clock('2027-01-20 09:00:00');
        $this->firstInvoice($res);
        $this->firstInvoice($com);
        $pay = app(PaymentService::class);
        $pay->record($this->tRes, Money::parse('8000'), 'mada', CarbonImmutable::parse('2027-01-05'), 'owner', reference: 'EJR-RES-0001');
        $pay->record($this->tCom, Money::parse('11500'), 'bank_transfer', CarbonImmutable::parse('2027-01-06'), $shopPaidToOwner ? 'owner' : 'manager', reference: 'FT27006-11500');
        if ($withMaintenance) {
            $req = app(TrustAccounting::class)->requestExpense($this->accountant, $owner, $p->id, Money::parse('2300'), 'صيانة مدفوعة', 'WO-1', CarbonImmutable::parse('2027-01-10'));
            if ($req->fresh()->status === 'pending') {
                app(ApprovalExecutor::class)->approve($req, $this->manager);
            }
        }
        return [$owner, $p];
    }

    private function disbursementsPosted(Owner $owner): int
    {
        return JournalEntry::where('kind', 'owner.disbursement')->where('owner_id', $owner->id)->count();
    }

    // ───────────────────────── 1. Duplicate collection ─────────────────────────

    public function test_req_0316_the_same_external_transaction_is_recorded_once_even_with_another_tenant_method_or_spelling(): void
    {
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'م']);
        [, $apt] = $this->building([['owner' => $owner, 'numerator' => 1, 'denominator' => 1]]);
        $lease = $this->lease($apt, $this->tRes, 'residential', '96000');
        $this->clock('2027-01-03 09:00:00');
        $inv = $this->firstInvoice($lease);
        $svc = app(PaymentService::class);
        $first = $svc->record($this->tRes, Money::parse('4000'), 'mada', CarbonImmutable::parse('2027-01-05'), 'owner', reference: 'EJR-778901');
        $this->assertSame('4000.00', app(InvoiceService::class)->outstanding($inv->fresh())->toDecimalString());
        // the same transaction typed again: other tenant, other method, other spelling (spaces, lower case, Arabic digits)
        foreach ([[$this->tRes, 'mada', 'EJR-778901'], [$this->tCom, 'sadad', 'ejr 778901'], [$this->tRes, 'cash', 'EJR-٧٧٨٩٠١']] as [$tenant, $method, $ref]) {
            try {
                $svc->record($tenant, Money::parse('4000'), $method, CarbonImmutable::parse('2027-01-05'), 'owner', reference: $ref);
                $this->fail("duplicate accepted: $ref");
            } catch (FinanceException $e) {
                $this->assertStringContainsString($first->receipt_number, $e->getMessage());   // the employee sees which receipt holds it
            }
        }
        $this->assertSame(1, Payment::count());
        $this->assertSame('4000.00', app(InvoiceService::class)->outstanding($inv->fresh())->toDecimalString());   // changed once
    }

    public function test_req_0316_the_database_refuses_a_duplicate_even_when_two_requests_pass_the_check_together(): void
    {
        $svc = app(PaymentService::class);
        $p = $svc->record($this->tRes, Money::parse('500'), 'bank_transfer', CarbonImmutable::parse('2026-12-01'), 'manager', reference: 'FT-1');
        // a second request that passed the service check at the same moment ends up as this insert
        $this->expectException(QueryException::class);
        DB::transaction(fn () => Payment::create(['tenant_id' => $this->tCom->id, 'receipt_number' => 'RACE-2', 'amount_minor' => Money::parse('500'), 'method' => 'mada', 'source' => 'manual',
            'received_on' => '2026-12-01', 'reference' => 'ft 1', 'external_key' => $p->external_key, 'paid_to' => 'manager']));
    }

    public function test_req_0188_two_genuine_payments_for_one_instalment_still_work(): void
    {
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'م']);
        [, $apt] = $this->building([['owner' => $owner, 'numerator' => 1, 'denominator' => 1]]);
        $lease = $this->lease($apt, $this->tRes, 'residential', '96000');
        $this->clock('2027-01-03 09:00:00');
        $inv = $this->firstInvoice($lease);
        $svc = app(PaymentService::class);
        $svc->record($this->tRes, Money::parse('3000'), 'mada', CarbonImmutable::parse('2027-01-05'), 'owner', reference: 'EJR-1001');
        $svc->record($this->tRes, Money::parse('3000'), 'mada', CarbonImmutable::parse('2027-01-05'), 'owner', reference: 'EJR-1002');   // same amount, same day, other transaction
        $svc->record($this->tRes, Money::parse('1000'), 'cash', CarbonImmutable::parse('2027-01-06'), 'owner');                           // cash without a reference
        $svc->record($this->tRes, Money::parse('1000'), 'cash', CarbonImmutable::parse('2027-01-06'), 'owner');
        $this->assertSame(4, Payment::count());
        $this->assertTrue(app(InvoiceService::class)->outstanding($inv->fresh())->isZero());
    }

    public function test_req_0316_a_reversed_payment_can_be_recorded_again_correctly(): void
    {
        $svc = app(PaymentService::class);
        $p = $svc->record($this->tRes, Money::parse('700'), 'bank_transfer', CarbonImmutable::parse('2026-12-01'), 'manager', reference: 'FT-77');
        app(ApprovalExecutor::class)->approve($svc->requestReversal($p, $this->accountant, 'سُجّلت على المستأجر الخطأ'), $this->manager);
        $again = $svc->record($this->tCom, Money::parse('700'), 'bank_transfer', CarbonImmutable::parse('2026-12-01'), 'manager', reference: 'FT-77');
        $this->assertSame('recorded', $again->status);
    }

    public function test_req_0316_from_the_payment_screen_the_second_entry_is_refused_and_the_invoice_moves_once(): void
    {
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'م']);
        [, $apt] = $this->building([['owner' => $owner, 'numerator' => 1, 'denominator' => 1]]);
        $lease = $this->lease($apt, $this->tRes, 'residential', '96000');
        $this->clock('2027-01-03 09:00:00');
        $inv = $this->firstInvoice($lease);
        $this->actingAs($this->accountant);
        $form = ['tenant_id' => $this->tRes->id, 'amount_minor' => '8000', 'method' => 'mada', 'received_on' => '2027-01-05', 'paid_to' => 'owner', 'reference' => 'EJR-55001'];
        Livewire::test(CreatePayment::class)->fillForm($form)->call('create')->assertHasNoFormErrors();
        $this->assertTrue(app(InvoiceService::class)->outstanding($inv->fresh())->isZero());
        Livewire::test(CreatePayment::class)->fillForm(['method' => 'bank_transfer', 'tenant_id' => $this->tCom->id] + $form)->call('create')->assertHasFormErrors(['reference']);
        $this->assertSame(1, Payment::count());
        $this->assertSame(0, \App\Models\PaymentAllocation::count() - 1);
        $this->assertTrue(app(InvoiceService::class)->outstanding($inv->fresh())->isZero());
    }

    // ───────────────────────── 2. Over-disbursement ─────────────────────────

    public function test_req_0069_two_transfers_of_8165_on_an_available_8165_post_once(): void
    {
        [$owner] = $this->closingMonth();
        $trust = app(TrustAccounting::class);
        $this->assertSame('8165.00', $trust->availableBalance($owner)->toDecimalString());
        $a = $trust->requestDisbursement($this->accountant, $owner, Money::parse('8165'), CarbonImmutable::parse('2027-01-31'));
        $b = $trust->requestDisbursement($this->accountant, $owner, Money::parse('8165'), CarbonImmutable::parse('2027-01-31'));
        app(ApprovalExecutor::class)->approve($a, $this->manager);
        try {
            app(ApprovalExecutor::class)->approve($b, $this->manager);
            $this->fail('the second transfer was posted');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('لا يكفي', $e->getMessage());
        }
        $this->assertSame(['approved', 'pending'], [$a->fresh()->status, $b->fresh()->status]);   // nothing half-done: it waits or is rejected
        $this->assertSame(1, $this->disbursementsPosted($owner));
        $s = $trust->statement($owner, CarbonImmutable::parse('2027-01-01'));
        $this->assertSame(['8165.00', '8165.00', '0.00'], [$s['net_to_owner']->toDecimalString(), $s['disbursed']->toDecimalString(), $s['available']->toDecimalString()]);
    }

    public function test_req_0069_the_same_approval_cannot_be_executed_twice(): void
    {
        [$owner] = $this->closingMonth();
        $a = app(TrustAccounting::class)->requestDisbursement($this->accountant, $owner, Money::parse('1000'), CarbonImmutable::parse('2027-01-31'));
        $stale = ApprovalRequest::find($a->id);   // a second checker's screen still shows it pending
        app(ApprovalExecutor::class)->approve($a, $this->manager);
        $second = $this->makeUser(\App\Models\Subscriber::find($this->manager->subscriber_id), 'manager');
        $this->tenant()->set($this->manager->subscriber_id);
        try {
            app(ApprovalExecutor::class)->approve($stale, $second);
            $this->fail('approved twice');
        } catch (\App\Approvals\ApprovalException) {
        }
        $this->assertSame(1, $this->disbursementsPosted($owner));
    }

    public function test_req_0069_paying_out_of_trust_waits_for_the_owners_lock(): void
    {
        // no maintenance posting here: this test's own connection must not already hold the owner's lock
        [$owner] = $this->closingMonth(withMaintenance: false);
        $a = app(TrustAccounting::class)->requestDisbursement($this->accountant, $owner, Money::parse('8165'), CarbonImmutable::parse('2027-01-31'));
        // another session (a second checker approving at the same moment) holds this owner's lock
        $cfg = config('database.connections.pgsql');
        $other = new \PDO("pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']}", $cfg['username'], $cfg['password']);
        $this->assertTrue((bool) $other->query('select pg_try_advisory_lock(' . TrustAccounting::LOCK_NAMESPACE . ', ' . (int) $owner->id . ')')->fetchColumn());
        DB::statement("set lock_timeout = '300ms'");
        try {
            app(ApprovalExecutor::class)->approve($a, $this->manager);
            $this->fail('posted without waiting for the lock');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode());   // lock_not_available: the balance is never read while another posting holds it
        } finally {
            DB::statement('reset lock_timeout');
            $other->query('select pg_advisory_unlock_all()');
        }
        $this->assertSame(0, $this->disbursementsPosted($owner));
        app(ApprovalExecutor::class)->approve($a->fresh(), $this->manager);   // once released it goes through
        $this->assertSame(1, $this->disbursementsPosted($owner));
    }

    public function test_req_0069_maintenance_from_an_insufficient_balance_is_refused_and_nothing_is_posted(): void
    {
        [$owner, $p] = $this->closingMonth(shopPaidToOwner: true, withMaintenance: false);   // all rent went to the owner directly
        $trust = app(TrustAccounting::class);
        // 19,500 collected by the owner is not trust money; the office holds nothing and is owed its fee (900 + 135 VAT)
        $this->assertSame('-1035.00', $trust->availableBalance($owner)->toDecimalString());
        $req = $trust->requestExpense($this->accountant, $owner, $p->id, Money::parse('2300'), 'صيانة', 'WO-9', CarbonImmutable::parse('2027-01-10'));
        try {
            app(ApprovalExecutor::class)->approve($req, $this->manager);
            $this->fail('maintenance paid from an empty trust balance');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('لا يكفي', $e->getMessage());
        }
        $this->assertSame('pending', $req->fresh()->status);
        $this->assertSame(0, JournalEntry::where('kind', 'expense.maintenance')->count());
        // a transfer is refused at once, before it reaches the approval queue
        $this->expectException(FinanceException::class);
        $trust->requestDisbursement($this->accountant, $owner, Money::parse('1'), CarbonImmutable::parse('2027-01-31'));
    }

    public function test_req_0431_from_the_screens_two_transfers_of_8165_one_posts_and_the_statement_shows_it(): void
    {
        [$owner] = $this->closingMonth();
        $this->actingAs($this->accountant);
        $page = Livewire::test(OwnerStatementPage::class)->set('data.owner_id', $owner->id)->set('data.month', '2027-01')->call('show');
        $this->assertSame(['8,165.00', '8,165.00'], [preg_replace('/[^\d.,]/u', '', $page->get('statement')['net']), preg_replace('/[^\d.,]/u', '', $page->get('statement')['available_now'])]);
        $page->callAction('disburse', data: ['amount' => '8165'])->callAction('disburse', data: ['amount' => '8165']);
        $reqs = ApprovalRequest::where('action', 'trust.disburse')->whereNull('payload->cost_minor')->orderBy('id')->get();
        $this->assertCount(2, $reqs);
        $this->actingAs($this->manager);
        Livewire::test(ListApprovalRequests::class)->callTableAction('approve', $reqs[0])->callTableAction('approve', $reqs[1]);
        $this->assertSame(['approved', 'pending'], [$reqs[0]->fresh()->status, $reqs[1]->fresh()->status]);
        $this->assertSame(1, $this->disbursementsPosted($owner));
        $this->actingAs($this->accountant);
        $s = Livewire::test(OwnerStatementPage::class)->set('data.owner_id', $owner->id)->set('data.month', '2027-01')->call('show')->get('statement');
        $this->assertSame(['8,165.00', '0.00'], [preg_replace('/[^\d.,]/u', '', $s['disbursed']), preg_replace('/[^\d.,]/u', '', $s['available'])]);
    }

    // ───────────────────────── 3. Several owners ─────────────────────────

    public function test_req_0130_maintenance_on_a_property_with_several_owners_is_not_charged_to_the_first_owner(): void
    {
        $h = [Owner::create(['kind' => 'individual', 'name_ar' => 'وارث 1']), Owner::create(['kind' => 'individual', 'name_ar' => 'وارث 2']), Owner::create(['kind' => 'individual', 'name_ar' => 'وارث 3'])];
        [$p] = $this->building([['owner' => $h[0], 'numerator' => 1, 'denominator' => 2], ['owner' => $h[1], 'numerator' => 1, 'denominator' => 3], ['owner' => $h[2], 'numerator' => 1, 'denominator' => 6]]);
        $svc = app(MaintenanceService::class);
        $wo = $svc->report('corrective', $p->id, 'تسريب', 'tenant', null, null, null, ['leak.jpg']);
        $svc->triage($wo, 'normal');
        $svc->estimate($wo, Money::parse('300'));
        $svc->assign($wo, $this->makeUser(\App\Models\Subscriber::find($this->manager->subscriber_id), 'technician'));
        $this->tenant()->set($this->manager->subscriber_id);
        $svc->start($wo, ['b.jpg']);
        $svc->addCost($wo, 'labour', 'عمالة', 1, Money::parse('300'));
        $svc->complete($wo, ['a.jpg']);
        try {
            $svc->close($wo->fresh(), $this->manager);
            $this->fail('cost charged on a multi-owner property');
        } catch (MaintenanceException $e) {
            $this->assertStringContainsString('أكثر من مالك', $e->getMessage());
        }
        $this->assertNotSame('closed', $wo->fresh()->status);
        $this->assertSame(0, ApprovalRequest::where('action', 'trust.disburse')->count());
        $this->assertSame(0, JournalEntry::where('kind', 'expense.maintenance')->count());
        // the service path refuses too (a request made outside the work order)
        $this->expectException(FinanceException::class);
        app(TrustAccounting::class)->requestExpense($this->accountant, $h[0], $p->id, Money::parse('100'), 'x', null, CarbonImmutable::parse('2027-01-10'));
    }

    public function test_req_0130_deposit_deductions_on_a_property_with_several_owners_wait_and_a_full_refund_names_no_owner(): void
    {
        $h = [Owner::create(['kind' => 'individual', 'name_ar' => 'شريك 1']), Owner::create(['kind' => 'individual', 'name_ar' => 'شريك 2'])];
        [, $apt] = $this->building([['owner' => $h[0], 'numerator' => 1, 'denominator' => 2], ['owner' => $h[1], 'numerator' => 1, 'denominator' => 2]]);
        $lease = $this->lease($apt, $this->tRes, 'residential', '48000');
        $pay = app(PaymentService::class);
        $pay->recordDeposit($lease, Money::parse('3000'), CarbonImmutable::parse('2027-01-01'), 'manager');
        try {
            $pay->requestDepositRefund($lease, $this->accountant, [['description' => 'تلف', 'amount' => Money::parse('700'), 'document' => 'docs/d.pdf']], CarbonImmutable::parse('2027-12-31'));
            $this->fail('deduction credited to the first owner');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('أكثر من مالك', $e->getMessage());
        }
        $this->assertSame(0, ApprovalRequest::where('action', 'deposit.refund')->count());
        $req = $pay->requestDepositRefund($lease, $this->accountant, [], CarbonImmutable::parse('2027-12-31'));
        app(ApprovalExecutor::class)->approve($req, $this->manager);
        $e = JournalEntry::where('kind', 'deposit.refund')->firstOrFail();
        $this->assertNull($e->owner_id);   // the whole deposit back to the tenant: no owner is credited
    }
}
