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

    public function test_req_0271_the_new_page_is_previewed_at_its_own_path_and_the_current_page_stays(): void
    {
        $html = $this->get('/preview')->assertOk()->getContent();
        foreach (['كل وحدة،', 'تعرف أين ذهب مالك', 'أربع نوافذ على سجل واحد', 'ما لا تفعله المنصة', 'الباقات', 'site/v2/v2.css', 'fonts/fonts.css',
                     'site/v2/shots/dashboard.webp', 'name="from" value="preview"', 'noindex', 'مثال توضيحي'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);   // fonts are local
        $this->assertStringNotContainsString('ريال شهرياً', $html);            // no invented prices (Q36)
        foreach (['dashboard', 'statement', 'assets', 'facade', 'tenant', 'tech', 'map'] as $shot) {   // the real screens it shows
            $this->assertFileExists(public_path("site/v2/shots/$shot.webp"));
        }
        $this->assertFileExists(public_path('fonts/noto-kufi-arabic-var-arabic.woff2'));
        // the opening keeps the slogan while three stories slide under it, each with a pencil sketch and cards shaped like the system (owner, office, tenant + technician)
        foreach (['aria-roledescription="carousel"', 'للمالك', 'للمكتب', 'للمستأجر والفني', 'site/v2/art/sketch-villa.svg', 'w2-float', 'w2-pause', 'هكذا يرى المكتب مبناه كل صباح',
                     // the mamarat-like structure, in our own terms: why, apps in device frames, features, who, plans without prices (Q36)
                     'لماذا وِثاق؟', 'المزايا الأساسية', 'لمن صُمّم وِثاق؟', 'كل الباقات تشمل', 'السعر يُعلن عند الإطلاق', 'site/v2/shots/tenant.webp',
                     // a real photo in the first story, and the portfolio map (a real screen)
                     'site/v2/people/owner-photo.webp', 'صورة توضيحية', 'كل عقار تديره، في مكانه على الخريطة', 'site/v2/shots/map.webp', 'OpenStreetMap'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        foreach (['sketch-villa', 'sketch-offices', 'sketch-block', 'sketch-street'] as $art) {   // pencil sketches drawn for the page
            $svg = file_get_contents(public_path("site/v2/art/$art.svg"));
            $this->assertStringNotContainsString('<image', $svg, $art);   // vector drawings, no embedded photos
            $this->assertStringContainsString('<title>', $svg, $art);
        }
        // the current page is untouched and still at «/»
        $this->get('/')->assertOk()->assertSee('ثلاثة أشياء تعرفها دون أن تسأل أحداً')->assertDontSee('site/v2/v2.css', false);
        // a signed-in user can still review the preview (only «/» sends them to their interface)
        $s = $this->makeSubscriber();
        $this->actingAs($this->makeUser($s, 'manager'))->get('/preview')->assertOk();
    }

    public function test_the_demo_form_on_the_new_page_returns_to_the_new_page(): void
    {
        $this->post('/demo', ['name' => 'سعد', 'phone' => '0512345678', 'consent' => '1', 'from' => 'preview'])
            ->assertRedirect(route('site.preview') . '#demo')->assertSessionHas('demo_sent');
        $this->assertSame(1, SiteLead::count());
        $this->post('/demo', ['name' => 'سعد', 'phone' => '0512345678', 'consent' => '1', 'from' => 'elsewhere'])
            ->assertRedirect(route('site.home') . '#demo');   // anything else goes back to the current page
    }

    public function test_the_platform_admin_reads_the_requests(): void
    {
        SiteLead::create(['name' => 'نورة', 'phone' => '0555555555', 'consented_at' => now()]);
        $this->tenant()->setPlatform(true);
        $admin = User::factory()->create(['subscriber_id' => null, 'role' => 'platform_admin']);
        $this->actingAs($admin)->get('/platform/site-leads')->assertOk()->assertSee('نورة')->assertSee('طلبات العرض من الموقع');
    }
}
