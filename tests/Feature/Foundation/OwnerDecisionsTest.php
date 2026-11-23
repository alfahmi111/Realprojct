<?php

namespace Tests\Feature\Foundation;

use App\Finance\FinanceException;
use App\Finance\VatEngine;
use App\Http\Middleware\EnforceSessionTimeout;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

/** Owner decisions of 2026-11-23 (QUESTIONS.md Q10, Q18) applied to behaviour. */
class OwnerDecisionsTest extends TestCase
{
    use RefreshDatabase;

    /** REQ-0414, REQ-0420 — Q18: 15% applies from 2020-07-01, so imported invoices with earlier dates find their rate. */
    public function test_req_0414_vat_rate_applies_from_2020_07_01(): void
    {
        $vat = app(VatEngine::class);
        $this->assertSame(1500, $vat->standardRateBp(CarbonImmutable::parse('2020-07-01')));
        $this->assertSame(1500, $vat->standardRateBp(CarbonImmutable::parse('2024-03-15')));
        $this->expectException(FinanceException::class);
        $vat->standardRateBp(CarbonImmutable::parse('2020-06-30'));   // the 5% period is not in the table: refused, not guessed
    }

    /** REQ-0370 — Q10: passwords need at least 12 characters by default; the subscriber can raise it. */
    public function test_req_0370_password_policy_default_and_subscriber_override(): void
    {
        $s = $this->makeSubscriber();
        $this->tenant()->set($s->id);
        $this->assertTrue(Validator::make(['p' => 'short-pass1'], ['p' => [Password::default()]])->fails());
        $this->assertFalse(Validator::make(['p' => 'twelve-chars'], ['p' => [Password::default()]])->fails());
        app(Settings::class)->setSubscriber('security.password_policy', ['min_length' => 16]);
        $this->assertTrue(Validator::make(['p' => 'twelve-chars'], ['p' => [Password::default()]])->fails());
    }

    /** REQ-0370 — Q10: after 120 idle minutes (default) the session ends and the user signs in again. */
    public function test_req_0370_idle_session_ends_after_the_configured_minutes(): void
    {
        $s = $this->makeSubscriber();
        $m = $this->makeUser($s, 'manager');
        $this->tenant()->set($s->id);
        $m->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        Filament::setCurrentPanel(Filament::getPanel('manager'));
        $this->actingAs($m)->withSession([EnforceSessionTimeout::KEY => time() - 119 * 60])->get('/manage/invoices')->assertOk();
        $this->actingAs($m)->withSession([EnforceSessionTimeout::KEY => time() - 121 * 60])->get('/manage/invoices')->assertRedirect(url('/manage/login'));
        $this->assertGuest();
    }
}
