<?php

namespace Tests\Feature\Screens;

use App\Notifications\NotificationCenter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner request 2026-11-24: the header's speaker button mutes or enables the notification sound (as in the owner's
 * previous system); the header polls a small heartbeat and chimes when tasks or unread notifications grow (REQ-0359).
 */
class HeaderSoundTest extends TestCase
{
    use RefreshDatabase;

    public function test_req_0359_the_header_carries_the_sound_switch_and_the_pulse_counts_what_waits(): void
    {
        $s = $this->makeSubscriber();
        $m = $this->makeUser($s, 'manager');
        $this->tenant()->set($s->id);
        $m->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $html = $this->actingAs($m)->get('/manage')->assertOk()->getContent();
        $this->assertStringContainsString('wqSound()', $html);
        $this->assertStringContainsString('كتم صوت التنبيهات', $html);

        $this->getJson('/pulse')->assertOk()->assertJson(['unread' => 0]);
        app(NotificationCenter::class)->dispatch('approval.waiting', [$m], ['الإجراء' => 'إلغاء فاتورة'], CarbonImmutable::now());
        $this->getJson('/pulse')->assertOk()->assertJson(['unread' => 1]);

        auth()->logout();
        $this->getJson('/pulse')->assertUnauthorized();
    }
}
