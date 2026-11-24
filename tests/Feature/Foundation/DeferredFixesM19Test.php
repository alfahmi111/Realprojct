<?php

namespace Tests\Feature\Foundation;

use App\Dashboards\HomeOverview;
use App\Dashboards\Kpis;
use App\Finance\InvoiceService;
use App\Finance\PaymentService;
use App\Finance\SadadBilling;
use App\Finance\TrustAccounting;
use App\Identity\IdentityService;
use App\Leasing\LeasingService;
use App\Maintenance\MaintenanceService;
use App\Migration\ExcelTemplate;
use App\Models\LeasableUnit;
use App\Models\Lease;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Space;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NotificationCenter;
use App\Properties\BuildingWizard;
use App\Support\Dates\Today;
use App\Support\Messages;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The items deferred by the M18 review, completed in M19 on the owner's order «اكمل ما تم تأجيله» (2026-11-24):
 * deposit refunds from where the deposit sits (REQ-0189), SADAD bills that follow the balance (REQ-0467), the fee of an
 * owner with several agreements (REQ-0305), potential rent of unpriced units (REQ-0284), whole-building leases on the
 * facade (REQ-0330), one escalation per event and maintenance/approval alerts (REQ-0359), spreadsheet row numbers
 * (REQ-0122), name matching (REQ-0447), Arabic messages (REQ-0490) and Riyadh's "today".
 */
class DeferredFixesM19Test extends TestCase
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
        $w = app(BuildingWizard::class);
        $this->owner = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك']);
        $this->p = $w->createProperty(Portfolio::create(['name_ar' => 'م']), ['name_ar' => 'عمارة'], [['owner' => $this->owner, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $w->createAgreement($this->owner, [$this->p], ['fee_type' => 'percentage', 'fee_percentage_bp' => 500, 'collector' => 'owner', 'starts_on' => '2020-01-01']);
        $w->generate($this->p, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 2]]]]]);
        $this->tenant = Tenant::create(['kind' => 'individual', 'name_ar' => 'مستأجر']);
    }

    private function lease(string $code, int $deposit = 0, ?int $unitId = null): Lease
    {
        $l = Lease::create(['leasable_unit_id' => $unitId ?? LeasableUnit::where('code', $code)->first()->id, 'tenant_id' => $this->tenant->id, 'kind' => 'lease', 'usage' => 'residential', 'track' => 'long',
            'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'annual_rent_minor' => Money::parse('120000'), 'payment_frequency' => 'monthly', 'deposit_minor' => Money::of($deposit)]);
        $l->forceFill(['status' => 'active'])->save();
        app(\App\Leasing\ScheduleGenerator::class)->generate($l);
        return $l->fresh();
    }

    public function test_req_0189_a_deposit_kept_in_the_owners_account_is_refunded_from_there(): void
    {
        $l = $this->lease('101', 500000);
        $pay = app(PaymentService::class);
        $pay->recordDeposit($l, Money::of(500000), CarbonImmutable::parse('2026-01-01'), 'owner');
        $this->assertSame('owner_direct:deposit', $pay->depositAccount($l));
        $lines = $pay->depositRefundLines($l, Money::of(500000), Money::of(100000), $this->owner->id);
        $this->assertSame(['deposit_liability:' . $l->id, 'owner_direct:deposit', 'rent_income:' . $this->owner->id], array_column($lines, 0));   // never trust_bank
        $this->assertSame(400000, $lines[1][2]->minor());
    }

    public function test_req_0467_sadad_bill_is_reissued_for_the_balance_after_a_partial_payment(): void
    {
        config(['integrations.sadad' => 'simulator']);
        $l = $this->lease('101');
        $inv = app(InvoiceService::class)->issueRent($l->scheduleLines()->first(), CarbonImmutable::parse('2026-01-01'));
        $sadad = app(SadadBilling::class);
        if (! $sadad->available()) {
            $this->markTestSkipped('SADAD simulator not enabled by this configuration key.');
        }
        $sadad->issue($inv);
        $this->assertSame(1000000, (int) $inv->fresh()->sadad_amount_minor);
        app(PaymentService::class)->record($this->tenant, Money::of(400000), 'cash', CarbonImmutable::parse('2026-01-05'), 'owner');
        $inv->refresh();
        $this->assertNotNull($inv->sadad_bill_number);
        $this->assertSame(600000, (int) $inv->sadad_amount_minor);   // the bill follows the balance (was stuck at 10,000)
    }

    public function test_req_0305_each_property_carries_the_fee_of_its_own_agreement(): void
    {
        $w = app(BuildingWizard::class);
        $p2 = $w->createProperty(Portfolio::create(['name_ar' => 'م2']), ['name_ar' => 'عمارة 2'], [['owner' => $this->owner, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $w->generate($p2, ['name_ar' => 'B', 'floors' => [['number' => 2, 'units' => [['type' => 'apartment', 'count' => 1]]]]]);
        $w->createAgreement($this->owner, [$p2], ['fee_type' => 'percentage', 'fee_percentage_bp' => 1000, 'collector' => 'owner', 'starts_on' => '2021-01-01']);
        $a = $this->lease('101');
        $b = $this->lease('', unitId: LeasableUnit::where('property_id', $p2->id)->first()->id);
        foreach ([$a, $b] as $l) {
            $inv = app(InvoiceService::class)->issueRent($l->scheduleLines()->first(), CarbonImmutable::parse('2026-01-01'));
            app(PaymentService::class)->record($this->tenant, $inv->total_minor, 'bank_transfer', CarbonImmutable::parse('2026-01-03'), 'owner', reference: 'FT-D110-' . $inv->id);
        }
        $s = app(TrustAccounting::class)->statement($this->owner, CarbonImmutable::parse('2026-01-01'));
        // 10,000 at 5% + 10,000 at 10% (was 20,000 at the latest agreement's 10%)
        $this->assertSame(50000 + 100000, $s['management_fee']['amount']->minor());
        $this->assertStringContainsString('+', TrustAccounting::feeText($s['management_fee']));
    }

    public function test_req_0284_a_vacant_unit_without_a_price_counts_at_its_last_rent(): void
    {
        $old = $this->lease('102');
        $old->forceFill(['status' => 'expired'])->save();
        $unit = LeasableUnit::where('code', '102')->first();
        $this->assertSame(12000000, app(Kpis::class)->potentialRent($unit, null)->minor());   // was null → zero potential
    }

    public function test_req_0330_a_whole_building_lease_shows_on_each_flat_of_the_facade(): void
    {
        $spaces = Space::where('property_id', $this->p->id)->where('kind', 'unit')->pluck('id')->all();
        $whole = app(BuildingWizard::class)->createComposite($this->p, $spaces, 'whole_building', 'ALL', 'العمارة كاملة');
        $l = $this->lease('', unitId: $whole->id);
        app(LeasingService::class)->occupy($whole->load('spaces'), CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'), 'lease', leaseId: $l->id);
        $home = app(HomeOverview::class);
        $b = \App\Models\Building::where('property_id', $this->p->id)->first();
        $facade = $home->facade($b->id, CarbonImmutable::parse('2026-06-01'));
        $statuses = collect($facade['rows'])->flatMap(fn ($r) => array_column($r['units'], 'status'))->all();
        $this->assertSame(['paid', 'paid'], $statuses);   // was vacant, vacant
        $this->assertSame(2, $home->buildingOutlook($b->id, CarbonImmutable::parse('2026-06-01'))['leased']);
    }

    public function test_req_0359_a_report_alerts_the_supervisors_and_escalates_once_unless_handled(): void
    {
        $this->makeUser(\App\Models\Subscriber::find($this->manager->subscriber_id), 'maintenance_supervisor');
        $this->makeUser(\App\Models\Subscriber::find($this->manager->subscriber_id), 'maintenance_supervisor');
        $this->tenant()->set($this->manager->subscriber_id);
        $svc = app(MaintenanceService::class);
        $wo = $svc->report('emergency', $this->p->id, 'تسريب غاز', 'tenant');
        $this->assertSame(2, DB::table('notifications_outbox')->where('event', 'maintenance.emergency')->where('channel', 'in_app')->count());
        $center = app(NotificationCenter::class);
        $this->assertSame(1, $center->escalate(CarbonImmutable::now()->addHours(2)));   // two supervisors, one escalation
        $this->assertSame(0, $center->escalate(CarbonImmutable::now()->addHours(3)));
        // a triaged report does not escalate
        $wo2 = $svc->report('corrective', $this->p->id, 'تسريب', 'tenant');
        $svc->triage($wo2, 'normal');
        $this->assertSame(0, $center->escalate(CarbonImmutable::now()->addDays(2)));
    }

    public function test_req_0122_errors_name_the_spreadsheet_row_the_user_sees(): void
    {
        $csv = sys_get_temp_dir() . '/m19-rows.csv';
        $head = array_column(array_values(ExcelTemplate::COLUMNS), 0);
        $row = implode(',', ['1', 'خالد', 'فرد', '', '', '101', 'سكني', '2026-01-01', '2026-12-31', '60000', 'سنوي', '']);
        file_put_contents($csv, implode(',', $head) . "\n\n\n" . $row . "\n");   // two blank rows before the contract
        $this->assertSame(4, app(ExcelTemplate::class)->parse($csv)[0]['row']);   // was 2
    }

    public function test_req_0447_names_match_across_hidden_marks_and_compound_spacing(): void
    {
        $id = app(IdentityService::class);
        $this->assertTrue($id->sameName('عبد الله محمد', "\u{200F}عبدالله مُحمد"));
        $this->assertFalse($id->sameName('أحمد علي', 'محمد علي'));
    }

    public function test_req_0490_domain_messages_reach_people_in_arabic(): void
    {
        $this->assertSame('الخصومات أكبر من التأمين المحفوظ.', Messages::ar('Deductions exceed the deposit held.'));
        $this->assertStringStartsWith('باقتك تشمل حتى 20 وحدة', Messages::ar('The office plan includes up to 20 units.'));
        $this->assertSame('رسالة غير معروفة', Messages::ar('رسالة غير معروفة'));
    }

    public function test_req_0372_the_office_links_its_own_provider_accounts_never_in_the_platforms_name(): void
    {
        $acc = app(\App\Integrations\IntegrationAccounts::class);
        $this->assertContains('مالك', $acc->holders());
        $row = $acc->save('payments', 'مالك', ['merchant_id' => 'M-1', 'secret' => 'S-1'], true);
        $this->assertSame(['merchant_id' => 'M-1', 'secret' => 'S-1'], $row->fresh()->credentials);
        $this->assertStringNotContainsString('S-1', (string) DB::table('subscriber_integrations')->where('id', $row->id)->value('credentials'));   // encrypted at rest
        $acc->save('payments', 'مالك', [], false);   // empty keeps the saved secrets
        $this->assertSame('M-1', $row->fresh()->credentials['merchant_id']);
        $this->assertSame(['merchant_id', 'secret'], collect($acc->list())->firstWhere('kind', 'payments')['keys']);
        $this->expectException(\RuntimeException::class);
        $acc->save('payments', 'وِثاق', ['merchant_id' => 'X'], true);
    }

    public function test_today_turns_over_at_riyadh_midnight(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 22:30:00', 'UTC'));   // 01:30 on the 25th in Riyadh
        try {
            $this->assertSame('2026-11-25', Today::string());
            $this->assertSame('UTC', Today::date()->getTimezone()->getName());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
