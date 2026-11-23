<?php

namespace App\Notifications;

use App\Finance\InvoiceService;
use App\Models\Invoice;
use App\Models\OwnershipShare;
use App\Models\Tenant;
use App\Models\User;
use App\Settings\Settings;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Payment reminders with escalation — the MVP part of spec §5.8 (REQ-0206, REQ-0207, REQ-0050, REQ-0584):
 *   1. a reminder before the due date (if the manager set the number of days) and on the due date (WhatsApp + portal);
 *   2. a second reminder 7 days after, escalated automatically to the manager — and to the owner when the overdue
 *      amount exceeds the threshold the owner set.
 * The formal warning after 30 days, the arrears file and the claim file are phase 2 (REQ-0208…REQ-0210).
 */
class PaymentReminders
{
    public function __construct(private NotificationCenter $center, private InvoiceService $invoices, private Settings $settings)
    {
    }

    public function run(CarbonImmutable $today): array
    {
        $cfg = $this->settings->get('leasing.payment_reminders') ?? [];
        $before = $cfg['before_due_days'] ?? null;
        $ownerThreshold = isset($cfg['owner_threshold_minor']) ? Money::of((int) $cfg['owner_threshold_minor']) : null;
        $sent = ['before' => 0, 'due' => 0, 'second' => 0];

        foreach (Invoice::where('status', 'issued')->where('kind', 'rent')->whereNotNull('period_start')->get() as $inv) {
            $outstanding = $this->invoices->outstanding($inv);
            if (! $outstanding->isPositive()) {
                continue;
            }
            $due = CarbonImmutable::parse($inv->period_start);
            $tenant = Tenant::find($inv->tenant_id);
            $recipients = array_values(array_filter([$tenant, $tenant->user_id ? User::find($tenant->user_id) : null]));
            $vars = ['tenant_name' => $tenant->name_ar, 'unit' => $inv->unit_code, 'amount' => $outstanding->toDecimalString(), 'due_date' => $due->toDateString()];

            if ($before !== null && $today->isSameDay($due->subDays($before)) && $this->once($inv, 'before')) {
                $this->center->dispatch('payment.due', $recipients, $vars + ['stage' => 'before'], $today, subject: $inv);
                $sent['before']++;
            }
            if ($today->isSameDay($due) && $this->once($inv, 'due')) {
                $this->center->dispatch('payment.due', $recipients, $vars + ['stage' => 'due'], $today, subject: $inv);
                $sent['due']++;
            }
            if ($today->isSameDay($due->addDays(7)) && $this->once($inv, 'second')) {
                $this->center->dispatch('payment.overdue', $recipients, $vars + ['stage' => 'second'], $today, subject: $inv);
                $managers = User::where('role', 'manager')->whereNull('archived_at')->get()->all();
                $this->center->dispatch('payment.overdue', $managers, $vars + ['stage' => 'escalated_to_manager'], $today, subject: $inv);
                if ($ownerThreshold && $outstanding->greaterThan($ownerThreshold)) {
                    $ownerUsers = OwnershipShare::where('property_id', $inv->property_id)->with('owner')->get()->map(fn ($s) => $s->owner->user_id ? User::find($s->owner->user_id) : null)->filter()->all();
                    $this->center->dispatch('payment.overdue', array_values($ownerUsers), $vars + ['stage' => 'escalated_to_owner'], $today, subject: $inv);
                }
                $sent['second']++;
            }
        }
        return $sent;
    }

    private function once(Invoice $inv, string $stage): bool
    {
        return ! DB::table('notifications_outbox')->where('subject_type', 'Invoice')->where('subject_id', (string) $inv->id)
            ->whereRaw("payload->>'stage' = ?", [$stage])->exists();
    }
}
