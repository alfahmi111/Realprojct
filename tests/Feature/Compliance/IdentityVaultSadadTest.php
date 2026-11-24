<?php

namespace Tests\Feature\Compliance;

use App\Documents\DocumentVault;
use App\Finance\InvoiceService;
use App\Finance\SadadBilling;
use App\Identity\IdentityService;
use App\Integrations\IntegrationNotConfigured;
use App\Leasing\LeasingService;
use App\Models\Building;
use App\Models\Document;
use App\Models\IdentityVerification;
use App\Models\LeasableUnit;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Notifications\NotificationCenter;
use App\Properties\BuildingWizard;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * M15 (owner order Q30, 2026-11-23): the identity layer built once (REQ-0472) — Yaqeen (REQ-0446), Wathq (REQ-0447),
 * Nafath (REQ-0445, REQ-0471); the document vault (REQ-0054, REQ-0265…REQ-0270): ownership REQ-0266, licences
 * REQ-0267, statutory certificates REQ-0268, party documents REQ-0269, alerts and history REQ-0270; SADAD (REQ-0467).
 */
class IdentityVaultSadadTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $accountant;
    private Property $p;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->makeSubscriber();
        $this->manager = $this->makeUser($s, 'manager');
        $this->accountant = $this->makeUser($s, 'accountant');
        $this->tenant()->set($s->id);
        $this->manager->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $w = app(BuildingWizard::class);
        $o = Owner::create(['kind' => 'individual', 'name_ar' => 'سعد المالك', 'national_id' => '1000000001']);
        $this->p = $w->createProperty(Portfolio::create(['name_ar' => 'م']), ['name_ar' => 'عمارة الوفاء'], [['owner' => $o, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $w->generate($this->p, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 1]]]]]);
        config(['integrations.nafath' => 'simulator', 'integrations.yaqeen' => 'simulator', 'integrations.wathq' => 'simulator', 'integrations.sadad' => 'simulator']);
        Filament::setCurrentPanel(Filament::getPanel('manager'));
    }

    public function test_req_0446_yaqeen_verifies_matching_name_flags_mismatch_and_not_found_and_keeps_only_a_masked_id(): void
    {
        $svc = app(IdentityService::class);
        $ok = Tenant::create(['kind' => 'individual', 'name_ar' => 'خالد أحمد', 'national_id' => '1016860767']);
        $v = $svc->yaqeen($ok, $this->manager);
        $this->assertSame('verified', $v->status);
        $this->assertSame('******0767', $v->reference_masked);
        $this->assertStringNotContainsString('1016860767', json_encode(IdentityVerification::all()->toArray()));
        $bad = Tenant::create(['kind' => 'individual', 'name_ar' => 'فهد', 'national_id' => '1016860769']);
        $this->assertSame('mismatch', $svc->yaqeen($bad, $this->manager)->status);
        $none = Tenant::create(['kind' => 'individual', 'name_ar' => 'نورة', 'national_id' => '1016860000']);
        $this->assertSame('not_found', $svc->yaqeen($none, $this->manager)->status);
        $this->assertTrue($svc->sameName('خالد أحمد', 'خالد احمد'));            // hamza and spacing normalised
        $this->assertStringContainsString('موثّق عبر يقين', \App\Filament\Support\IdentityActions::badge($ok));
    }

    public function test_req_0447_wathq_checks_company_tenant_vendor_and_the_office(): void
    {
        $svc = app(IdentityService::class);
        $co = Tenant::create(['kind' => 'company', 'name_ar' => 'شركة النخبة', 'commercial_registration' => '1010123456']);
        $v = $svc->wathq($co, $this->manager);
        $this->assertSame('verified', $v->status);
        $this->assertSame('active', $v->details['cr_status']);
        $vendor = Vendor::create(['name_ar' => 'مؤسسة الصيانة', 'commercial_registration' => '1010123459']);
        $this->assertSame('expired', $svc->wathq($vendor, $this->manager)->status);   // suspended CR
        $office = $this->manager->subscriber;
        $office->forceFill(['commercial_registration' => '4030111222'])->save();
        $this->assertSame('verified', $svc->wathq($office->fresh(), $this->manager)->status);
    }

    public function test_req_0445_nafath_two_steps_approve_and_reject(): void
    {
        $svc = app(IdentityService::class);
        $t = Tenant::create(['kind' => 'individual', 'name_ar' => 'ريم', 'national_id' => '1022334455']);
        $s = $svc->nafathStart($t, $this->manager);
        $this->assertMatchesRegularExpression('/^\d{2}$/', $s['random']);
        $v = IdentityVerification::find($s['verification_id']);
        $this->assertSame('pending', $v->status);
        $this->assertSame('verified', $svc->nafathCheck($v, $t)->status);
        $r = Tenant::create(['kind' => 'individual', 'name_ar' => 'سلمان', 'national_id' => '1022331111']);
        $s2 = $svc->nafathStart($r, $this->manager);
        $this->assertSame('rejected', $svc->nafathCheck(IdentityVerification::find($s2['verification_id']), $r)->status);
    }

    public function test_req_0472_without_a_provider_the_identity_layer_refuses_and_hides_its_actions(): void
    {
        config(['integrations.yaqeen' => 'none']);
        $svc = app(IdentityService::class);
        $this->assertFalse($svc->available('yaqeen'));
        $this->expectException(IntegrationNotConfigured::class);
        $svc->yaqeen(Tenant::create(['kind' => 'individual', 'name_ar' => 'س', 'national_id' => '1016860767']), $this->manager);
    }

    public function test_req_0265_vault_versions_views_and_party_documents_restricted(): void
    {
        Storage::fake('local');
        $vault = app(DocumentVault::class);
        $path = UploadedFile::fake()->create('deed.pdf', 20, 'application/pdf')->store('documents/x', 'local');
        $deed = $vault->add($this->p, 'deed', '', $path, null, $this->manager);
        $this->assertSame('ownership', $deed->category);
        $this->assertSame('الصك', $deed->title_ar);
        $this->assertSame($this->p->id, $deed->property_id);
        $lic = $vault->add(Building::where('property_id', $this->p->id)->first(), 'building_permit', 'رخصة البناء', $path, '2027-01-01', $this->manager);
        $new = $vault->replace($lic, $path, '2032-01-01', $this->manager);
        $this->assertSame(2, $new->version);
        $this->assertNotNull($lic->fresh()->superseded_at);
        $this->assertCount(1, $vault->history($new));
        $vault->open($new, $this->manager);
        $this->assertSame(1, $new->views()->count());
        // REQ-0269: party documents only with documents.parties
        $tenant = Tenant::create(['kind' => 'individual', 'name_ar' => 'م', 'national_id' => '1016860767']);
        $id = $vault->add($tenant, 'national_id_copy', '', $path, null, $this->manager);
        $this->assertFalse($vault->canSee($this->accountant, $id));
        $this->expectException(\RuntimeException::class);
        $vault->open($id, $this->accountant);
    }

    public function test_req_0270_alerts_30_and_7_days_and_one_statutory_work_order_per_certificate(): void
    {
        Storage::fake('local');
        $vault = app(DocumentVault::class);
        $today = CarbonImmutable::parse('2026-11-23');
        $cd = $vault->add($this->p, 'civil_defense', '', 'documents/x/cd.pdf', $today->addDays(30)->toDateString(), $this->manager);
        $vault->add($this->p, 'building_permit', '', 'documents/x/bp.pdf', $today->addDays(7)->toDateString(), $this->manager);
        $vault->add($this->p, 'deed', '', 'documents/x/d.pdf', $today->addDays(12)->toDateString(), $this->manager);   // inside 30 days: its 30-day alert is due (review M18: ranges)
        $vault->add($this->p, 'deed', '', 'documents/x/d2.pdf', $today->addDays(60)->toDateString(), $this->manager);   // too early: none
        $r = $vault->alerts($today, app(NotificationCenter::class));
        $this->assertSame(['alerted' => 3, 'work_orders' => 1], $r);
        $this->assertSame(['alerted' => 0, 'work_orders' => 0], $vault->alerts($today, app(NotificationCenter::class)));   // run twice in a day: nothing repeated
        // day 7 of the deed (today+5) was missed; on today+6 its 7-day alert is caught up, nothing else repeats
        $this->assertSame(1, $vault->alerts($today->addDays(6), app(NotificationCenter::class))['alerted']);
        $wo = WorkOrder::find($cd->fresh()->statutory_work_order_id);
        $this->assertSame('statutory', $wo->type);
        $this->assertStringContainsString('شهادة الدفاع المدني', $wo->description);
        $again = $vault->alerts($today->addDays(23), app(NotificationCenter::class));   // 7 days before the certificate
        $this->assertSame(0, $again['work_orders']);                                   // still one work order
    }

    public function test_req_0467_sadad_bill_for_an_outstanding_invoice_once_and_shown_to_the_tenant(): void
    {
        $ls = app(LeasingService::class);
        $user = $this->makeUser($this->manager->subscriber, 'tenant');
        $t = Tenant::create(['kind' => 'individual', 'name_ar' => 'مستأجر', 'user_id' => $user->id]);
        $o = $ls->createOffer($this->manager, LeasableUnit::first(), $t, ['usage' => 'residential', 'starts_on' => now()->subMonth()->toDateString(), 'duration_unit' => 'year', 'duration_count' => 1, 'annual_rent_minor' => Money::parse('36000'), 'payment_frequency' => 'annual']);
        $lease = $ls->acceptOffer($ls->sendOffer($ls->submitOffer($o)));
        $ls->sendForRegistration($lease);
        $ls->markAwaitingParties($lease);
        $ls->register($lease, 'EJ-S1');
        $ls->activate($lease);
        $inv = app(InvoiceService::class)->issueRent($lease->fresh()->scheduleLines->first(), CarbonImmutable::today());
        $billed = app(SadadBilling::class)->issue($inv);
        $this->assertMatchesRegularExpression('/^\d{12}$/', $billed->sadad_bill_number);
        $this->assertSame($billed->sadad_bill_number, app(SadadBilling::class)->issue($billed->fresh())->sadad_bill_number);   // once
        $this->assertSame($billed->sadad_bill_number, app(\App\Portals\TenantPortal::class)->myInvoices($user)[0]['sadad']);
        config(['integrations.sadad' => 'none']);
        $this->assertFalse(app(SadadBilling::class)->available());
    }

    public function test_req_0054_vault_and_verify_screens_render(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get('/manage/documents')->assertOk()->getContent();
        foreach (['خزنة المستندات', 'الخزنة فارغة', 'رفع مستند'] as $n) {
            $this->assertStringContainsString($n, $html, $n);
        }
        Tenant::create(['kind' => 'individual', 'name_ar' => 'خالد', 'national_id' => '1016860767']);
        \Livewire\Livewire::test(\App\Filament\Manager\Resources\Tenants\Pages\ListTenants::class)->set('mode', 'table')->assertSee('تحقّق');
        $this->assertStringContainsString('تحقّق من سجل المكتب عبر واثق', $this->get('/manage/subscriber-settings')->assertOk()->getContent());
    }
}
