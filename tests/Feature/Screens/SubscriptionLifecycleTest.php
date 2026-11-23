<?php

namespace Tests\Feature\Screens;

use App\Filament\Manager\Pages\SubscriberSettingsPage;
use App\Filament\Manager\Resources\Owners\Pages\CreateOwner;
use App\Filament\Platform\Pages\PlatformSettingsPage;
use App\Filament\Platform\Resources\Subscribers\Pages\ViewSubscriber;
use App\Models\AuditLog;
use App\Models\Owner;
use App\Models\Subscriber;
use App\Models\User;
use App\Plans\ReadOnlyGuard;
use App\Plans\SubscriberAdmin;
use App\Plans\SubscriptionNotices;
use App\Plans\SubscriptionReadOnlyException;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Q24 (owner's decision 2026-11-23) — after the subscription ends: 7-day grace period (platform default, extendable per
 * subscriber) with a daily notice, then read-only automatically: read and export, nothing saved by the office's users.
 * Also the links between the subscriber record and the rest of the platform (REQ-0065, REQ-0323, REQ-0333…REQ-0337).
 */
class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Subscriber $s;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSubscriber('office', 'مكتب الأفق');
        $this->manager = $this->makeUser($this->s, 'manager', ['email' => 'm@ufuq.test']);
        $this->tenant()->set($this->s->id);
        $this->manager->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        Filament::setCurrentPanel(Filament::getPanel('manager'));
    }

    private function endedDaysAgo(int $days): void
    {
        // Straight to the row: the test may be signed in as the office manager, whom the read-only guard would (rightly) stop.
        DB::table('subscribers')->where('id', $this->s->id)->update(['subscription_ends_on' => CarbonImmutable::today()->subDays($days)->toDateString()]);
        $this->freshState();
    }

    /** Forget per-request caches only (settings values, read-only state) — never the tenant context or the panel. */
    private function freshState(): void
    {
        app()->forgetInstance(Settings::class);
        app()->forgetInstance(ReadOnlyGuard::class);
        app()->forgetInstance(SubscriberAdmin::class);
    }

    private function svc(): SubscriberAdmin
    {
        return app(SubscriberAdmin::class);
    }

    public function test_q24_grace_for_seven_days_then_read_only_and_a_per_subscriber_extension(): void
    {
        $today = CarbonImmutable::today();
        $this->endedDaysAgo(0);
        $this->assertSame('expiring', $this->svc()->state($this->s->fresh(), $today), 'ends today');
        $this->endedDaysAgo(1);
        $this->assertSame(['grace', 6], [$this->svc()->state($this->s->fresh(), $today), $this->svc()->graceLeft($this->s->fresh(), $today)]);
        $this->endedDaysAgo(7);
        $this->assertSame(['grace', 0], [$this->svc()->state($this->s->fresh(), $today), $this->svc()->graceLeft($this->s->fresh(), $today)], 'day 7 is the last grace day');
        $this->endedDaysAgo(8);
        $this->assertSame('read_only', $this->svc()->state($this->s->fresh(), $today));

        $this->svc()->extendGrace($this->s->fresh(), 7);                                   // this subscriber only
        $this->assertSame(['grace', 6], [$this->svc()->state($this->s->fresh(), $today), $this->svc()->graceLeft($this->s->fresh(), $today)]);
        $other = $this->makeSubscriber('office', 'آخر');
        $this->assertSame(7, $this->svc()->graceDays($other), 'others keep the platform default');

        $this->svc()->renew($this->s->fresh(), 12, $today);
        $this->assertNull($this->s->fresh()->grace_days, 'the extension belonged to the period that ended');
        $this->assertSame(1, AuditLog::where('action', 'subscription.grace_extended')->count());
    }

    public function test_q24_read_only_office_users_cannot_save_but_system_and_own_account_can(): void
    {
        $this->endedDaysAgo(10);
        $this->actingAs($this->manager);
        try {
            Owner::create(['kind' => 'individual', 'name_ar' => 'مالك جديد']);
            $this->fail('read-only office saved a record');
        } catch (SubscriptionReadOnlyException $e) {
            $this->assertStringContainsString('وضع القراءة فقط', $e->getMessage());
        }
        $this->manager->forceFill(['name' => 'اسم معدّل'])->save();                       // own account: allowed
        \App\Audit\AuditLogger::record('test.read', 'x', null, null, '1');                  // audit log: allowed

        auth()->logout();
        $this->freshState();
        Owner::create(['kind' => 'individual', 'name_ar' => 'من مهمة النظام']);              // no signed-in user: system process
        $this->assertSame(1, Owner::count());

        $this->tenant()->run(null, fn () => $this->svc()->renew($this->s->fresh(), 1, CarbonImmutable::today()));
        $this->freshState();
        $this->actingAs($this->manager);
        Owner::create(['kind' => 'individual', 'name_ar' => 'بعد التجديد']);
        $this->assertSame(2, Owner::count());
    }

    public function test_q24_a_read_only_office_can_still_export_all_its_data(): void
    {
        Owner::create(['kind' => 'individual', 'name_ar' => 'مالك قبل الانتهاء']);
        $this->endedDaysAgo(30);
        $this->actingAs($this->manager);
        $this->assertTrue(app(ReadOnlyGuard::class)->active());
        $zip = app(\App\Migration\DataExport::class)->export();          // ر-20: export stays open
        $this->assertFileExists($zip);
        $this->assertGreaterThan(0, filesize($zip));
    }

    public function test_q24_the_refusal_reaches_the_screen_as_a_message_not_an_error(): void
    {
        $this->endedDaysAgo(10);
        Livewire::actingAs($this->manager)->test(CreateOwner::class)
            ->fillForm(['kind' => 'individual', 'name_ar' => 'مالك'])->call('create')
            ->assertNotified('وضع القراءة فقط');
        $this->assertSame(0, Owner::count());
    }

    public function test_q24_office_staff_see_the_notice_tenants_do_not(): void
    {
        $this->endedDaysAgo(2);
        $this->actingAs($this->manager)->get('/manage')->assertOk()->assertSee('مهلة السماح')->assertSee('متبقٍ 5 يوماً');
        $this->endedDaysAgo(9);
        $this->actingAs($this->manager)->get('/manage')->assertOk()->assertSee('وضع القراءة فقط');
        $this->endedDaysAgo(-365);   // renewed a year ahead
        $this->freshState();
        $this->actingAs($this->manager)->get('/manage')->assertOk()->assertDontSee('wq-subnotice', false);
    }

    public function test_q24_daily_notice_during_grace_and_once_on_the_first_read_only_day(): void
    {
        $count = fn (string $e) => DB::table('notifications_outbox')->where('event', $e)->count();
        $this->endedDaysAgo(3);
        $this->assertSame('subscription.grace', app(SubscriptionNotices::class)->run(CarbonImmutable::today()));
        $this->endedDaysAgo(8);
        $this->assertSame('subscription.read_only', app(SubscriptionNotices::class)->run(CarbonImmutable::today()));
        $this->endedDaysAgo(9);
        $this->assertNull(app(SubscriptionNotices::class)->run(CarbonImmutable::today()), 'only once');
        $this->assertGreaterThan(0, $count('subscription.grace'));
        $this->assertGreaterThan(0, $count('subscription.read_only'));
    }

    public function test_req_0337_a_smaller_plan_takes_back_its_customizations(): void
    {
        $this->tenant()->run($this->s->id, fn () => app(Settings::class)->setSubscriber('theme.custom_primary', '#0D5C63'));
        $this->tenant()->run(null, fn () => $this->svc()->changePlan($this->s->fresh(), 'owner'));
        $this->freshState();
        $this->assertNull(app(Settings::class)->get('theme.custom_primary'));
        $log = AuditLog::where('action', 'subscription.plan_changed')->latest('id')->first();
        $this->assertSame(['theme.custom_primary'], $log->after['cleared']);
    }

    public function test_req_0065_the_office_edits_its_own_record_and_the_platform_sees_the_same_row(): void
    {
        $page = fn () => Livewire::actingAs($this->manager)->test(SubscriberSettingsPage::class);
        $page()->fillForm(['finance__vat_registered' => true, 'office__vat_number' => ''])->call('save')->assertHasFormErrors(['office__vat_number' => 'required']);
        $page()->fillForm(['office__vat_number' => '123'])->call('save')->assertHasFormErrors(['office__vat_number']);
        $page()->fillForm(['finance__vat_registered' => true, 'office__vat_number' => '310123456700003', 'office__commercial_registration' => '1010999999', 'office__city' => 'جدة'])
            ->call('save')->assertHasNoFormErrors();

        $row = $this->tenant()->run(null, fn () => Subscriber::find($this->s->id));
        $this->assertSame(['310123456700003', '1010999999', 'جدة'], [$row->vat_number, $row->commercial_registration, $row->city]);
        $this->assertTrue((bool) app(Settings::class)->get('finance.vat_registered'));

        $this->tenant()->clear();
        $this->tenant()->setPlatform(true);
        $admin = User::factory()->create(['subscriber_id' => null, 'role' => 'platform_admin']);
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs($admin)->get("/platform/subscribers/{$this->s->id}")->assertOk()->assertSee('310123456700003')->assertSee('جدة');
    }

    public function test_q24_platform_default_grace_is_editable_and_the_page_offers_the_extension(): void
    {
        $this->tenant()->clear();
        $this->tenant()->setPlatform(true);
        $admin = User::factory()->create(['subscriber_id' => null, 'role' => 'platform_admin']);
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        Livewire::actingAs($admin)->test(PlatformSettingsPage::class)->fillForm(['grace_days' => 10, 'warning_days' => 21])->call('save');
        $this->freshState();
        $this->assertSame(10, app(Settings::class)->get('subscription.grace_days'));

        $this->s->forceFill(['subscription_ends_on' => CarbonImmutable::today()->subDays(12)->toDateString()])->save();
        Livewire::actingAs($admin)->test(ViewSubscriber::class, ['record' => $this->s->id])
            ->assertActionVisible('extendGrace')->callAction('extendGrace', ['days' => 7]);
        $this->assertSame(17, $this->s->fresh()->grace_days);
        $this->assertSame('grace', app(SubscriberAdmin::class)->state($this->s->fresh(), CarbonImmutable::today()));
    }
}
