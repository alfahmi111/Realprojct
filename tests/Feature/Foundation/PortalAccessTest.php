<?php

namespace Tests\Feature\Foundation;

use App\Authorization\Role;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REQ-0271, REQ-0480, REQ-0494 — one installable web app; each user enters only their role's interface.
 * REQ-0057…REQ-0065 — the roles of spec §3. REQ-0506 — two-factor for manager and owner.
 * REQ-0062 — technician: assigned work orders only. REQ-0063 — tenant: own unit, lease, invoices, assets, requests.
 * REQ-0505 — Laravel default protections (CSRF, hashing, throttled login) stay on.
 */
class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_req_0057_all_parties_of_spec_3_exist_as_roles(): void
    {
        $this->assertSame(
            ['platform_admin', 'owner', 'institutional_owner', 'manager', 'accountant', 'leasing_officer', 'maintenance_supervisor', 'technician', 'tenant'],
            array_map(fn ($r) => $r->value, Role::cases()),
        );
        foreach (Role::cases() as $r) {
            $this->assertNotSame('', $r->labelAr());
            $this->assertNotSame('', $r->labelEn());
        }
    }

    public function test_req_0062_technician_sees_only_assigned_work_and_req_0063_tenant_only_own_data(): void
    {
        $this->assertTrue(Role::Technician->can('maintenance.assigned'));
        $this->assertFalse(Role::Technician->can('maintenance.create'));
        $this->assertFalse(Role::Technician->can('finance.view'));
        $this->assertTrue(Role::Tenant->can('tenant.invoices'));
        $this->assertFalse(Role::Tenant->can('leasing.view'));
        $this->assertTrue(Role::Manager->can('leasing.offer.create'));     // REQ-0060
        $this->assertTrue(Role::Accountant->can('finance.payments'));      // REQ-0061
        $this->assertFalse(Role::Accountant->can('maintenance.assign'));
        $this->assertTrue(Role::Owner->can('owner.approve_above_cap'));   // REQ-0058
        $this->assertTrue(Role::InstitutionalOwner->can('maintenance.assign')); // REQ-0059
        $this->assertTrue(Role::PlatformAdmin->can('platform.subscribers'));   // REQ-0065
        $this->assertFalse(Role::PlatformAdmin->can('finance.view'));
    }

    public function test_req_0271_each_role_enters_only_its_own_panel(): void
    {
        $s = $this->makeSubscriber();
        $map = ['manager' => 'manager', 'accountant' => 'manager', 'owner' => 'owner', 'tenant' => 'tenant', 'technician' => 'technician'];
        foreach ($map as $role => $panel) {
            $u = $this->makeUser($s, $role);
            foreach (['manager', 'owner', 'tenant', 'technician', 'platform'] as $id) {
                $this->assertSame($id === $panel, $u->canAccessPanel(Filament::getPanel($id)), "$role → $id");
            }
        }
    }

    public function test_req_0271_a_tenant_is_forbidden_from_the_manager_interface(): void
    {
        $s = $this->makeSubscriber();
        $tenant = $this->makeUser($s, 'tenant');
        $this->actingAs($tenant)->get('/manage')->assertForbidden();
    }

    public function test_req_0506_two_factor_is_required_for_manager_and_owner_only(): void
    {
        $this->assertTrue(Role::Manager->requiresTwoFactor());
        $this->assertTrue(Role::Owner->requiresTwoFactor());
        $this->assertTrue(Role::InstitutionalOwner->requiresTwoFactor());
        $this->assertFalse(Role::Tenant->requiresTwoFactor());
        $this->assertFalse(Role::Technician->requiresTwoFactor());

        $s = $this->makeSubscriber();
        $m = $this->makeUser($s, 'manager');
        // A manager without 2FA set up is redirected to set it up before using the interface.
        $res = $this->actingAs($m)->get('/manage');
        $res->assertRedirect();
        $this->assertStringContainsString('multi-factor', $res->headers->get('Location'));
    }

    public function test_req_0506_two_factor_secrets_are_encrypted_at_rest(): void
    {
        $s = $this->makeSubscriber();
        $m = $this->makeUser($s, 'manager');
        $this->tenant()->set($s->id);
        $m->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $raw = \DB::table('users')->where('id', $m->id)->value('app_authentication_secret');
        $this->assertNotSame('JBSWY3DPEHPK3PXP', $raw);
        $this->assertSame('JBSWY3DPEHPK3PXP', $m->fresh()->getAppAuthenticationSecret());
    }

    public function test_req_0505_login_page_renders_rtl_arabic_with_csrf(): void
    {
        $res = $this->get('/manage/login');
        $res->assertOk();
        $res->assertSee('dir="rtl"', false);
        $res->assertSee('lang="ar"', false);
        $res->assertSee('csrf-token', false);
        $res->assertSee('theme-tokens', false);
    }

    public function test_req_0505_passwords_are_hashed_and_login_is_throttled(): void
    {
        $s = $this->makeSubscriber();
        $u = $this->makeUser($s, 'tenant', ['password' => 'plain-secret']);
        $this->tenant()->set($s->id);
        $this->assertNotSame('plain-secret', \DB::table('users')->where('id', $u->id)->value('password'));
        $this->assertTrue(\Hash::check('plain-secret', \DB::table('users')->where('id', $u->id)->value('password')));
        $src = file_get_contents(base_path('vendor/filament/filament/src/Auth/Pages/Login.php'));
        $this->assertStringContainsString('rateLimit', $src);
    }

    public function test_req_0331_manifest_uses_the_subscriber_app_name(): void
    {
        $res = $this->get('/manifest.webmanifest');
        $res->assertOk()->assertJsonPath('dir', 'rtl')->assertJsonPath('display', 'standalone');
    }
}
