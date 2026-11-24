<?php

namespace Tests\Feature\Screens;

use App\Filament\Manager\Pages\AddBuildingWizard;
use App\Filament\Manager\Pages\SubscriberSettingsPage;
use App\Filament\Manager\Resources\ApprovalRequests\Pages\ListApprovalRequests;
use App\Filament\Manager\Resources\BillingScheduleLines\Pages\ListBillingScheduleLines;
use App\Filament\Manager\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Manager\Resources\LeaseOffers\Pages\CreateLeaseOffer;
use App\Filament\Manager\Resources\LeaseOffers\Pages\ListLeaseOffers;
use App\Filament\Manager\Resources\Leases\Pages\ListLeases;
use App\Filament\Manager\Resources\Payments\Pages\CreatePayment;
use App\Filament\Manager\Resources\WorkOrders\Pages\CreateWorkOrder;
use App\Filament\Manager\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Models\ApprovalRequest;
use App\Models\BillingScheduleLine;
use App\Models\Invoice;
use App\Models\LeasableUnit;
use App\Models\Lease;
use App\Models\LeaseOffer;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Settings\Settings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * End-to-end manager flows through the screens (REQ-0121, REQ-0184, REQ-0182, REQ-0300, REQ-0316, REQ-0069, REQ-0227,
 * REQ-0324): the screens call the tested services, so domain rules (RLS, no overlap, maker/checker) hold in the UI.
 */
class ManagerFlowsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->makeSubscriber();
        $this->manager = $this->makeUser($s, 'manager');
        $this->accountant = $this->makeUser($s, 'accountant');
        $this->tenant()->set($s->id);
        $this->manager->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        Filament::setCurrentPanel(Filament::getPanel('manager'));
        $this->actingAs($this->manager);
    }

    private function building(): Property
    {
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك']);
        $pf = Portfolio::create(['name_ar' => 'محفظة']);
        Livewire::test(AddBuildingWizard::class)
            ->set('data.portfolio_id', $pf->id)->set('data.name_ar', 'عمارة الشاشات')->set('data.template', 'residential_building')
            ->set('data.owners', [['owner_id' => $owner->id, 'numerator' => 1, 'denominator' => 1]])
            ->set('data.building_name', 'A')->set('data.numbering', 'floor_seq')
            ->set('data.floors', [['number' => 1, 'name_ar' => 'الأول', 'units' => [['type' => 'apartment', 'count' => 2]]]])
            ->set('data.prices', [['type' => 'apartment', 'annual' => '48000']])
            ->call('create')->assertHasNoErrors();
        return Property::where('name_ar', 'عمارة الشاشات')->firstOrFail();
    }

    public function test_req_0121_wizard_screen_creates_property_tree_units_and_prices(): void
    {
        $p = $this->building();
        $this->assertSame(2, LeasableUnit::where('property_id', $p->id)->count());
        $this->assertSame(4800000, RatePlan::whereNull('archived_at')->first()->price_minor->minor());
    }

    public function test_req_0184_offer_to_registered_lease_to_invoice_to_payment_through_screens(): void
    {
        $this->building();
        $unit = LeasableUnit::where('code', '101')->first();
        $t = Tenant::create(['kind' => 'individual', 'name_ar' => 'مستأجر الشاشة']);

        Livewire::test(CreateLeaseOffer::class)->fillForm([
            'leasable_unit_id' => $unit->id, 'tenant_id' => $t->id, 'usage' => 'residential', 'starts_on' => now()->toDateString(),
            'duration_unit' => 'year', 'duration_count' => 1, 'annual_rent_minor' => '48000', 'payment_frequency' => 'quarterly', 'deposit_minor' => '2000',
        ])->call('create')->assertHasNoFormErrors();
        $offer = LeaseOffer::firstOrFail();
        $this->assertSame(4800000, $offer->annual_rent_minor->minor());

        Livewire::test(ListLeaseOffers::class)->callTableAction('submit', $offer)->callTableAction('send', $offer->fresh());
        $this->assertSame('sent', $offer->fresh()->status);
        $token = \Illuminate\Support\Facades\DB::table('lease_offer_tokens')->value('token_hash');
        $this->assertNotNull($token);

        // The tenant accepts through the service the public page calls (token is only shown once to the manager).
        $lease = app(\App\Leasing\LeasingService::class)->acceptOffer(...[$this->reissueToken($offer->fresh())]);
        Livewire::test(ListLeases::class)
            ->callTableAction('sendForRegistration', $lease)
            ->callTableAction('awaiting', $lease->fresh())
            ->callTableAction('register', $lease->fresh(), data: ['number' => 'EJ-SCR-1'])
            ->callTableAction('activate', $lease->fresh());
        $this->assertSame('active', $lease->fresh()->status);

        $line = BillingScheduleLine::where('lease_id', $lease->id)->orderBy('sequence')->first();
        Livewire::test(ListBillingScheduleLines::class)->callTableAction('issue', $line);
        $invoice = Invoice::firstOrFail();
        $this->assertSame('EJ-SCR-1', $invoice->ejar_contract_number);

        Livewire::test(CreatePayment::class)->fillForm([
            'tenant_id' => $t->id, 'amount_minor' => '12000', 'method' => 'mada', 'received_on' => now()->toDateString(), 'paid_to' => 'manager', 'ref_source' => 'ejar', 'reference' => 'EJR-SCR-PAY-1',
        ])->call('create')->assertHasNoFormErrors();
        $this->assertSame(1, Payment::count());
        $this->assertTrue(app(\App\Finance\InvoiceService::class)->outstanding($invoice->fresh())->isZero());
    }

    public function test_req_0069_cancellation_requested_in_the_screen_is_approved_by_another_user(): void
    {
        $this->test_req_0184_offer_to_registered_lease_to_invoice_to_payment_through_screens_without_payment();
        $invoice = Invoice::firstOrFail();
        $this->actingAs($this->accountant);
        Livewire::test(ListInvoices::class)->callTableAction('cancel', $invoice, data: ['reason' => 'خطأ إدخال']);
        $req = ApprovalRequest::firstOrFail();
        $this->assertSame('pending', $req->status);
        // The maker cannot approve their own entry from the screen (action hidden).
        Livewire::test(ListApprovalRequests::class)->assertTableActionHidden('approve', $req);
        $this->actingAs($this->manager);
        Livewire::test(ListApprovalRequests::class)->callTableAction('approve', $req);
        $this->assertSame('approved', $req->fresh()->status);
        $this->assertSame('cancelled', $invoice->fresh()->status);
    }

    public function test_req_0227_work_order_from_the_screen_through_triage_estimate_assignment(): void
    {
        $p = $this->building();
        $tech = $this->makeUser(\App\Models\Subscriber::find($this->manager->subscriber_id), 'technician');
        $this->tenant()->set($this->manager->subscriber_id);
        Livewire::test(CreateWorkOrder::class)->fillForm(['type' => 'corrective', 'property_id' => $p->id, 'description' => 'تسريب في المطبخ'])->call('create')->assertHasNoFormErrors();
        $wo = WorkOrder::firstOrFail();
        Livewire::test(ListWorkOrders::class)
            ->callTableAction('triage', $wo, data: ['priority' => 'urgent'])
            ->callTableAction('estimate', $wo->fresh(), data: ['cost' => '300'])
            ->callTableAction('assign', $wo->fresh(), data: ['technician' => $tech->id]);
        $this->assertSame('assigned', $wo->fresh()->status);
        $this->assertSame($tech->id, $wo->fresh()->assignee_user_id);
    }

    public function test_req_0324_settings_screen_saves_and_audits_subscriber_settings(): void
    {
        Livewire::test(SubscriberSettingsPage::class)->set('data.finance__tax_period', 'monthly')->set('data.leasing__non_renewal_notice_days', '75')->call('save');
        $this->assertSame('monthly', app(Settings::class)->get('finance.tax_period'));
        $this->assertSame(75, app(Settings::class)->get('leasing.non_renewal_notice_days'));
        $this->assertTrue(\App\Models\AuditLog::where('action', 'setting.changed')->where('auditable_id', 'finance.tax_period')->exists());
    }

    /** Same as the end-to-end flow up to the invoice, without paying it. */
    private function test_req_0184_offer_to_registered_lease_to_invoice_to_payment_through_screens_without_payment(): void
    {
        $this->building();
        $unit = LeasableUnit::where('code', '101')->first();
        $t = Tenant::create(['kind' => 'individual', 'name_ar' => 'م']);
        $svc = app(\App\Leasing\LeasingService::class);
        $o = $svc->createOffer($this->manager, $unit, $t, ['usage' => 'residential', 'starts_on' => now()->toDateString(), 'duration_unit' => 'year', 'duration_count' => 1, 'annual_rent_minor' => \App\Support\Money\Money::parse('48000'), 'payment_frequency' => 'quarterly']);
        $lease = $svc->acceptOffer($svc->sendOffer($svc->submitOffer($o)));
        $svc->sendForRegistration($lease);
        $svc->markAwaitingParties($lease);
        $svc->register($lease, 'EJ-X');
        app(\App\Finance\InvoiceService::class)->issueRent($lease->scheduleLines->first());
    }

    /** The raw token is shown once in the UI notification; tests re-issue a known token for the same sent offer. */
    private function reissueToken(LeaseOffer $offer): string
    {
        $token = 'test-token-' . $offer->id;
        $offer->forceFill(['accept_token' => hash('sha256', $token)])->save();
        return $token;
    }
}
