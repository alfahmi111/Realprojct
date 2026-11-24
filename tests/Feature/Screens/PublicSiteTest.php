<?php

namespace Tests\Feature\Screens;

use App\Models\SiteLead;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public website (owner request 2026-11-24): visitors see it at «/», a signed-in user still lands on their role's
 * interface (REQ-0271), the demo form stores a request with consent, and the platform admin reads the requests.
 * Every claim on the page is something the platform does today; prices are not shown until decided (Q36).
 */
class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_req_0271_visitors_see_the_site_and_signed_in_users_go_to_their_interface(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        foreach (['كل وحدة،', 'تعرف أين ذهب مالك', 'لكل طرف واجهته', 'الباقات', 'المنصة لا تستلم أموالاً', 'اطلب عرضاً', 'fonts/fonts.css'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);   // no external request
        $this->assertStringNotContainsString('ريال شهرياً', $html);            // no invented prices (Q36)

        $s = $this->makeSubscriber();
        $m = $this->makeUser($s, 'manager');
        $this->actingAs($m)->get('/')->assertRedirect(Filament::getPanel('manager')->getUrl());
    }

    public function test_the_demo_form_stores_a_request_with_consent_and_explains_errors_in_arabic(): void
    {
        $this->post('/demo', ['name' => 'سعد', 'phone' => '0512'])
            ->assertSessionHasErrors(['phone' => 'اكتب رقم جوال سعودياً يبدأ بـ 05 ويتكون من 10 أرقام.', 'consent' => 'وافق على استخدام بياناتك للتواصل معك بشأن العرض.']);
        $this->assertSame(0, SiteLead::count());

        $this->post('/demo', ['name' => 'سعد', 'organization' => 'مكتب الديار', 'phone' => '0512345678', 'units' => '21-300', 'consent' => '1'])
            ->assertRedirect(route('site.home') . '#demo')->assertSessionHas('demo_sent');
        $lead = SiteLead::firstOrFail();
        $this->assertSame(['سعد', '0512345678', 'new'], [$lead->name, $lead->phone, $lead->status]);
        $this->assertNotNull($lead->consented_at);

        $this->post('/demo', ['name' => 'روبوت', 'phone' => '0512345678', 'consent' => '1', 'website' => 'spam.example']);   // honeypot
        $this->assertSame(1, SiteLead::count());
    }

    public function test_the_platform_admin_reads_the_requests(): void
    {
        SiteLead::create(['name' => 'نورة', 'phone' => '0555555555', 'consented_at' => now()]);
        $this->tenant()->setPlatform(true);
        $admin = User::factory()->create(['subscriber_id' => null, 'role' => 'platform_admin']);
        $this->actingAs($admin)->get('/platform/site-leads')->assertOk()->assertSee('نورة')->assertSee('طلبات العرض من الموقع');
    }
}
