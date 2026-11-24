<?php

namespace Tests;

use App\Models\Subscriber;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The app's «today» is Riyadh's date (Today::date()); tests often write CarbonImmutable::today() (UTC). From 21:00
        // to midnight UTC the two differ by a day, so a run in that window pins the clock to 20:00 UTC of the same day.
        if ((int) gmdate('G') >= 21) {
            \Carbon\Carbon::setTestNow(\Carbon\Carbon::now('UTC')->setTime(20, 0));
            \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::now('UTC')->setTime(20, 0));
        }
        if (in_array(\Illuminate\Foundation\Testing\RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            app(TenantContext::class)->clear();
        }
    }

    protected function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    protected function makeSubscriber(string $plan = 'office', string $name = 'مكتب'): Subscriber
    {
        return $this->tenant()->run(null, fn () => Subscriber::create(['name_ar' => $name, 'name_en' => 'Office', 'plan' => $plan]));
    }

    /** Create a user inside its subscriber's context (RLS WITH CHECK requires it). */
    protected function makeUser(Subscriber $subscriber, string $role = 'manager', array $attrs = []): User
    {
        return $this->tenant()->run($subscriber->id, fn () => User::factory()->create(array_merge(['subscriber_id' => $subscriber->id, 'role' => $role], $attrs)));
    }
}
