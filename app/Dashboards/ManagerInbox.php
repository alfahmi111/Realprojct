<?php

namespace App\Dashboards;

use App\Models\ApprovalRequest;
use App\Models\LeaseOfferApproval;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Manager mobile view inside the single app (spec §7.5, REQ-0314…REQ-0320): one task inbox with pending approvals
 * (offers, disbursements, maker/checker entries), new maintenance reports and escalated arrears. Recording a payment
 * (PaymentService), creating an offer / importing an Ejar contract (LeasingService, QuickMigration) and assigning work
 * orders (MaintenanceService) are the same services the desktop uses. Advanced accounting settings, budgets,
 * rescheduling and detailed reports stay on the large screen (REQ-0320) — see MOBILE_ACTIONS / DESKTOP_ONLY.
 */
class ManagerInbox
{
    public const MOBILE_ACTIONS = ['inbox', 'payment.record', 'receipt.issue', 'offer.create', 'ejar.import', 'work_order.follow', 'work_order.assign', 'alerts'];
    public const DESKTOP_ONLY = ['accounting.advanced_settings', 'budgets', 'schedule.reschedule', 'reports.detailed'];

    public function for(User $user): array
    {
        $offerSteps = LeaseOfferApproval::whereNull('decision')->where('approver_role', $user->role->value)
            ->whereHas('offer', fn ($q) => $q->where('status', 'pending_approval'))->get()
            ->filter(fn ($s) => LeaseOfferApproval::where('lease_offer_id', $s->lease_offer_id)->whereNull('decision')->orderBy('step')->value('id') === $s->id);   // only the current step
        $entries = $user->hasPermission('approvals.decide')
            ? ApprovalRequest::where('status', 'pending')->where('maker_id', '<>', $user->id)->orderBy('created_at')->get()
            : collect();
        $newReports = WorkOrder::whereIn('status', ['reported', 'awaiting_owner_approval'])->orderByDesc('reported_at')->get();
        $escalated = DB::table('notifications_outbox')->where('recipient_type', 'user')->where('recipient_id', $user->id)
            ->where('event', 'payment.overdue')->where('channel', 'in_app')->whereNull('read_at')->orderByDesc('id')->get();

        return [
            'approvals' => [
                'offers' => $offerSteps->map(fn ($s) => ['offer_id' => $s->lease_offer_id, 'step' => $s->step])->values()->all(),
                'entries' => $entries->map(fn ($r) => ['id' => $r->id, 'action' => $r->action, 'maker_id' => $r->maker_id])->all(),
            ],
            'new_reports' => $newReports->map(fn ($w) => ['id' => $w->id, 'number' => $w->number, 'type' => $w->type, 'priority' => $w->priority, 'status' => $w->status])->all(),
            'escalated_arrears' => $escalated->map(fn ($n) => ['notification_id' => $n->id, 'body' => $n->body])->all(),
            'counts' => ['approvals' => $offerSteps->count() + $entries->count(), 'reports' => $newReports->count(), 'arrears' => $escalated->count()],
        ];
    }
}
