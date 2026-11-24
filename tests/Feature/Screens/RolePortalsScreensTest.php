<?php

namespace Tests\Feature\Screens;

use App\Filament\Owner\Pages\OwnerHome;
use App\Filament\Platform\Resources\Subscribers\Pages\CreateSubscriber;
use App\Filament\Technician\Pages\TechnicianHome;
use App\Filament\Tenant\Pages\TenantHome;
use App\Leasing\LeasingService;
use App\Maintenance\MaintenanceService;
use App\Models\Asset;
use App\Models\LeasableUnit;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\Space;
use App\Models\Subscriber;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Properties\BuildingWizard;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner, tenant, technician and platform interfaces (REQ-0271, REQ-0272…REQ-0275, REQ-0307, REQ-0313, REQ-0065):
 * each role sees its own screen and acts through the tested services.
 */
class RolePortalsScreensTest extends TestCase
{
    use RefreshDatabase;

    private Subscriber $s;
    private User $manager;
    private User $ownerUser;
    private User $tenantUser;
    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSubscriber();
        $this->manager = $this->makeUser($this->s, 'manager');
        $this->ownerUser = $this->makeUser($this->s, 'owner');
        $this->tenantUser = $this->makeUser($this->s, 'tenant');
        $this->tech = $this->makeUser($this->s, 'technician');
        $this->tenant()->set($this->s->id);
        $this->ownerUser->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $w = app(BuildingWizard::class);
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك', 'user_id' => $this->ownerUser->id]);
        $p = $w->createProperty(Portfolio::create(['name_ar' => 'م']), ['name_ar' => 'ع'], [['owner' => $owner, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $w->createAgreement($owner, [$p], ['fee_type' => 'percentage', 'fee_percentage_bp' => 500, 'spend_cap_minor' => Money::parse('1000'), 'collector' => 'manager', 'starts_on' => '2026-01-01']);
        $w->generate($p, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 1]]]]]);
        Asset::create(['space_id' => Space::where('kind', 'unit')->first()->id, 'qr_code' => 'QR-T', 'category' => 'hvac', 'type' => 'split_ac', 'name_ar' => 'مكيف']);
        $lessee = Tenant::create(['kind' => 'individual', 'name_ar' => 'مستأجر', 'user_id' => $this->tenantUser->id]);
        $ls = app(LeasingService::class);
        $o = $ls->createOffer($this->manager, LeasableUnit::first(), $lessee, ['usage' => 'residential', 'starts_on' => now()->subMonth()->toDateString(), 'duration_unit' => 'year', 'duration_count' => 1, 'annual_rent_minor' => Money::parse('40000'), 'payment_frequency' => 'annual']);
        $lease = $ls->acceptOffer($ls->sendOffer($ls->submitOffer($o)));
        $ls->sendForRegistration($lease);
        $ls->markAwaitingParties($lease);
        $ls->register($lease, 'EJ-T');
        $ls->activate($lease);
    }

    public function test_req_0275_tenant_screen_shows_unit_and_sends_a_report(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        $this->actingAs($this->tenantUser)->get('/tenant/my-unit')->assertOk()->assertSee('EJ-T')->assertSee('مكيف');
        Livewire::actingAs($this->tenantUser)->test(TenantHome::class)->set('description', 'المكيف لا يبرد')->set('assetId', Asset::first()->id)->call('report')->assertHasNoErrors();
        $this->assertSame('tenant', WorkOrder::first()->reported_by_type);
    }

    public function test_req_0313_technician_screen_works_assigned_orders(): void
    {
        $m = app(MaintenanceService::class);
        $wo = $m->report('corrective', LeasableUnit::first()->property_id, 'إصلاح', 'supervisor', $this->manager, Asset::first());
        $m->triage($wo, 'normal');
        $m->estimate($wo, Money::parse('100'));
        $m->assign($wo, $this->tech);
        Filament::setCurrentPanel(Filament::getPanel('technician'));
        $this->actingAs($this->tech)->get('/tech/tasks')->assertOk()->assertSee($wo->number);
        Livewire::actingAs($this->tech)->test(TechnicianHome::class)->call('start', $wo->id)
            ->set('cost.' . $wo->id, ['kind' => 'part', 'description' => 'محبس', 'quantity' => 1, 'unit_cost' => '40'])->call('addCost', $wo->id)
            ->set('scan', 'QR-T')->call('doScan');
        $this->assertSame('in_progress', $wo->fresh()->status);
        $this->assertSame(4000, $m->totalCost($wo->fresh())->minor());
    }

    public function test_req_0307_owner_approves_above_cap_from_the_screen(): void
    {
        $m = app(MaintenanceService::class);
        $wo = $m->report('corrective', LeasableUnit::first()->property_id, 'تغيير مضخة', 'supervisor', $this->manager);
        $m->triage($wo, 'normal');
        $m->estimate($wo, Money::parse('2500'));
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $this->actingAs($this->ownerUser)->get('/owner/statement')->assertOk()->assertSee($wo->number);
        Livewire::actingAs($this->ownerUser)->test(OwnerHome::class)->call('decide', $wo->id, true);
        $this->assertSame('approved', $wo->fresh()->owner_approval);
    }

    public function test_req_0065_platform_admin_creates_a_subscriber_with_its_first_manager(): void
    {
        $this->tenant()->clear();
        $this->tenant()->setPlatform(true);
        $admin = User::factory()->create(['subscriber_id' => null, 'role' => 'platform_admin']);
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs($admin)->get('/platform/subscribers')->assertOk();
        $this->actingAs($admin)->get('/platform/platform-settings')->assertOk();
        Livewire::actingAs($admin)->test(CreateSubscriber::class)->fillForm([
            'name_ar' => 'مكتب جديد', 'name_en' => 'New Office', 'plan' => 'office', 'status' => 'active',
            'manager_name' => 'مدير', 'manager_email' => 'new.manager@example.test', 'manager_password' => 'a-strong-password-123',
        ])->call('create')->assertHasNoFormErrors();
        $new = Subscriber::where('name_ar', 'مكتب جديد')->firstOrFail();
        $this->tenant()->set($new->id);
        $this->assertSame('manager', User::where('email', 'new.manager@example.test')->first()->role->value);
    }

    public function test_req_0271_each_role_lands_only_on_its_interface(): void
    {
        $this->actingAs($this->tenantUser)->get('/tech/tasks')->assertForbidden();
        $this->actingAs($this->tech)->get('/tenant/my-unit')->assertForbidden();
        $this->actingAs($this->tenantUser)->get('/platform/subscribers')->assertForbidden();
    }

    /** REQ-0271 — opening an interface (and signing in) lands on that role's own home, never an empty dashboard. */
    public function test_req_0271_interface_root_opens_the_role_home(): void
    {
        $this->actingAs($this->ownerUser)->get('/owner')->assertRedirect(url('/owner/statement'));
        $this->actingAs($this->tenantUser)->get('/tenant')->assertRedirect(url('/tenant/my-unit'));
        $this->actingAs($this->tech)->get('/tech')->assertRedirect(url('/tech/tasks'));
    }
}
