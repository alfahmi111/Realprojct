<?php

namespace Tests\Feature\Foundation;

use App\Approvals\ApprovalException;
use App\Approvals\DualApproval;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REQ-0069, REQ-0052, REQ-0579 — maker/checker for sensitive financial entries: nobody approves what they entered;
 * the checker has higher authority; single-user offices may disable it explicitly and the switch is audited.
 * REQ-0652 — provisional default of decision 10 (PENDING-D10).
 */
class DualApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): DualApproval
    {
        return app(DualApproval::class);
    }

    public function test_req_0069_entry_waits_for_a_different_higher_authority_checker(): void
    {
        $s = $this->makeSubscriber('office');
        $accountant = $this->makeUser($s, 'accountant');
        $manager = $this->makeUser($s, 'manager');
        $this->tenant()->set($s->id);

        $done = 0;
        $req = $this->svc()->submit($accountant, 'invoice.cancel', ['invoice' => 1], function () use (&$done) { $done++; });
        $this->assertSame('pending', $req->status);
        $this->assertSame(0, $done);

        $this->svc()->approve($req, $manager, function () use (&$done) { $done++; });
        $this->assertSame(1, $done);
        $this->assertSame('approved', $req->fresh()->status);
        $this->assertSame($manager->id, $req->fresh()->checker_id);
    }

    public function test_req_0069_nobody_approves_their_own_entry(): void
    {
        $s = $this->makeSubscriber('office');
        $manager = $this->makeUser($s, 'manager');
        $this->tenant()->set($s->id);
        $req = $this->svc()->submit($manager, 'deposit.refund', ['amount' => 100], fn () => null);

        $this->expectException(ApprovalException::class);
        $this->svc()->approve($req, $manager, fn () => null);
    }

    public function test_req_0069_the_database_also_forbids_self_approval(): void
    {
        $s = $this->makeSubscriber('office');
        $manager = $this->makeUser($s, 'manager');
        $this->tenant()->set($s->id);
        $req = $this->svc()->submit($manager, 'payment.edit', [], fn () => null);
        $this->expectException(QueryException::class);
        DB::table('approval_requests')->where('id', $req->id)->update(['checker_id' => $manager->id]);
    }

    public function test_req_0069_equal_or_lower_staff_authority_cannot_approve(): void
    {
        $s = $this->makeSubscriber('office');
        $a1 = $this->makeUser($s, 'accountant');
        $a2 = $this->makeUser($s, 'accountant');
        $tech = $this->makeUser($s, 'technician');
        $this->tenant()->set($s->id);
        $req = $this->svc()->submit($a1, 'invoice.discount', [], fn () => null);

        foreach ([$a2, $tech] as $checker) {
            try {
                $this->svc()->approve($req, $checker, fn () => null);
                $this->fail('approved by insufficient authority');
            } catch (ApprovalException) {
                $this->assertSame('pending', $req->fresh()->status);
            }
        }
    }

    public function test_req_0652_office_plan_cannot_disable_dual_approval(): void
    {
        $s = $this->makeSubscriber('office');
        $this->tenant()->set($s->id);
        $this->assertTrue($this->svc()->enabled());
        $this->expectException(ApprovalException::class);
        $this->svc()->setEnabled($s, false);
    }

    public function test_req_0069_single_user_owner_plan_disables_explicitly_and_it_is_audited(): void
    {
        $s = $this->makeSubscriber('owner');
        $solo = $this->makeUser($s, 'manager');
        $this->actingAs($solo);
        $this->tenant()->set($s->id);

        $this->svc()->setEnabled($s, false);
        $this->assertTrue(AuditLog::where('action', 'setting.changed')->where('auditable_id', 'security.dual_approval')->exists());

        $done = false;
        $req = $this->svc()->submit($solo, 'trust.disburse', ['amount' => 500], function () use (&$done) { $done = true; });
        $this->assertTrue($done);
        $this->assertSame('approved', $req->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'approval.auto_approved')->exists());
    }

    public function test_req_0069_approval_requests_are_never_physically_deleted(): void
    {
        $s = $this->makeSubscriber('office');
        $m = $this->makeUser($s, 'accountant');
        $this->tenant()->set($s->id);
        $req = $this->svc()->submit($m, 'invoice.cancel', [], fn () => null);
        $this->expectException(QueryException::class);
        ApprovalRequest::whereKey($req->id)->delete();
    }
}
