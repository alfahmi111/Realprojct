<?php

namespace Tests\Feature\Screens;

use App\Compliance\ExpiryAlerts;
use App\Integrations\IntegrationStatus;
use App\Models\Subscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M14 (owner approval 2026-11-23, Q31): office licence with alerts 30 and 7 days before (spec 6.7), integrations
 * status stated plainly in office settings (REQ-0489), redesigned create forms render with their sections.
 */
class FormsAndIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private Subscriber $s;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSubscriber();
        $this->manager = $this->makeUser($this->s, 'manager');
        $this->tenant()->set($this->s->id);
        $this->manager->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        Filament::setCurrentPanel(Filament::getPanel('manager'));
    }

    public function test_req_0489_integrations_status_never_shows_a_simulator_or_unchosen_provider_as_connected(): void
    {
        config(['integrations.ejar' => 'manual', 'integrations.payments' => 'simulator', 'integrations.sms' => 'none', 'integrations.whatsapp' => 'acme']);
        $rows = collect(IntegrationStatus::all())->keyBy('key');
        $this->assertSame('manual', $rows['ejar']['state']);
        $this->assertSame('simulator', $rows['payments']['state']);
        $this->assertSame('none', $rows['sms']['state']);
        $this->assertSame('live', $rows['whatsapp']['state']);
        $this->assertSame('none', $rows['nafath']['state']);   // P2 layer: not linked until a provider is chosen
        $this->assertCount(11, $rows);
    }

    public function test_q31_office_licence_alerts_managers_30_and_7_days_before_only(): void
    {
        $today = CarbonImmutable::parse('2026-11-23');
        $this->s->forceFill(['license_kind' => 'property_management', 'license_no' => 'L-77', 'license_expires_on' => $today->addDays(30)])->save();
        $this->assertSame(['office_license'], app(ExpiryAlerts::class)->run($today));
        $this->assertSame([], app(ExpiryAlerts::class)->run($today->addDay()));        // 29 days left: no repeat
        $this->assertSame(['office_license'], app(ExpiryAlerts::class)->run($today->addDays(23)));   // 7 days left
        $this->assertSame(2, DB::table('notifications_outbox')->where('event', 'document.expiring')->where('recipient_id', $this->manager->id)->where('channel', 'in_app')->count());
    }

    public function test_m14_settings_and_create_forms_render_their_sections(): void
    {
        $this->actingAs($this->manager);
        $pages = [
            '/manage/subscriber-settings' => ['رخصة المكتب', 'نوع الرخصة', 'التكاملات', 'يدوي بمساعدة النظام', 'نفاذ'],
            '/manage/tenants/create' => ['من المستأجر؟', 'التواصل', 'العنوان الوطني', 'منشأة'],
            '/manage/owners/create' => ['من المالك؟', 'الضريبة', 'ورثة'],
            '/manage/lease-offers/create' => ['الوحدة والمستأجر', 'المدة', 'المبلغ والدفعات', 'مبلغ الضمان'],
            '/manage/work-orders/create' => ['ما المشكلة؟', 'أين؟', 'طارئة'],
        ];
        $missing = [];
        foreach ($pages as $url => $needles) {
            $html = $this->get($url)->assertOk()->getContent();
            foreach ($needles as $n) {
                if (! str_contains($html, $n)) {
                    $missing[] = "$url : $n";
                }
            }
        }
        $this->assertSame([], $missing);
    }
}
