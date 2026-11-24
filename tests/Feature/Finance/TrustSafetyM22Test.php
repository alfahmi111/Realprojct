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
        $pay->record($this->tRes, Money::parse('8000'), 'mada', CarbonImmutable::parse('2027-01-05'), 'owner', reference: 'EJR-RES-0001', refSource: 'ejar');
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

    // ───────────────────────── 1. Duplicate collection (Q38: unique per source; incomplete → pending) ─────────────────────────

    public function test_req_0316_q38_the_same_transaction_is_refused_whatever_tenant_method_account_or_spelling(): void
    {
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'م']);
        [, $apt] = $this->building([['owner' => $owner, 'numerator' => 1, 'denominator' => 1]]);
        $lease = $this->lease($apt, $this->tRes, 'residential', '96000');
        $this->clock('2027-01-03 09:00:00');
        $inv = $this->firstInvoice($lease);
        $svc = app(PaymentService::class);
        $first = $svc->record($this->tRes, Money::parse('4000'), 'mada', CarbonImmutable::parse('2027-01-05'), 'owner', reference: 'EJR-778901', refSource: 'ejar');
        $this->assertSame('4000.00', app(InvoiceService::class)->outstanding($inv->fresh())->toDecimalString());
        // the same Ejar transaction typed again: same entry; other tenant + other method (SADAD through Ejar) + other spelling;
        // other receiving account + card + Arabic digits
        foreach ([[$this->tRes, 'mada', 'EJR-778901', 'owner'], [$this->tCom, 'sadad', 'ejr 778901', 'owner'], [$this->tRes, 'card', 'EJR-٧٧٨٩٠١', 'manager']] as [$tenant, $method, $ref, $paidTo]) {
            try {
                $svc->record($tenant, Money::parse('4000'), $method, CarbonImmutable::parse('2027-01-05'), $paidTo, reference: $ref, refSource: 'ejar');
                $this->fail("duplicate accepted: $ref ($method, $paidTo)");
            } catch (FinanceException $e) {
                $this->assertStringContainsString($first->receipt_number, $e->getMessage());   // the employee sees which receipt holds it
            }
        }
        $this->assertSame(1, Payment::count());
        $this->assertSame('4000.00', app(InvoiceService::class)->outstanding($inv->fresh())->toDecimalString());   // changed once
    }

    public function test_req_0316_q38_two_real_transactions_from_different_sources_with_the_same_number_are_both_recorded(): void
    {
        $svc = app(PaymentService::class);
        $a = $svc->record($this->tRes, Money::parse('500'), 'mada', CarbonImmutable::parse('2026-12-01'), 'owner', reference: '445566', refSource: 'ejar');
        $b = $svc->record($this->tCom, Money::parse('900'), 'bank_transfer', CarbonImmutable::parse('2026-12-01'), 'manager', reference: '445566');   // the bank's own number
        $c = $svc->record($this->tCom, Money::parse('300'), 'sadad', CarbonImmutable::parse('2026-12-01'), 'manager', reference: '4455-66', refSource: 'sadad');
        $this->assertSame([['ejar', 'recorded'], ['bank', 'recorded'], ['sadad', 'recorded']], [[$a->ref_source, $a->status], [$b->ref_source, $b->status], [$c->ref_source, $c->status]]);
        // a source the method cannot carry is refused, so «changing the source» is not a free way around the rule
        $this->expectException(FinanceException::class);
        $svc->record($this->tRes, Money::parse('500'), 'bank_transfer', CarbonImmutable::parse('2026-12-01'), 'owner', reference: '445566', refSource: 'ejar');
    }

    public function test_req_0316_the_database_refuses_a_duplicate_even_when_two_requests_pass_the_check_together(): void
    {
        $svc = app(PaymentService::class);
        $p = $svc->record($this->tRes, Money::parse('500'), 'bank_transfer', CarbonImmutable::parse('2026-12-01'), 'manager', reference: 'FT-1');
        // a second request that passed the service check at the same moment ends up as this insert (other tenant, other account)
        $this->expectException(QueryException::class);
        DB::transaction(fn () => Payment::create(['tenant_id' => $this->tCom->id, 'receipt_number' => 'RACE-2', 'amount_minor' => Money::parse('500'), 'method' => 'bank_transfer', 'source' => 'manual',
            'received_on' => '2026-12-01', 'reference' => 'ft 1', 'external_key' => $p->external_key, 'ref_source' => 'bank', 'paid_to' => 'owner']));
    }

    public function test_req_0316_q38_an_electronic_payment_without_its_reference_waits_with_no_financial_effect_until_matched(): void
    {
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'م']);
        [, $apt] = $this->building([['owner' => $owner, 'numerator' => 1, 'denominator' => 1]]);
        $lease = $this->lease($apt, $this->tRes, 'residential', '96000');
        $this->clock('2027-01-03 09:00:00');
        $inv = $this->firstInvoice($lease);
        $svc = app(PaymentService::class);
        $entries = JournalEntry::count();
        $noRef = $svc->record($this->tRes, Money::parse('8000'), 'bank_transfer', CarbonImmutable::parse('2027-01-05'), 'owner');          // no number
        $noSource = $svc->record($this->tRes, Money::parse('8000'), 'mada', CarbonImmutable::parse('2027-01-05'), 'owner', reference: 'X-99');   // Ejar or the gateway?
        foreach ([$noRef, $noSource] as $p) {
            $this->assertSame(['pending_match', null], [$p->status, $p->receipt_number]);
            $this->assertSame(0, $p->allocations()->count());
        }
        $this->assertSame($entries, JournalEntry::count());                                                         // nothing posted
        $this->assertSame('8000.00', app(InvoiceService::class)->outstanding($inv->fresh())->toDecimalString());   // invoice untouched
        $svc->confirmMatch($noRef, 'FT-2027-0105', null, $this->accountant);                                        // the bank is the only source of a transfer
        $this->assertSame('recorded', $noRef->fresh()->status);
        $this->assertNotNull($noRef->fresh()->receipt_number);
        $this->assertTrue(app(InvoiceService::class)->outstanding($inv->fresh())->isZero());
        try {
            $svc->confirmMatch($noSource, 'FT 2027 0105', 'ejar', $this->accountant);   // a different source: not the same transaction, allowed
            $this->assertSame('recorded', $noSource->fresh()->status);
            $svc->discardPending($noSource->fresh(), $this->accountant, 'x');
            $this->fail('a recorded payment was discarded');
        } catch (FinanceException) {
        }
        $pending = $svc->record($this->tCom, Money::parse('100'), 'bank_transfer', CarbonImmutable::parse('2027-01-06'), 'manager');
        $this->expectException(\App\Finance\DuplicatePaymentException::class);
        $svc->confirmMatch($pending, 'ft-2027-0105', null, $this->accountant);   // the number already belongs to a recorded transfer
    }

    public function test_req_0188_two_genuine_payments_for_one_instalment_still_work(): void
    {
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'م']);
        [, $apt] = $this->building([['owner' => $owner, 'numerator' => 1, 'denominator' => 1]]);
        $lease = $this->lease($apt, $this->tRes, 'residential', '96000');
        $this->clock('2027-01-03 09:00:00');
        $inv = $this->firstInvoice($lease);
        $svc = app(PaymentService::class);
        $svc->record($this->tRes, Money::parse('3000'), 'mada', CarbonImmutable::parse('2027-01-05'), 'owner', reference: 'EJR-1001', refSource: 'ejar');
        $svc->record($this->tRes, Money::parse('3000'), 'mada', CarbonImmutable::parse('2027-01-05'), 'owner', reference: 'EJR-1002', refSource: 'ejar');   // same amount, same day, other transaction
        $svc->record($this->tRes, Money::parse('1000'), 'cash', CarbonImmutable::parse('2027-01-06'), 'owner');                                                // cash needs no reference
        $svc->record($this->tRes, Money::parse('1000'), 'cash', CarbonImmutable::parse('2027-01-06'), 'owner');
        $this->assertSame(4, Payment::where('status', 'recorded')->count());
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
        $form = ['tenant_id' => $this->tRes->id, 'amount_minor' => '8000', 'method' => 'mada', 'received_on' => '2027-01-05', 'paid_to' => 'owner', 'ref_source' => 'ejar', 'reference' => 'EJR-55001'];
        Livewire::test(CreatePayment::class)->fillForm($form)->call('create')->assertHasNoFormErrors();
        $this->assertTrue(app(InvoiceService::class)->outstanding($inv->fresh())->isZero());
        // again with another tenant, another receiving account and SADAD-through-Ejar: refused on the reference field
        Livewire::test(CreatePayment::class)->fillForm(['tenant_id' => $this->tCom->id, 'paid_to' => 'manager', 'method' => 'sadad'] + $form)->call('create')->assertHasFormErrors(['reference']);
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, \App\Models\PaymentAllocation::count());
        $this->assertTrue(app(InvoiceService::class)->outstanding($inv->fresh())->isZero());
        // without a number it is kept waiting, and the invoice does not move
        Livewire::test(CreatePayment::class)->fillForm(['reference' => null, 'amount_minor' => '50'] + $form)->call('create')->assertHasNoFormErrors();
        $this->assertSame('pending_match', Payment::latest('id')->first()->status);
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
        // 19,500 collected by the owner is not trust money; the office holds nothing and is owed its fee (900 + 135 VAT),
        // shown apart from the cash: nothing available, 1,035 owed by the owner
        $pos = $trust->position($owner);
        $this->assertSame(['0.00', '1035.00', '0.00', '1035.00'], [$pos['trust_cash']->toDecimalString(), $pos['owed_to_office']->toDecimalString(), $pos['available']->toDecimalString(), $pos['owner_owes']->toDecimalString()]);
        $this->assertSame('0.00', $trust->availableBalance($owner)->toDecimalString());
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

    public function test_req_0189_a_full_refund_on_a_shared_property_leaves_the_deposit_once(): void
    {
        $h = [Owner::create(['kind' => 'individual', 'name_ar' => 'شريك 1']), Owner::create(['kind' => 'individual', 'name_ar' => 'شريك 2'])];
        [, $apt] = $this->building([['owner' => $h[0], 'numerator' => 1, 'denominator' => 2], ['owner' => $h[1], 'numerator' => 1, 'denominator' => 2]]);
        $lease = $this->lease($apt, $this->tRes, 'residential', '48000');
        $pay = app(PaymentService::class);
        $ledger = app(\App\Finance\Ledger::class);
        $pay->recordDeposit($lease, Money::parse('3000'), CarbonImmutable::parse('2027-01-01'), 'manager');
        $trustBefore = $ledger->balance('trust_bank');
        $req = $pay->requestDepositRefund($lease, $this->accountant, [], CarbonImmutable::parse('2027-12-31'));
        try {   // a second request while the first waits is refused
            $pay->requestDepositRefund($lease, $this->accountant, [], CarbonImmutable::parse('2027-12-31'));
            $this->fail('two refund requests for one deposit');
        } catch (\App\Approvals\ApprovalException) {
        }
        app(ApprovalExecutor::class)->approve($req, $this->manager);
        $this->assertSame('0.00', $ledger->balance("deposit_liability:{$lease->id}")->toDecimalString());
        $this->assertSame('3000.00', $trustBefore->minus($ledger->balance('trust_bank'))->toDecimalString());   // out of trust exactly once
        // the deposit's cash was nobody's trust money: neither partner's trust position moved
        foreach ($h as $o) {
            $this->assertSame(0, (int) DB::table('journal_lines')->where('account', 'trust_bank')->where('owner_id', $o->id)->sum(DB::raw('debit_minor - credit_minor')));
        }
        // posting it again (a stale request, or two approved together) finds nothing held and posts nothing
        try {
            $pay->postDepositRefund($lease, Money::zero(), '2027-12-31');
            $this->fail('the deposit was refunded twice');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('لا يوجد تأمين محفوظ', $e->getMessage());
        }
        $this->assertSame(1, JournalEntry::where('kind', 'deposit.refund')->count());
        $this->expectException(FinanceException::class);
        $pay->requestDepositRefund($lease, $this->accountant, [], CarbonImmutable::parse('2027-12-31'));
    }

    // ───────────────────────── 4. Available balance = the owner's trust ledger ─────────────────────────

    public function test_req_0428_available_balance_matches_the_owners_trust_ledger_with_fees_kept_apart(): void
    {
        [$owner] = $this->closingMonth();
        $trust = app(TrustAccounting::class);
        $pos = $trust->position($owner);
        // ledger: 11,500 held − 2,300 maintenance = 9,200 cash; office is owed 900 + 135 VAT; statements: 8,165
        $this->assertSame(['9200.00', '1035.00', '8165.00', '8165.00', '0.00', '0.00'], array_map(fn ($k) => $pos[$k]->toDecimalString(), ['trust_cash', 'owed_to_office', 'available', 'statement_based', 'difference', 'owner_owes']));
        $ledgerCash = (int) DB::table('journal_lines')->where('account', 'trust_bank')->where('owner_id', $owner->id)->sum(DB::raw('debit_minor - credit_minor'));
        $this->assertSame(920000, $ledgerCash);   // the same figure read straight from the journal
        app(ApprovalExecutor::class)->approve($trust->requestDisbursement($this->accountant, $owner, Money::parse('8165'), CarbonImmutable::parse('2027-01-31')), $this->manager);
        $pos = $trust->position($owner);
        // after the transfer only the office's own fee remains in trust for this owner
        $this->assertSame(['1035.00', '1035.00', '0.00', '0.00'], array_map(fn ($k) => $pos[$k]->toDecimalString(), ['trust_cash', 'owed_to_office', 'available', 'difference']));
    }

    public function test_req_0428_a_payment_received_before_its_invoice_moves_to_the_owner_in_the_ledger_when_applied(): void
    {
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'م', 'vat_registered' => true, 'vat_number' => '300000000000053']);
        [, , $shop] = $this->building([['owner' => $owner, 'numerator' => 1, 'denominator' => 1]]);
        $com = $this->lease($shop, $this->tCom, 'commercial', '120000');
        $this->clock('2027-01-20 09:00:00');
        app(PaymentService::class)->record($this->tCom, Money::parse('11500'), 'bank_transfer', CarbonImmutable::parse('2027-01-02'), 'manager', reference: 'FT-EARLY-1');   // before the invoice
        $trust = app(TrustAccounting::class);
        $this->assertSame('0.00', $trust->position($owner)['trust_cash']->toDecimalString());   // still the tenant's credit, nobody's trust money
        $this->firstInvoice($com);                                                                // the credit is applied to it
        $pos = $trust->position($owner);
        $this->assertSame(['11500.00', '575.00', '10925.00', '0.00'], array_map(fn ($k) => $pos[$k]->toDecimalString(), ['trust_cash', 'owed_to_office', 'available', 'difference']));
    }

    // ───────────────────────── 5. Q37 (أ): the owner pays the vendor directly ─────────────────────────

    private function finishedWorkOrder(Property $p, string $cost): WorkOrder
    {
        $svc = app(MaintenanceService::class);
        $wo = $svc->report('corrective', $p->id, 'تسريب', 'tenant', null, null, null, ['leak.jpg']);
        $svc->triage($wo, 'normal');
        $svc->estimate($wo, Money::parse($cost));
        $svc->assign($wo, $this->makeUser(\App\Models\Subscriber::find($this->manager->subscriber_id), 'technician'));
        $this->tenant()->set($this->manager->subscriber_id);
        $svc->start($wo, ['b.jpg']);
        $svc->addCost($wo, 'vendor', 'سبّاك', 1, Money::parse($cost));
        $svc->complete($wo, ['a.jpg']);
        return $wo->fresh();
    }

    public function test_req_0230_q37_the_owner_pays_the_vendor_with_proof_and_trust_is_untouched(): void
    {
        [$owner, $p] = $this->closingMonth(shopPaidToOwner: true, withMaintenance: false);   // nothing in trust for him
        $svc = app(MaintenanceService::class);
        $trust = app(TrustAccounting::class);
        $wo = $this->finishedWorkOrder($p, '2300');
        $svc->close($wo, $this->manager, null, 'owner');
        $wo->refresh();
        $this->assertSame(['closed', 'awaiting_owner_payment', 'owner'], [$wo->status, $wo->payment_status, $wo->paid_by]);   // work done; payment apart
        $this->assertSame(0, ApprovalRequest::where('action', 'trust.disburse')->count());
        try {
            $svc->recordOwnerPayment($wo, $this->accountant, '', CarbonImmutable::parse('2027-01-18'));
            $this->fail('owner payment without proof');
        } catch (MaintenanceException) {
        }
        $before = $trust->position($owner);
        $svc->recordOwnerPayment($wo, $this->accountant, 'work-orders/owner-payments/receipt.pdf', CarbonImmutable::parse('2027-01-18'), 'TRF-778');
        $wo->refresh();
        $this->assertSame(['paid_by_owner', '2027-01-18', '2300.00'], [$wo->payment_status, $wo->owner_paid_on->toDateString(), $wo->owner_payment_amount_minor->toDecimalString()]);
        $after = $trust->position($owner);
        $this->assertSame([$before['trust_cash']->minor(), $before['available']->minor()], [$after['trust_cash']->minor(), $after['available']->minor()]);   // no trust movement
        $s = $trust->statement($owner, CarbonImmutable::parse('2027-01-01'));
        $this->assertSame(['0.00', '2300.00'], [$s['maintenance_total']->toDecimalString(), $s['maintenance_owner_paid_total']->toDecimalString()]);   // listed apart, not deducted
        $this->assertSame(0, JournalEntry::where('kind', 'expense.maintenance')->count());
        $this->expectException(MaintenanceException::class);   // recorded once
        $svc->recordOwnerPayment($wo, $this->accountant, 'x.pdf', CarbonImmutable::parse('2027-01-18'));
    }

    public function test_req_0230_q37_payment_from_trust_shows_its_own_status_and_a_refusal_lets_the_owner_pay(): void
    {
        [$owner, $p] = $this->closingMonth(withMaintenance: false);   // 10,465 available
        $svc = app(MaintenanceService::class);
        $paid = $this->finishedWorkOrder($p, '400');
        $svc->close($paid, $this->accountant);
        $this->assertSame('awaiting_trust_approval', $paid->fresh()->payment_status);
        app(ApprovalExecutor::class)->approve(ApprovalRequest::where('action', 'trust.disburse')->latest('id')->first(), $this->manager);
        $this->assertSame('paid_from_trust', $paid->fresh()->payment_status);
        $refused = $this->finishedWorkOrder($p, '300');
        $svc->close($refused, $this->accountant);
        app(ApprovalExecutor::class)->reject(ApprovalRequest::where('action', 'trust.disburse')->where('status', 'pending')->latest('id')->first(), $this->manager, 'يدفعه المالك');
        $this->assertSame('trust_rejected', $refused->fresh()->payment_status);
        $svc->recordOwnerPayment($refused->fresh(), $this->accountant, 'r.pdf', CarbonImmutable::parse('2027-01-19'));
        $this->assertSame('paid_by_owner', $refused->fresh()->payment_status);
    }
}
