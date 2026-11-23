<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use App\Models\UserScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REQ-0169, REQ-0487 — every subscriber is isolated at the database level by PostgreSQL Row-Level Security,
 * so even a wrong query written by a programmer cannot read or write another subscriber's rows.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_req_0169_a_subscriber_cannot_see_another_subscribers_rows_even_with_a_raw_query(): void
    {
        $a = $this->makeSubscriber('office', 'أ');
        $b = $this->makeSubscriber('office', 'ب');
        $ua = $this->makeUser($a);
        $ub = $this->makeUser($b);
        $this->tenant()->run($a->id, fn () => UserScope::create(['user_id' => $ua->id, 'scope_type' => 'property', 'scope_id' => 1]));
        $this->tenant()->run($b->id, fn () => UserScope::create(['user_id' => $ub->id, 'scope_type' => 'property', 'scope_id' => 2]));

        $this->tenant()->set($a->id);
        // "Forgotten WHERE" — a raw query over the whole table still returns only subscriber A's rows.
        $this->assertSame([$ua->id], DB::table('users')->pluck('id')->all());
        $this->assertSame([$ua->id], array_column(DB::select('select user_id from user_scopes'), 'user_id'));
        // Even an explicit filter for B's rows returns nothing.
        $this->assertSame(0, DB::table('users')->where('subscriber_id', $b->id)->count());
        $this->assertNull(User::find($ub->id));
    }

    public function test_req_0487_without_a_subscriber_context_no_tenant_rows_are_visible(): void
    {
        $a = $this->makeSubscriber();
        $ua = $this->makeUser($a);
        $this->tenant()->run($a->id, fn () => UserScope::create(['user_id' => $ua->id, 'scope_type' => 'property', 'scope_id' => 1]));

        $this->tenant()->clear();
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('user_scopes')->count());
    }

    public function test_req_0487_writing_a_row_for_another_subscriber_is_rejected_by_the_database(): void
    {
        $a = $this->makeSubscriber();
        $b = $this->makeSubscriber();
        $ua = $this->makeUser($a);

        $this->tenant()->set($a->id);
        $this->expectException(QueryException::class);
        DB::table('user_scopes')->insert(['subscriber_id' => $b->id, 'user_id' => $ua->id, 'scope_type' => 'property', 'scope_id' => 9]);
    }

    public function test_req_0487_updating_or_deleting_another_subscribers_rows_affects_nothing(): void
    {
        $a = $this->makeSubscriber();
        $b = $this->makeSubscriber();
        $this->makeUser($a);
        $ub = $this->makeUser($b, 'manager', ['name' => 'B-user']);

        $this->tenant()->set($a->id);
        $this->assertSame(0, DB::table('users')->where('id', $ub->id)->update(['name' => 'hacked']));
        $this->assertSame(0, DB::table('users')->whereRaw('true')->where('id', $ub->id)->delete());

        $this->tenant()->set($b->id);
        $this->assertSame('B-user', DB::table('users')->where('id', $ub->id)->value('name'));
    }

    public function test_req_0169_rls_is_forced_even_for_the_table_owner(): void
    {
        foreach (['users', 'user_scopes', 'subscriber_settings', 'user_settings', 'property_settings', 'audit_logs', 'approval_requests'] as $t) {
            $row = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = current_schema()::regnamespace', [$t]);
            $this->assertTrue($row->relrowsecurity && $row->relforcerowsecurity, "RLS not forced on $t");
        }
        $this->assertFalse((bool) DB::selectOne('select rolbypassrls from pg_roles where rolname = current_user')->rolbypassrls);
    }

    public function test_req_0487_login_resolves_the_user_and_enters_its_subscriber_context(): void
    {
        $a = $this->makeSubscriber();
        $b = $this->makeSubscriber();
        $ua = $this->makeUser($a, 'manager', ['email' => 'a@example.test', 'password' => 'secret-pass']);
        $this->makeUser($b);

        $this->tenant()->clear();
        $this->assertTrue(Auth::attempt(['email' => 'a@example.test', 'password' => 'secret-pass']));
        $this->assertSame($ua->id, Auth::id());
        $this->assertSame($a->id, $this->tenant()->subscriberId());
        $this->assertSame(1, DB::table('users')->count());   // only subscriber A's users
    }
}
