<?php

namespace App\Approvals;

use App\Audit\AuditLogger;
use App\Models\ApprovalRequest;
use App\Models\Subscriber;
use App\Models\User;
use App\Settings\Settings;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Maker/checker for sensitive financial entries (REQ-0069, REQ-0052, REQ-0579).
 *
 *  - Entered by one user, approved by ANOTHER user with higher authority. Nobody approves their own entry
 *    (also a database CHECK constraint).
 *  - Can be switched off only explicitly (single-user offices); switching it off is written to the audit log.
 *    Whether a plan may switch it off follows the provisional value of decision 10 (PENDING-D10):
 *    mandatory from the "office" plan up, optional in the "owner" plan.
 */
class DualApproval
{
    public function __construct(private Settings $settings, private TenantContext $tenant)
    {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('security.dual_approval');
    }

    /** PENDING-D10: only the "owner" plan may disable dual approval. */
    public function canDisable(Subscriber $subscriber): bool
    {
        return $subscriber->plan === 'owner';
    }

    public function setEnabled(Subscriber $subscriber, bool $enabled): void
    {
        if (! $enabled && ! $this->canDisable($subscriber)) {
            throw new ApprovalException('Dual approval is mandatory for this plan (PENDING-D10).');
        }
        $this->settings->setSubscriber('security.dual_approval', $enabled);   // audited by Settings (REQ-0376)
    }

    /**
     * Submit a sensitive entry. When dual approval is enabled it waits for a checker; when disabled it is
     * executed immediately and still recorded.
     */
    /** One pending request per subject: two approvals of the same reversal would reverse it twice (review M18). */
    public function assertNonePending(string $subjectType, string $subjectId): void
    {
        if (ApprovalRequest::where('status', 'pending')->where('subject_type', $subjectType)->where('subject_id', $subjectId)->exists()) {
            throw new ApprovalException('يوجد طلب معلّق على هذا السجل ينتظر المراجِع.');
        }
    }

    public function submit(User $maker, string $action, array $payload, callable $execute, ?string $subjectType = null, ?string $subjectId = null): ApprovalRequest
    {
        if (! in_array($action, ApprovalRequest::ACTIONS, true)) {
            throw new ApprovalException("Unknown sensitive action [$action].");
        }
        return DB::transaction(function () use ($maker, $action, $payload, $execute, $subjectType, $subjectId) {
            $req = new ApprovalRequest();
            $req->forceFill([
                'status' => 'pending', 'action' => $action, 'payload' => $payload, 'maker_id' => $maker->id,
                'subject_type' => $subjectType, 'subject_id' => $subjectId,
            ])->save();
            if (! $this->enabled()) {
                $req->forceFill(['status' => 'approved', 'decided_at' => now(), 'reason' => 'dual approval disabled by subscriber'])->save();
                AuditLogger::record('approval.auto_approved', $req, null, ['action' => $action, 'maker_id' => $maker->id]);
                $execute($req);
            } else {
                // tell the managers who can decide it (the event existed but nothing sent it — review M18 L11)
                $checkers = \App\Models\User::where('role', 'manager')->whereNull('archived_at')->where('id', '!=', $maker->id)->get()->all();
                if ($checkers) {
                    app(\App\Notifications\NotificationCenter::class)->dispatch('approval.waiting', $checkers, ['الإجراء' => \App\Filament\Manager\Resources\ApprovalRequests\ApprovalRequestResource::ACTIONS_AR[$action] ?? $action, 'مقدّم الطلب' => (string) $maker->name], \Carbon\CarbonImmutable::now(), subject: $req);
                }
            }
            return $req;
        });
    }

    public function approve(ApprovalRequest $req, User $checker, callable $execute): ApprovalRequest
    {
        $this->assertCanDecide($req, $checker);
        return DB::transaction(function () use ($req, $checker, $execute) {
            // M22: two checkers approving at once (or a stale screen) must not execute it twice — lock the row and
            // re-read its status inside the transaction that executes it
            $current = ApprovalRequest::whereKey($req->getKey())->lockForUpdate()->first();
            if (! $current || $current->status !== 'pending') {
                throw new ApprovalException('هذا الطلب حُسم من قبل؛ حدّث الصفحة.');
            }
            $req->forceFill(['status' => 'approved', 'checker_id' => $checker->id, 'decided_at' => now()])->save();
            $execute($req);
            return $req;
        });
    }

    public function reject(ApprovalRequest $req, User $checker, string $reason): ApprovalRequest
    {
        $this->assertCanDecide($req, $checker);
        return DB::transaction(function () use ($req, $checker, $reason) {
            // a stale screen must not turn an executed request into «rejected» (M22)
            $current = ApprovalRequest::whereKey($req->getKey())->lockForUpdate()->first();
            if (! $current || $current->status !== 'pending') {
                throw new ApprovalException('هذا الطلب حُسم من قبل؛ حدّث الصفحة.');
            }
            $req->forceFill(['status' => 'rejected', 'checker_id' => $checker->id, 'decided_at' => now(), 'reason' => $reason])->save();
            return $req;
        });
    }

    private function assertCanDecide(ApprovalRequest $req, User $checker): void
    {
        if ($req->status !== 'pending') {
            throw new ApprovalException('This request has already been decided.');
        }
        if ($checker->id === $req->maker_id) {
            throw new ApprovalException('Nobody approves an entry they made themselves.');
        }
        if ($checker->subscriber_id !== $req->subscriber_id || ! $checker->hasPermission('approvals.decide')) {
            throw new ApprovalException('This user cannot decide approvals.');
        }
        $maker = $req->maker;
        if ($checker->role->authority() < $maker->role->authority()
            || ($checker->role->authority() === $maker->role->authority() && $maker->role->authority() < 3)) {
            throw new ApprovalException('The checker must have higher authority than the maker.');
        }
    }
}
