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
