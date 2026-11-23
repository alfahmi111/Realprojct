<?php

namespace Tests\Feature\Screens;

use App\Filament\Platform\Resources\Subscribers\Pages\CreateSubscriber;
use App\Filament\Platform\Resources\Subscribers\Pages\ViewSubscriber;
use App\Filament\Support\WithaqLogin;
use App\Models\AuditLog;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\Subscriber;
use App\Models\User;
use App\Plans\SubscriberAdmin;
use App\Properties\BuildingWizard;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * REQ-0065, REQ-0323 — the platform admin manages a subscriber: profile, subscription period and days remaining,
 * renewal, plan change within the unit limit (REQ-0634…REQ-0637), a suspension that really stops sign-in, usage
 * counts only (REQ-0453), and account support for the office's users. Every action lands in the office's audit log.
 */
class PlatformSubscribersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Subscriber $s;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSubscriber('owner', 'مكتب الندى');
        $this->manager = $this->makeUser($this->s, 'manager', ['email' => 'm@nada.test', 'password' => 'old-password-123']);
        $this->tenant()->clear();
        $this->tenant()->setPlatform(true);
        $this->admin = User::factory()->create(['subscriber_id' => null, 'role' => 'platform_admin']);
        Filament::setCurrentPanel(Filament::getPanel('platform'));
    }

    private function svc(): SubscriberAdmin
    {
        return app(SubscriberAdmin::class);
    }

    public function test_req_0065_admin_creates_a_subscriber_with_profile_subscription_and_first_manager(): void
    {
        Livewire::actingAs($this->admin)->test(CreateSubscriber::class)->fillForm([
            'name_ar' => 'شركة المدار', 'name_en' => 'Almadar', 'commercial_registration' => '1010123456', 'vat_number' => '300000000000003', 'city' => 'الرياض',
            'contact_name' => 'سالم', 'contact_phone' => '0500000000', 'contact_email' => 'info@madar.test',
            'plan' => 'office', 'billing_cycle' => 'annual', 'subscription_starts_on' => '2026-11-01', 'subscription_ends_on' => '2027-10-31', 'price_minor' => '12000',
            'addons' => ['waitlist'], 'manager_name' => 'مدير المدار', 'manager_email' => 'boss@madar.test', 'manager_password' => 'Strong-pass-2026',
        ])->call('create')->assertHasNoFormErrors();

        $s = Subscriber::where('name_en', 'Almadar')->firstOrFail();
        $this->assertSame(['office', 'annual', '2027-10-31', 1200000, ['waitlist'], 'active'], [$s->plan, $s->billing_cycle, $s->subscription_ends_on->toDateString(), $s->price_minor->minor(), $s->addons, $s->status]);
        $this->assertSame(1, $this->tenant()->run($s->id, fn () => User::where('email', 'boss@madar.test')->count()));
    }

    public function test_req_0323_the_subscriber_page_shows_days_remaining_usage_and_users(): void
    {
        $this->s->forceFill(['subscription_starts_on' => now()->subMonths(11)->toDateString(), 'subscription_ends_on' => now()->addDays(12)->toDateString(), 'billing_cycle' => 'annual'])->save();
        $html = $this->actingAs($this->admin)->get("/platform/subscribers/{$this->s->id}")->assertOk()->getContent();
        foreach (['ينتهي قريباً', '12', 'يوماً متبقية', 'الاستخدام', 'الوحدات', 'مستخدمو المكتب', 'm@nada.test', 'كلمة مرور مؤقتة', 'تجديد الاشتراك', 'إيقاف الاشتراك'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $list = $this->actingAs($this->admin)->get('/platform/subscribers')->assertOk()->getContent();
        $this->assertStringContainsString('متبقٍ 12 يوماً', $list);
    }

    public function test_req_0323_subscription_state_and_renewal(): void
    {
        $today = CarbonImmutable::parse('2026-11-23');
        $this->assertSame('no_period', $this->svc()->state($this->s, $today));
        $this->s->forceFill(['subscription_ends_on' => '2027-06-01'])->save();
        $this->assertSame('active', $this->svc()->state($this->s, $today));
        $this->s->forceFill(['subscription_ends_on' => '2026-12-10'])->save();
        $this->assertSame('expiring', $this->svc()->state($this->s, $today));
        $this->assertSame(17, $this->svc()->daysRemaining($this->s, $today));

        $this->svc()->renew($this->s, 12, $today);                                   // from the current end date
        $this->assertSame('2027-12-10', $this->s->fresh()->subscription_ends_on->toDateString());

        $this->s->forceFill(['subscription_ends_on' => '2026-10-01'])->save();      // already ended: from today
        $this->assertSame('expired', $this->svc()->state($this->s->fresh(), $today));
        $this->svc()->renew($this->s->fresh(), 1, $today);
        $this->assertSame('2026-12-22', $this->s->fresh()->subscription_ends_on->toDateString());
        $this->assertSame(2, $this->tenant()->run($this->s->id, fn () => AuditLog::where('action', 'subscription.renewed')->count()), 'in the office\'s own log');
    }

    public function test_req_0634_a_smaller_plan_is_refused_while_units_exceed_it(): void
    {
        $this->tenant()->run($this->s->id, function () {
            $w = app(BuildingWizard::class);
            $p = $w->createProperty(Portfolio::create(['name_ar' => 'أ']), ['name_ar' => 'برج'], [['owner' => Owner::create(['kind' => 'individual', 'name_ar' => 'م']), 'numerator' => 1, 'denominator' => 1]], 'residential_building');
            $w->generate($p, ['name_ar' => 'أ', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 18]]]]]);
        });
        $this->svc()->changePlan($this->s, 'office');
        $this->tenant()->run($this->s->id, function () {
            $p = \App\Models\Property::firstOrFail();
            app(BuildingWizard::class)->generate($p, ['name_ar' => 'ب', 'code_prefix' => 'B', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 5]]]]]);
        });
        try {
            $this->svc()->changePlan($this->s->fresh(), 'owner');
            $this->fail('23 units do not fit the 20-unit plan');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('23 وحدة', $e->getMessage());
        }
        $this->assertSame('office', $this->s->fresh()->plan);
    }

    public function test_req_0323_suspension_really_stops_the_office_and_reactivation_restores_it(): void
    {
        $manager = fn () => $this->tenant()->run($this->s->id, fn () => User::findOrFail($this->manager->id));   // RLS: read inside the office
        $this->svc()->suspend($this->s, 'تأخر سداد الاشتراك');
        $this->assertFalse($manager()->canAccessPanel(Filament::getPanel('manager')));

        Filament::setCurrentPanel(Filament::getPanel('manager'));
        Livewire::test(WithaqLogin::class)->fillForm(['email' => 'm@nada.test', 'password' => 'old-password-123'])->call('authenticate')
            ->assertHasFormErrors(['email'])->assertSee('اشتراك مكتبك موقوف');
        $this->assertGuest();

        $this->svc()->activate($this->s->fresh());
        $this->assertTrue($manager()->canAccessPanel(Filament::getPanel('manager')));
        $this->tenant()->run($this->s->id, function () {
            $this->assertSame(1, AuditLog::where('action', 'subscription.suspended')->count());
            $this->assertSame(1, AuditLog::where('action', 'subscription.activated')->count());
        });
    }

    public function test_req_0065_account_support_temporary_password_two_factor_and_disable(): void
    {
        $this->tenant()->run($this->s->id, fn () => $this->manager->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP'));
        DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $this->manager->id, 'payload' => '', 'last_activity' => time()]);

        $page = fn () => Livewire::actingAs($this->admin)->test(ViewSubscriber::class, ['record' => $this->s->id]);
        $page()->callAction(TestAction::make('setPassword')->arguments(['user' => $this->manager->id]), ['password' => 'short'])->assertHasFormErrors(['password']);
        $page()->callAction(TestAction::make('setPassword')->arguments(['user' => $this->manager->id]), ['password' => 'Temporary-pass-77'])->assertHasNoFormErrors();
        $page()->callAction(TestAction::make('resetTwoFactor')->arguments(['user' => $this->manager->id]));
        $page()->callAction(TestAction::make('toggleUser')->arguments(['user' => $this->manager->id, 'active' => true]));

        $m = $this->tenant()->run($this->s->id, fn () => User::findOrFail($this->manager->id));
        $this->assertTrue(Hash::check('Temporary-pass-77', $m->password));
        $this->assertNull($m->app_authentication_secret);
        $this->assertNotNull($m->archived_at);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->manager->id)->count(), 'signed-in devices are signed out');
        $this->assertSame(['support.password_set', 'support.two_factor_reset', 'support.user_disabled'],
            $this->tenant()->run($this->s->id, fn () => AuditLog::where('action', 'like', 'support.%')->orderBy('id')->pluck('action')->all()));
    }

    public function test_req_0323_header_actions_renew_and_suspend_from_the_page(): void
    {
        $this->s->forceFill(['subscription_ends_on' => now()->addMonth()->toDateString()])->save();
        $end = $this->s->subscription_ends_on;
        Livewire::actingAs($this->admin)->test(ViewSubscriber::class, ['record' => $this->s->id])
            ->callAction('renew', ['months' => 12])
            ->callAction('suspend', ['reason' => 'طلب المكتب إيقافاً مؤقتاً']);
        $s = $this->s->fresh();
        $this->assertSame($end->copy()->addMonthsNoOverflow(12)->toDateString(), $s->subscription_ends_on->toDateString());
        $this->assertTrue($s->isSuspended());
        $this->assertSame('طلب المكتب إيقافاً مؤقتاً', $s->suspended_reason);
    }

    public function test_req_0453_support_and_usage_never_reach_platform_accounts(): void
    {
        $this->assertSame(['manager' => 1], $this->svc()->usage($this->s)['users_by_role'], 'the platform admin is not one of the office\'s users');
        $this->assertSame([$this->manager->id], array_column($this->svc()->users($this->s), 'id'));
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->svc()->setTemporaryPassword($this->s, $this->admin->id, 'Another-pass-2026');
    }

    public function test_req_0323_last_sign_in_is_recorded(): void
    {
        event(new \Illuminate\Auth\Events\Login('web', $this->manager, false));
        $this->assertNotNull($this->tenant()->run($this->s->id, fn () => User::findOrFail($this->manager->id)->last_login_at));
    }
}
