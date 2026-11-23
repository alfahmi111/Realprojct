<?php

namespace Tests\Feature\Foundation;

use App\Models\AuditLog;
use App\Models\UserScope;
use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * REQ-0068, REQ-0497 — audit log that cannot be deleted or edited: who, what, when, value before and after.
 * REQ-0168 — no physical delete for archive-only tables.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_req_0068_changes_are_logged_with_who_what_when_before_and_after(): void
    {
        $s = $this->makeSubscriber();
        $u = $this->makeUser($s);
        $this->actingAs($u);
        $this->tenant()->set($s->id);

        $scope = UserScope::create(['user_id' => $u->id, 'scope_type' => 'property', 'scope_id' => 1]);
        $scope->update(['scope_id' => 2]);

        $log = AuditLog::where('auditable_type', $scope->getMorphClass())->where('action', 'updated')->firstOrFail();
        $this->assertSame($u->id, $log->user_id);
        $this->assertSame(['scope_id' => 1], $log->before);
        $this->assertSame(['scope_id' => 2], $log->after);
        $this->assertNotNull($log->created_at);
        $this->assertSame($s->id, $log->subscriber_id);
    }

    public function test_req_0497_audit_rows_cannot_be_updated_or_deleted_even_with_raw_sql(): void
    {
        $s = $this->makeSubscriber();
        $this->makeUser($s);
        $this->tenant()->set($s->id);
        $id = DB::table('audit_logs')->value('id');
        $this->assertNotNull($id);

        foreach (["update audit_logs set action = 'x' where id = $id", "delete from audit_logs where id = $id", 'truncate audit_logs cascade'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("Statement should have been rejected: $sql");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
    }

    public function test_req_0068_the_model_refuses_to_edit_audit_rows(): void
    {
        $s = $this->makeSubscriber();
        $this->makeUser($s);
        $this->tenant()->set($s->id);
        $log = AuditLog::firstOrFail();
        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_req_0068_secrets_are_never_written_to_the_audit_log(): void
    {
        $s = $this->makeSubscriber();
        $u = $this->makeUser($s);
        $this->tenant()->set($s->id);
        $log = AuditLog::where('auditable_id', (string) $u->id)->where('action', 'created')->firstOrFail();
        $this->assertArrayNotHasKey('password', $log->after);
        $this->assertArrayNotHasKey('remember_token', $log->after);
    }

    public function test_req_0168_archive_only_tables_reject_physical_delete(): void
    {
        Schema::create('archive_probe', fn ($t) => $t->id());
        Rls::archiveOnly('archive_probe');
        DB::table('archive_probe')->insert(['id' => 1]);
        $this->expectException(QueryException::class);
        DB::table('archive_probe')->delete();
    }
}
