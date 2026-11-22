<?php

namespace Tests\Feature\Screens;

use App\Filament\Support\WithaqLogin as Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Real sign-in through the Filament login form (REQ-0505, REQ-0506, REQ-0487). */
class LoginFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_req_0487_manager_signs_in_through_the_form_and_gets_the_2fa_challenge(): void
    {
        $s = $this->makeSubscriber();
        $m = $this->makeUser($s, 'manager', ['email' => 'manager@withaq.test', 'password' => 'demo-password-123']);
        $this->tenant()->set($s->id);
        $m->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->tenant()->clear();
        Filament::setCurrentPanel(Filament::getPanel('manager'));
        $t = Livewire::test(Login::class)->fillForm(['email' => 'manager@withaq.test', 'password' => 'demo-password-123'])->call('authenticate');
        $t->assertHasNoFormErrors();
        $this->assertNotNull($t->get('userUndertakingMultiFactorAuthentication'));   // password accepted → 2FA step
    }

    /** REQ-0487 — a wrong code or recovery code shows "invalid", never a server error, even with no recovery codes saved. */
    public function test_req_0487_wrong_2fa_code_without_recovery_codes_is_a_validation_error_not_a_crash(): void
    {
        $s = $this->makeSubscriber();
        $m = $this->makeUser($s, 'manager', ['email' => 'manager@withaq.test', 'password' => 'demo-password-123']);
        $this->tenant()->set($s->id);
        $m->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->tenant()->clear();
        Filament::setCurrentPanel(Filament::getPanel('manager'));
        $t = Livewire::test(Login::class)->fillForm(['email' => 'manager@withaq.test', 'password' => 'demo-password-123'])->call('authenticate');
        $t->set('data.multiFactor.app.useRecoveryCode', true)->set('data.multiFactor.app.recoveryCode', 'not-a-code')->call('authenticate');
        $t->assertHasErrors();
        $this->assertGuest();
        $t2 = Livewire::test(Login::class)->fillForm(['email' => 'manager@withaq.test', 'password' => 'demo-password-123'])->call('authenticate');
        $t2->set('data.multiFactor.app.code', '000000')->call('authenticate');
        $t2->assertHasErrors();
        $this->assertGuest();
    }

    public function test_req_0505_tenant_without_2fa_signs_in_directly(): void
    {
        $s = $this->makeSubscriber();
        $this->makeUser($s, 'tenant', ['email' => 'tenant@withaq.test', 'password' => 'demo-password-123']);
        $this->tenant()->clear();
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Livewire::test(Login::class)->fillForm(['email' => 'tenant@withaq.test', 'password' => 'demo-password-123'])->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticated();
    }

    /** REQ-0271 — right password on another role's page: sent to the account's own sign-in page, not logged in, no 2FA skipped. */
    public function test_req_0271_valid_account_on_the_wrong_interface_is_sent_to_its_own_sign_in_page(): void
    {
        $s = $this->makeSubscriber();
        $m = $this->makeUser($s, 'manager', ['email' => 'manager@withaq.test', 'password' => 'demo-password-123']);
        $this->tenant()->set($s->id);
        $m->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->tenant()->clear();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        Livewire::test(Login::class)->fillForm(['email' => 'manager@withaq.test', 'password' => 'demo-password-123'])->call('authenticate')
            ->assertRedirect(Filament::getPanel('manager')->getLoginUrl() . '?email=manager%40withaq.test&moved=1');
        $this->assertGuest();
    }

    public function test_req_0505_a_wrong_password_still_gets_the_generic_message_everywhere(): void
    {
        $s = $this->makeSubscriber();
        $this->makeUser($s, 'manager', ['email' => 'manager@withaq.test', 'password' => 'demo-password-123']);
        $this->tenant()->clear();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        Livewire::test(Login::class)->fillForm(['email' => 'manager@withaq.test', 'password' => 'wrong-password-1'])->call('authenticate')->assertHasFormErrors(['email']);
    }
}
