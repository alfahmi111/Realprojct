<?php

namespace Tests\Feature\Finance;

use App\Approvals\DualApproval;
use App\Compliance\NationalAddress;
use App\Finance\FinanceException;
use App\Finance\InvoiceService;
use App\Finance\Ledger;
use App\Finance\PaymentService;
use App\Finance\Reconciliation;
use App\Finance\TrustAccounting;
use App\Finance\VatEngine;
use App\Leasing\LeasingService;
use App\Models\Invoice;
use App\Models\LeasableUnit;
use App\Models\Lease;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\ReconciliationIssue;
use App\Models\Tenant;
use App\Models\User;
use App\Plans\PlanEntitlements;
use App\Plans\PlanLimitException;
use App\Properties\BuildingWizard;
use App\Settings\Settings;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Spec §9 finance — mandatory automated tests for every financial calculation (REQ-0502, REQ-0510).
 * Covered IDs: REQ-0412, REQ-0413, REQ-0414, REQ-0415, REQ-0416, REQ-0417, REQ-0418, REQ-0419, REQ-0420 (VAT),
 * REQ-0421, REQ-0422, REQ-0423, REQ-0424 (e-invoicing via provider), REQ-0427, REQ-0428, REQ-0429, REQ-0430,
 * REQ-0431, REQ-0432, REQ-0433, REQ-0434, REQ-0435 (trust accounting, reconciliation), REQ-0436, REQ-0448,
 * REQ-0142, REQ-0143, REQ-0188, REQ-0300, REQ-0305, REQ-0306, REQ-0316, REQ-0465, REQ-0466, REQ-0574, REQ-0575,
 * REQ-0626, REQ-0627, REQ-0628, REQ-0629, REQ-0630, REQ-0631, REQ-0632, REQ-0633, REQ-0634, REQ-0635, REQ-0636, REQ-0637,
 * REQ-0638, REQ-0639, REQ-0640, REQ-0641, REQ-0642 (plans).
 */
class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;
    private User $manager;
    private Owner $owner;
    private Tenant $tenant;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->makeSubscriber('office');
        $this->accountant = $this->makeUser($s, 'accountant');
        $this->manager = $this->makeUser($s, 'manager');
        $this->tenant()->set($s->id);
        $w = app(BuildingWizard::class);
        $this->owner = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك', 'vat_registered' => true, 'vat_number' => '300000000000003']);
        $p = $w->createProperty(Portfolio::create(['name_ar' => 'م']), ['name_ar' => 'برج', 'region' => 'makkah', 'city' => 'jeddah'], [['owner' => $this->owner, 'numerator' => 1, 'denominator' => 1]], 'mixed_building');
        $w->createAgreement($this->owner, [$p], ['fee_type' => 'percentage', 'fee_percentage_bp' => 750, 'collector' => 'manager', 'starts_on' => '2027-01-01']);
        $w->generate($p, ['name_ar' => 'A', 'floors' => [['number' => 0, 'units' => [['type' => 'shop', 'count' => 1]]], ['number' => 1, 'units' => [['type' => 'apartment', 'count' => 1]]]]]);
        $this->tenant = Tenant::create(['kind' => 'individual', 'name_ar' => 'مستأجر', 'national_address' => ['building_no' => '1234', 'street' => 'ش', 'district' => 'ح', 'city' => 'جدة', 'postal_code' => '23456', 'additional_no' => '7890']]);
        $this->lease = $this->registeredLease('101', 'residential', 'quarterly', '60000');
    }

    private function registeredLease(string $code, string $usage, string $freq, string $annual, ?Tenant $tenant = null): Lease
    {
        $svc = app(LeasingService::class);
        $o = $svc->createOffer($this->manager, LeasableUnit::where('code', $code)->first(), $tenant ?? $this->tenant, [
            'usage' => $usage, 'starts_on' => '2027-01-01', 'duration_unit' => 'year', 'duration_count' => 1,
            'annual_rent_minor' => Money::parse($annual), 'payment_frequency' => $freq,
        ]);
        $lease = $svc->acceptOffer($svc->sendOffer($svc->submitOffer($o)));
        $svc->sendForRegistration($lease);
        $svc->markAwaitingParties($lease);
        $svc->register($lease, 'EJ-' . $code);
        $svc->activate($lease);
        return $lease->fresh();
    }

    private function inv(): InvoiceService
    {
        return app(InvoiceService::class);
    }

    public function test_req_0420_tax_code_comes_from_usage_registration_and_track_not_unit_type(): void
    {
        $v = app(VatEngine::class);
        $d = CarbonImmutable::parse('2027-01-01');
        $this->assertSame(['code' => VatEngine::EXEMPT_RESIDENTIAL, 'rate_bp' => 0], $v->treatment('rent', 'residential', true, 'long', $d));      // REQ-0413
        $this->assertSame(['code' => VatEngine::STANDARD_COMMERCIAL, 'rate_bp' => 1500], $v->treatment('rent', 'commercial', true, 'long', $d));   // REQ-0414
        $this->assertSame(['code' => VatEngine::OUT_OF_SCOPE_UNREGISTERED, 'rate_bp' => 0], $v->treatment('rent', 'commercial', false, 'long', $d)); // unregistered may not charge
        $this->assertSame(1500, $v->treatment('management_fee', null, true, 'long', $d)['rate_bp']);       // REQ-0415
        $this->assertSame(0, $v->treatment('management_fee', null, false, 'long', $d)['rate_bp']);
        $this->assertSame(1500, $v->treatment('maintenance_recharge', null, true, 'long', $d)['rate_bp']); // REQ-0417
        try {
            $v->treatment('rent', 'residential', true, 'short', $d);   // REQ-0416 hospitality — needs tax advice, phase 3
            $this->fail('hospitality treatment decided without advice');
        } catch (FinanceException) {
        }
        $this->assertSame(1500, $v->standardRateBp($d));   // from the policies table, not code
    }

    public function test_req_0185_req_0424_rent_invoice_carries_required_fields_and_tax_code_per_line(): void
    {
        $line = $this->lease->scheduleLines->first();
        $invoice = $this->inv()->issueRent($line, CarbonImmutable::parse('2027-01-01'));
        $this->assertSame('EJ-101', $invoice->ejar_contract_number);
        $this->assertSame('101', $invoice->unit_code);
        $this->assertSame('2027-01-01', $invoice->period_start->toDateString());
        $this->assertSame('2027-03-31', $invoice->period_end->toDateString());
        $this->assertSame('23456', $invoice->buyer_national_address['postal_code']);
        $this->assertSame(1500000, $invoice->subtotal_minor->minor());
        $this->assertSame(0, $invoice->tax_minor->minor());   // residential: exempt
        $this->assertSame(VatEngine::EXEMPT_RESIDENTIAL, $invoice->lines->first()->tax_code);
        $this->assertSame('pending', $invoice->einvoice_status);   // no provider chosen → pending, never fake "cleared"
        $this->assertSame('b2c', $invoice->einvoice_type);
        $this->expectException(FinanceException::class);
        $this->inv()->issueRent($line->fresh());   // a line is invoiced once
    }

    public function test_req_0414_commercial_rent_to_a_company_is_b2b_with_15_percent(): void
    {
        $company = Tenant::create(['kind' => 'company', 'name_ar' => 'شركة', 'vat_number' => '310000000000003', 'national_address' => $this->tenant->national_address]);
        $lease = $this->registeredLease('1', 'commercial', 'annual', '100000.01', $company);
        config(['integrations.einvoicing' => 'simulator']);
        $this->app->forgetInstance(\App\Integrations\EInvoicing\EInvoicingGateway::class);
        $this->app->forgetInstance(InvoiceService::class);
        $invoice = $this->inv()->issueRent($lease->scheduleLines->first(), CarbonImmutable::parse('2027-01-01'));
        $this->assertSame('b2b', $invoice->einvoice_type);
        $this->assertSame(1500000, $invoice->tax_minor->minor());   // 15% of 100,000.01 = 15,000.0015 → 15,000.00
        $this->assertSame(11500001, $invoice->total_minor->minor());
        $this->assertSame('cleared', $invoice->einvoice_status);    // REQ-0422: B2B cleared immediately (simulator)
        $this->assertSame('310000000000003', $invoice->buyer_vat_number);
    }

    public function test_req_0185_no_rent_invoice_for_an_unregistered_lease(): void
    {
        $svc = app(LeasingService::class);
        $o = $svc->createOffer($this->manager, LeasableUnit::where('code', '1')->first(), $this->tenant, ['usage' => 'commercial', 'starts_on' => '2027-01-01', 'duration_unit' => 'year', 'duration_count' => 1, 'annual_rent_minor' => Money::parse('10000'), 'payment_frequency' => 'annual']);
        $draft = $svc->acceptOffer($svc->sendOffer($svc->submitOffer($o)));
        $this->expectException(FinanceException::class);
        $this->inv()->issueRent($draft->scheduleLines->first());
    }

    public function test_req_0188_payments_are_allocated_oldest_first_with_receipts(): void
    {
        [$q1, $q2] = $this->lease->scheduleLines->take(2)->all();
        $i1 = $this->inv()->issueRent($q1, CarbonImmutable::parse('2027-01-01'));
        $i2 = $this->inv()->issueRent($q2, CarbonImmutable::parse('2027-04-01'));
        $p = app(PaymentService::class)->record($this->tenant, Money::parse('20000'), 'mada', CarbonImmutable::parse('2027-04-05'), 'manager', $this->accountant);
        $this->assertStringStartsWith('RECEIPT-2027-', $p->receipt_number);   // REQ-0316
        $this->assertTrue($this->inv()->outstanding($i1->fresh())->isZero());
        $this->assertSame(1000000, $this->inv()->outstanding($i2->fresh())->minor());
        $p2 = app(PaymentService::class)->record($this->tenant, Money::parse('12000'), 'card', CarbonImmutable::parse('2027-04-20'), 'manager');
        $this->assertTrue($this->inv()->outstanding($i2->fresh())->isZero());
        $this->assertSame(200000, app(Ledger::class)->balance("tenant_credit:{$this->tenant->id}")->negate()->minor());   // 2,000 over-payment held as credit
    }

    public function test_req_0436_payments_never_go_to_the_platform(): void
    {
        $this->expectException(FinanceException::class);
        app(PaymentService::class)->record($this->tenant, Money::parse('100'), 'card', CarbonImmutable::parse('2027-01-02'), 'platform');
    }

    public function test_req_0429_collected_rent_enters_owner_trust_not_manager_revenue_and_req_0430_revenue_is_fees_only(): void
    {
        $i = $this->inv()->issueRent($this->lease->scheduleLines->first(), CarbonImmutable::parse('2027-01-01'));
        app(PaymentService::class)->record($this->tenant, $i->total_minor, 'mada', CarbonImmutable::parse('2027-01-03'), 'manager');
        $ledger = app(Ledger::class);
        $this->assertSame(1500000, $ledger->balance('trust_bank')->minor());
        $this->assertTrue(app(TrustAccounting::class)->managerRevenue('2027-01-01', '2027-12-31')->isZero());
        $this->assertSame(0, DB::table('journal_lines')->where('account', 'manager_revenue')->count());
    }

    public function test_req_0432_advance_rent_is_deferred_and_recognised_monthly(): void
    {
        $annual = $this->registeredLease('1', 'commercial', 'annual', '120000');
        $i = $this->inv()->issueRent($annual->scheduleLines->first(), CarbonImmutable::parse('2027-01-01'));
        $ledger = app(Ledger::class);
        $this->assertSame(-12000000, $ledger->balance("deferred_rent:{$this->owner->id}")->minor());
        $ta = app(TrustAccounting::class);
        $this->assertGreaterThanOrEqual(1, $ta->recognizeMonth(CarbonImmutable::parse('2027-01-15')));
        $this->assertSame(0, $ta->recognizeMonth(CarbonImmutable::parse('2027-01-31')));   // idempotent
        $this->assertSame(-1000000, $ledger->movements("rent_income:{$this->owner->id}", '2027-01-01', '2027-01-31')['credit']->negate()->minor());
        for ($m = 2; $m <= 12; $m++) {
            $ta->recognizeMonth(CarbonImmutable::create(2027, $m, 1));
        }
        $this->assertSame(0, $ledger->balance("deferred_rent:{$this->owner->id}")->minor());   // fully recognised over 12 months
    }

    public function test_req_0189_req_0433_deposit_is_a_liability_and_refund_needs_documents_and_approval(): void
    {
        $pay = app(PaymentService::class);
        $pay->recordDeposit($this->lease, Money::parse('5000'), CarbonImmutable::parse('2027-01-01'));
        $ledger = app(Ledger::class);
        $this->assertSame(-500000, $ledger->balance("deposit_liability:{$this->lease->id}")->minor());
        $this->assertSame(0, DB::table('journal_lines')->where('account', 'like', 'rent_income:%')->count());   // not revenue
        try {
            $pay->requestDepositRefund($this->lease, $this->accountant, [['description' => 'كسر', 'amount' => Money::parse('300'), 'document' => '']], CarbonImmutable::parse('2027-12-31'));
            $this->fail('deduction without document accepted');
        } catch (FinanceException) {
        }
        $req = $pay->requestDepositRefund($this->lease, $this->accountant, [['description' => 'كسر زجاج', 'amount' => Money::parse('300'), 'document' => 'docs/report.pdf']], CarbonImmutable::parse('2027-12-31'));
        $this->assertSame('pending', $req->status);
        $this->assertSame(-500000, $ledger->balance("deposit_liability:{$this->lease->id}")->minor());   // nothing paid before approval
        $this->assertSame('deposit.refund', $req->action);
    }

    public function test_req_0305_monthly_owner_statement_with_fee_percentage_maintenance_per_work_order_and_margin_line(): void
    {
        app(Settings::class)->setSubscriber('finance.vat_registered', true);
        $i = $this->inv()->issueRent($this->lease->scheduleLines->first(), CarbonImmutable::parse('2027-01-01'));
        app(PaymentService::class)->record($this->tenant, $i->total_minor, 'mada', CarbonImmutable::parse('2027-01-03'), 'manager');
        $ta = app(TrustAccounting::class);
        $req = $ta->requestExpense($this->accountant, $this->owner, $i->property_id, Money::parse('800'), 'إصلاح تسريب', 'WO-17', CarbonImmutable::parse('2027-01-10'), Money::parse('50'));
        $this->assertSame('pending', $req->status);   // paying out of trust waits for a checker (REQ-0069)
        $st = $ta->statement($this->owner, CarbonImmutable::parse('2027-01-01'));
        $this->assertSame(1500000, $st['collected_rent']->minor());
        $this->assertSame(750, $st['management_fee']['percentage_bp']);              // REQ-0306: agreed percentage shown
        $this->assertSame(112500, $st['management_fee']['amount']->minor());         // 7.5% of 15,000
        $this->assertSame(16875, $st['management_fee']['vat']->minor());             // 15% VAT on the fee (manager registered)
    }

    public function test_req_0306_vendor_margin_is_a_separate_line_and_net_adds_up(): void
    {
        $i = $this->inv()->issueRent($this->lease->scheduleLines->first(), CarbonImmutable::parse('2027-01-01'));
        app(PaymentService::class)->record($this->tenant, $i->total_minor, 'mada', CarbonImmutable::parse('2027-01-03'), 'manager');
        app(DualApproval::class)->setEnabled(\App\Models\Subscriber::find($this->owner->subscriber_id)->forceFill(['plan' => 'owner']), false);
        $ta = app(TrustAccounting::class);
        $ta->requestExpense($this->accountant, $this->owner, $i->property_id, Money::parse('800'), 'إصلاح تسريب', 'WO-17', CarbonImmutable::parse('2027-01-10'), Money::parse('50'));
        $st = $ta->statement($this->owner, CarbonImmutable::parse('2027-01-01'));
        $this->assertSame('WO-17', $st['maintenance'][0]['work_order']);
        $this->assertSame(80000, $st['maintenance_total']->minor());
        $this->assertSame(5000, $st['vendor_margin_total']->minor());
        $this->assertSame(1500000 - 112500 - 80000 - 5000, $st['net_to_owner']->minor());   // fee VAT 0: manager not registered
        $ta->postFee($this->owner, $st);
        $this->assertSame(112500 + 5000, $ta->managerRevenue('2027-01-01', '2027-01-31')->minor());   // fees + margin only
    }

    public function test_req_0069_invoice_cancellation_needs_a_checker_and_reverses_the_journal(): void
    {
        $i = $this->inv()->issueRent($this->lease->scheduleLines->first(), CarbonImmutable::parse('2027-01-01'));
        $req = $this->inv()->requestCancellation($i, $this->accountant, 'خطأ');
        $this->assertSame('issued', $i->fresh()->status);
        app(DualApproval::class)->approve($req, $this->manager, fn () => $this->inv()->executeCancellation($i));
        $this->assertSame('cancelled', $i->fresh()->status);
        $this->assertSame(0, app(Ledger::class)->balance("receivable:{$this->owner->id}")->minor());
    }

    public function test_req_0428_journal_is_balanced_and_append_only(): void
    {
        $this->inv()->issueRent($this->lease->scheduleLines->first(), CarbonImmutable::parse('2027-01-01'));
        $this->assertSame(0, (int) DB::selectOne('select coalesce(sum(debit_minor) - sum(credit_minor), 0) as d from journal_lines')->d);
        try {
            DB::transaction(fn () => DB::table('journal_lines')->limit(1)->update(['debit_minor' => 1]));
            $this->fail('journal edited');
        } catch (QueryException) {
        }
        $e = \App\Models\JournalEntry::first();
        $this->expectException(QueryException::class);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');   // the balance check normally runs at commit
        DB::transaction(function () use ($e) {   // unbalanced entry rejected
            DB::table('journal_lines')->insert(['subscriber_id' => $e->subscriber_id, 'journal_entry_id' => $e->id, 'account' => 'x', 'debit_minor' => 5, 'credit_minor' => 0, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function test_req_0434_nightly_reconciliation_builds_a_human_work_queue(): void
    {
        $lines = $this->lease->scheduleLines;
        $i = $this->inv()->issueRent($lines[0], CarbonImmutable::parse('2027-01-01'));
        app(PaymentService::class)->record($this->tenant, Money::parse('16000'), 'mada', CarbonImmutable::parse('2027-01-02'), 'manager');   // 1,000 unallocated
        $orphan = Tenant::create(['kind' => 'individual', 'name_ar' => 'آخر']);
        $found = app(Reconciliation::class)->run(CarbonImmutable::parse('2027-04-02'));
        $this->assertSame(1, $found['registered_lease_without_invoice']);   // Q2 due 2027-04-01, not invoiced
        $this->assertSame(1, $found['unallocated_payment']);
        $this->assertSame([], app(Reconciliation::class)->run(CarbonImmutable::parse('2027-04-02')));   // no duplicates
        $issue = ReconciliationIssue::where('kind', 'unallocated_payment')->first();
        app(Reconciliation::class)->resolve($issue, $this->accountant->id, 'رصيد دائن للمستأجر');
        $this->assertSame('resolved', $issue->fresh()->status);
    }

    public function test_req_0435_ejar_csv_export_is_matched_before_automatic_access(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'ej');
        file_put_contents($csv, "رقم العقد,الحالة\nEJ-101,منتهي\nEJ-999,موثق\n");
        $found = app(Reconciliation::class)->matchEjarCsv($csv, CarbonImmutable::parse('2027-02-01'));
        unlink($csv);
        $this->assertSame(1, $found['ejar_status_mismatch']);   // ours active, Ejar says expired
        $this->assertSame(1, $found['ejar_contract_unknown']);  // EJ-999 not in our system
    }

    public function test_req_0448_national_address_is_validated(): void
    {
        $this->assertTrue(NationalAddress::isValid($this->tenant->national_address));
        $this->assertContains('postal_code must be 5 digits', NationalAddress::problems(['building_no' => '1234', 'street' => 's', 'district' => 'd', 'city' => 'c', 'postal_code' => '123', 'additional_no' => '1234']));
        $this->assertContains('national address is missing', NationalAddress::problems(null));
    }

    public function test_req_0635_plan_limits_units_never_users_and_every_plan_has_maintenance_portal_and_assets(): void
    {
        foreach (['owner', 'office', 'company'] as $plan) {
            $s = new \App\Models\Subscriber(['plan' => $plan]);
            foreach (['maintenance', 'tenant_portal', 'asset_register', 'founding_inventory'] as $f) {   // REQ-0640
                $this->assertTrue(PlanEntitlements::has($s, $f), "$plan/$f");
            }
            $this->assertNull(PlanEntitlements::maxUsers($s));   // REQ-0641
        }
        $this->assertSame(20, PlanEntitlements::maxUnits(new \App\Models\Subscriber(['plan' => 'owner'])));
        $this->assertSame(300, PlanEntitlements::maxUnits(new \App\Models\Subscriber(['plan' => 'office'])));
        $this->assertNull(PlanEntitlements::maxUnits(new \App\Models\Subscriber(['plan' => 'company'])));
        $this->assertFalse(PlanEntitlements::has(new \App\Models\Subscriber(['plan' => 'office']), 'white_label'));

        $small = $this->makeSubscriber('owner');
        $this->tenant()->set($small->id);
        $w = app(BuildingWizard::class);
        $o = Owner::create(['kind' => 'individual', 'name_ar' => 'ص']);
        $p = $w->createProperty(Portfolio::create(['name_ar' => 'ص']), ['name_ar' => 'ص'], [['owner' => $o, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $w->generate($p, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 20]]]]]);
        $this->expectException(PlanLimitException::class);
        $w->generate($p, ['name_ar' => 'B', 'code_prefix' => 'B', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 1]]]]]);
    }
}
