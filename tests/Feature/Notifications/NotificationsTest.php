<?php

namespace Tests\Feature\Notifications;

use App\Finance\InvoiceService;
use App\Integrations\Simulators;
use App\Leasing\LeasingService;
use App\Models\LeasableUnit;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\SubscriberIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NotificationCenter;
use App\Notifications\PaymentReminders;
use App\Properties\BuildingWizard;
use App\Settings\Settings;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Spec §7.6د notifications and §5.8 reminders. Covered IDs:
 * REQ-0353, REQ-0354, REQ-0355, REQ-0356, REQ-0357, REQ-0358, REQ-0359, REQ-0360, REQ-0361, REQ-0496,
 * REQ-0050, REQ-0205, REQ-0206, REQ-0207, REQ-0468, REQ-0470, REQ-0584.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Tenant $lessee;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->makeSubscriber();
        $this->manager = $this->makeUser($s, 'manager', ['phone' => '966500000001']);
        $this->tenant()->set($s->id);
        $this->lessee = Tenant::create(['kind' => 'individual', 'name_ar' => 'سعد', 'phone' => '966500000002', 'messages_locale' => 'ar']);
        config(['integrations.whatsapp' => 'simulator', 'integrations.sms' => 'simulator']);
        foreach ([\App\Integrations\WhatsApp\WhatsAppGateway::class, \App\Integrations\Sms\SmsGateway::class, NotificationCenter::class] as $c) {
            $this->app->forgetInstance($c);
        }
        SubscriberIntegration::create(['integration' => 'whatsapp', 'account_holder_name' => 'مكتب', 'credentials' => ['token' => 'x']]);
    }

    private function nc(): NotificationCenter
    {
        return app(NotificationCenter::class);
    }

    private function settings(): Settings
    {
        return app(Settings::class);
    }

    public function test_req_0353_event_channel_recipient_as_configured_by_the_subscriber(): void
    {
        $this->settings()->setSubscriber('notifications.events', ['payment.received' => ['enabled' => true, 'channels' => ['whatsapp', 'email']], 'inventory.due' => ['enabled' => false]]);
        $ids = $this->nc()->dispatch('payment.received', [$this->manager, $this->lessee], ['amount' => '100.00'], CarbonImmutable::parse('2027-01-01 10:00', 'UTC'));
        $rows = DB::table('notifications_outbox')->whereIn('id', $ids)->get();
        $this->assertEqualsCanonicalizing(['in_app', 'whatsapp', 'email'], $rows->where('recipient_type', 'user')->pluck('channel')->all());
        $this->assertEqualsCanonicalizing(['whatsapp', 'email'], $rows->where('recipient_type', 'tenant')->pluck('channel')->all());
        $this->assertSame([], $this->nc()->dispatch('inventory.due', [$this->manager], [], CarbonImmutable::now()));   // disabled event
        $this->assertSame(['in_app', 'push', 'whatsapp', 'sms', 'email'], NotificationCenter::CHANNELS);   // REQ-0354
    }

    public function test_req_0356_mandatory_events_cannot_be_turned_off_by_users(): void
    {
        $this->settings()->setSubscriber('notifications.events', ['maintenance.emergency' => ['enabled' => true, 'channels' => ['sms']], 'payment.received' => ['enabled' => true, 'channels' => ['sms']]]);
        $this->settings()->setSubscriber('notifications.mandatory', ['maintenance.emergency', 'security.new_device']);
        $this->settings()->setUser($this->manager->id, 'notifications.preferences', ['maintenance.emergency' => [], 'payment.received' => []]);
        $emergency = $this->nc()->dispatch('maintenance.emergency', [$this->manager], [], CarbonImmutable::now());
        $normal = $this->nc()->dispatch('payment.received', [$this->manager], [], CarbonImmutable::now());
        $this->assertCount(2, $emergency);   // in_app + sms despite the user's preference
        $this->assertCount(1, $normal);      // user narrowed to in_app only
    }

    public function test_req_0357_quiet_hours_hold_whatsapp_and_sms_to_tenants_except_emergencies(): void
    {
        $this->settings()->setSubscriber('notifications.events', ['payment.due' => ['enabled' => true, 'channels' => ['whatsapp']], 'maintenance.emergency' => ['enabled' => true, 'channels' => ['sms']]]);
        $night = CarbonImmutable::parse('2027-01-01 20:30', 'UTC');   // 23:30 Riyadh
        [$id] = $this->nc()->dispatch('payment.due', [$this->lessee], [], $night);
        $this->assertSame('2027-01-02 05:00:00', CarbonImmutable::parse(DB::table('notifications_outbox')->find($id)->send_after)->utc()->format('Y-m-d H:i:s'));   // 08:00 Riyadh
        [$e] = $this->nc()->dispatch('maintenance.emergency', [$this->lessee], [], $night);
        $this->assertSame($night->format('Y-m-d H:i'), CarbonImmutable::parse(DB::table('notifications_outbox')->find($e)->send_after)->utc()->format('Y-m-d H:i'));
        // Adjustable quiet hours.
        $this->settings()->setSubscriber('notifications.quiet_hours', ['start' => '23:00', 'end' => '07:00']);
        $this->assertSame('2027-01-01 19:30:00', $this->nc()->sendAfter('sms', false, false, CarbonImmutable::parse('2027-01-01 19:30', 'UTC'))->toDateTimeString());   // 22:30 Riyadh is no longer quiet
    }

    public function test_req_0360_templates_with_variables_in_the_recipient_language_and_whatsapp_needs_meta_approval(): void
    {
        DB::table('notification_templates')->insert([
            ['subscriber_id' => $this->manager->subscriber_id, 'event' => 'payment.due', 'channel' => 'whatsapp', 'locale' => 'ar', 'body' => 'عزيزي {tenant_name}، دفعة الوحدة {unit} بمبلغ {amount} مستحقة في {due_date}', 'provider_template_name' => 'rent_due_ar', 'meta_approved' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->settings()->setSubscriber('notifications.events', ['payment.due' => ['enabled' => true, 'channels' => ['whatsapp']]]);
        $at = CarbonImmutable::parse('2027-01-01 09:00', 'UTC');
        [$id] = $this->nc()->dispatch('payment.due', [$this->lessee], ['tenant_name' => 'سعد', 'unit' => '101', 'amount' => '5000.00', 'due_date' => '2027-01-01'], $at);
        $this->assertSame('عزيزي سعد، دفعة الوحدة 101 بمبلغ 5000.00 مستحقة في 2027-01-01', DB::table('notifications_outbox')->find($id)->body);
        $this->assertSame(['blocked_template' => 1], $this->nc()->deliver($at));
        DB::table('notification_templates')->update(['meta_approved' => true]);
        [$id2] = $this->nc()->dispatch('payment.due', [$this->lessee], ['tenant_name' => 'سعد'], $at);
        $this->assertSame(['sent' => 1], $this->nc()->deliver($at));
        $this->assertSame(['whatsapp' => 1], $this->nc()->usage('2027-01'));   // REQ-0361
    }

    public function test_req_0358_daily_digest_replaces_scattered_messages(): void
    {
        $this->settings()->setSubscriber('notifications.events', ['approval.waiting' => ['enabled' => true, 'channels' => ['email']]]);
        $this->settings()->setUser($this->manager->id, 'notifications.daily_digest', true);
        $at = CarbonImmutable::parse('2027-01-01 09:00', 'UTC');
        foreach (range(1, 3) as $i) {
            $this->nc()->dispatch('approval.waiting', [$this->manager], ['n' => (string) $i], $at);
        }
        $stats = $this->nc()->deliver($at);
        $this->assertSame(3, $stats['in_digest']);
        $this->assertSame(1, $this->nc()->sendDigests($at));
        $this->assertSame(1, DB::table('notifications_outbox')->where('body', 'like', 'ملخص اليوم: 3%')->count());
    }

    public function test_req_0359_unhandled_items_escalate_to_the_next_level(): void
    {
        $sup = $this->makeUser(\App\Models\Subscriber::find($this->manager->subscriber_id), 'maintenance_supervisor');
        $this->tenant()->set($this->manager->subscriber_id);
        $at = CarbonImmutable::parse('2027-01-01 09:00', 'UTC');
        $this->nc()->dispatch('work_order.late', [$sup], ['wo' => 'WO-1'], $at, escalate: ['after_hours' => 4, 'to_role' => 'manager']);
        $this->assertSame(0, $this->nc()->escalate($at->addHours(3)));
        $this->assertSame(1, $this->nc()->escalate($at->addHours(5)));
        $this->assertSame(1, DB::table('notifications_outbox')->where('recipient_id', $this->manager->id)->where('event', 'work_order.late')->count());
        $this->assertSame(0, $this->nc()->escalate($at->addHours(6)));   // once per item
    }

    public function test_req_0207_reminders_on_due_day_and_after_7_days_with_escalation_to_manager_and_owner_above_threshold(): void
    {
        $ownerUser = $this->makeUser(\App\Models\Subscriber::find($this->manager->subscriber_id), 'owner');
        $this->tenant()->set($this->manager->subscriber_id);
        $w = app(BuildingWizard::class);
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك', 'user_id' => $ownerUser->id]);
        $p = $w->createProperty(Portfolio::create(['name_ar' => 'م']), ['name_ar' => 'ع'], [['owner' => $owner, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $w->generate($p, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 1]]]]]);
        $ls = app(LeasingService::class);
        $o = $ls->createOffer($this->manager, LeasableUnit::first(), $this->lessee, ['usage' => 'residential', 'starts_on' => '2027-01-01', 'duration_unit' => 'year', 'duration_count' => 1, 'annual_rent_minor' => Money::parse('60000'), 'payment_frequency' => 'quarterly']);
        $lease = $ls->acceptOffer($ls->sendOffer($ls->submitOffer($o)));
        $ls->sendForRegistration($lease);
        $ls->markAwaitingParties($lease);
        $ls->register($lease, 'EJ-9');
        app(InvoiceService::class)->issueRent($lease->scheduleLines->first(), CarbonImmutable::parse('2026-12-20'));
        $this->settings()->setSubscriber('leasing.payment_reminders', ['before_due_days' => 3, 'owner_threshold_minor' => 1000000]);

        $r = app(PaymentReminders::class);
        $this->assertSame(['before' => 1, 'due' => 0, 'second' => 0], $r->run(CarbonImmutable::parse('2026-12-29')));
        $this->assertSame(['before' => 0, 'due' => 1, 'second' => 0], $r->run(CarbonImmutable::parse('2027-01-01')));
        $this->assertSame(['before' => 0, 'due' => 0, 'second' => 0], $r->run(CarbonImmutable::parse('2027-01-01')));   // once
        $this->assertSame(['before' => 0, 'due' => 0, 'second' => 1], $r->run(CarbonImmutable::parse('2027-01-08')));
        $this->assertSame(1, DB::table('notifications_outbox')->where('recipient_id', $this->manager->id)->where('channel', 'in_app')->whereRaw("payload->>'stage' = 'escalated_to_manager'")->count());
        $this->assertSame(1, DB::table('notifications_outbox')->where('recipient_id', $ownerUser->id)->where('channel', 'in_app')->whereRaw("payload->>'stage' = 'escalated_to_owner'")->count());   // 15,000 > 10,000 threshold
    }

    public function test_req_0496_without_a_provider_whatsapp_is_blocked_not_lost(): void
    {
        config(['integrations.whatsapp' => 'none']);
        $this->app->forgetInstance(\App\Integrations\WhatsApp\WhatsAppGateway::class);
        $this->app->forgetInstance(NotificationCenter::class);
        DB::table('notification_templates')->insert(['subscriber_id' => $this->manager->subscriber_id, 'event' => 'payment.due', 'channel' => 'whatsapp', 'locale' => 'ar', 'body' => 'x', 'meta_approved' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->settings()->setSubscriber('notifications.events', ['payment.due' => ['enabled' => true, 'channels' => ['whatsapp']]]);
        $at = CarbonImmutable::parse('2027-01-01 09:00', 'UTC');
        $this->nc()->dispatch('payment.due', [$this->lessee], [], $at);
        $this->assertSame(['blocked_provider' => 1], $this->nc()->deliver($at));
    }
}
