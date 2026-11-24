<?php

namespace App\Maintenance;

use App\Finance\InvoiceService;
use App\Finance\Numbering;
use App\Finance\TrustAccounting;
use App\Models\Asset;
use App\Models\CostLine;
use App\Models\Lease;
use App\Models\ManagementAgreement;
use App\Models\OwnershipShare;
use App\Models\PpmPlan;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warranty;
use App\Models\WorkOrder;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Work orders (spec §6.2–§6.4):
 *   report (photos) → triage & priority → cost estimate → [above the manager's cap: owner approval]
 *   → assign (internal technician / vendor) → in progress → done (before/after photos)
 *   → tenant confirmation & rating → close → cost turned into an accounting entry           (REQ-0227…REQ-0230)
 * Warranty: a report on an asset with a valid warranty goes to the warranty vendor (REQ-0220).
 */
class MaintenanceService
{
    public const TYPES = ['corrective', 'preventive', 'statutory', 'unit_prep', 'emergency'];
    public const PRIORITIES = ['emergency', 'urgent', 'normal', 'scheduled'];

    public function __construct(private Numbering $numbering, private TrustAccounting $trust, private InvoiceService $invoices)
    {
    }

    public function report(string $type, int $propertyId, string $description, string $reporterType, ?User $reporter = null, ?Asset $asset = null, ?int $spaceId = null, array $photos = [], ?Lease $lease = null): WorkOrder
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new MaintenanceException("Unknown maintenance type [$type].");
        }
        if ($type === 'statutory') {
            throw new MaintenanceException('Statutory work orders are created from the document vault (phase 2).');
        }
        $now = CarbonImmutable::now();
        $wo = WorkOrder::create([
            'number' => $this->numbering->next('work_order', (int) $now->format('Y')), 'type' => $type,
            'priority' => $type === 'emergency' ? 'emergency' : ($type === 'preventive' ? 'scheduled' : null),
            'property_id' => $propertyId, 'space_id' => $spaceId ?? $asset?->space_id, 'asset_id' => $asset?->id, 'lease_id' => $lease?->id,
            'reported_by_type' => $reporterType, 'reported_by_user_id' => $reporter?->id, 'description' => $description,
            'photos_report' => $photos, 'reported_at' => $now,
        ]);
        $this->announce($wo, $now);
        return $wo;
    }

    /**
     * Tell the maintenance supervisors about a new report (the events existed but nothing sent them — review M18 L11).
     * Unhandled, it escalates once to the managers: an emergency after an hour, any other report after a day.
     * An office without a supervisor tells its managers directly. Preventive orders made by the daily job stay quiet.
     */
    private function announce(WorkOrder $wo, CarbonImmutable $now): void
    {
        if ($wo->type === 'preventive') {
            return;
        }
        $emergency = $wo->type === 'emergency';
        $supervisors = User::where('role', 'maintenance_supervisor')->whereNull('archived_at')->get()->all();
        $targets = $supervisors ?: User::where('role', 'manager')->whereNull('archived_at')->get()->all();
        if (! $targets) {
            return;
        }
        app(\App\Notifications\NotificationCenter::class)->dispatch($emergency ? 'maintenance.emergency' : 'maintenance.new_report', $targets,
            ['رقم الأمر' => (string) $wo->number, 'الوصف' => mb_substr((string) $wo->description, 0, 120)], $now, urgent: $emergency,
            escalate: $supervisors ? ['after_hours' => $emergency ? 1 : 24, 'to_role' => 'manager'] : null, subject: $wo);
    }

    /**
     * Statutory work order from the document vault (REQ-0270, 6.2): a certificate (civil defence, lift inspection,
     * insurance) is about to expire. Reported by the system, scheduled priority. The only way to create this type.
     */
    public function reportStatutory(\App\Models\Document $doc): WorkOrder
    {
        if ($doc->property_id === null) {
            throw new MaintenanceException('A statutory work order needs the document\'s property.');
        }
        $now = CarbonImmutable::now();
        return WorkOrder::create([
            'number' => $this->numbering->next('work_order', (int) $now->format('Y')), 'type' => 'statutory', 'priority' => 'scheduled',
            'property_id' => $doc->property_id, 'reported_by_type' => 'system',
            'description' => 'تجديد ' . $doc->title_ar . ' قبل انتهائه في ' . $doc->expires_on?->format('Y-m-d'),
            'photos_report' => [], 'reported_at' => $now,
        ]);
    }

    public function triage(WorkOrder $wo, string $priority): WorkOrder
    {
        $this->expect($wo, ['reported']);
        if (! in_array($priority, self::PRIORITIES, true)) {
            throw new MaintenanceException("Unknown priority [$priority].");
        }
        $wo->forceFill(['priority' => $priority, 'status' => 'triaged', 'responded_at' => now()])->save();
        return $wo;
    }

    /** Above the agreement's spend cap the owner must approve (REQ-0228, REQ-0066, REQ-0307). */
    public function estimate(WorkOrder $wo, Money $cost): WorkOrder
    {
        $this->expect($wo, ['triaged']);
        $cap = $this->spendCap($wo->property_id);
        $needsOwner = $cap !== null && $cost->greaterThan($cap);
        $wo->forceFill([
            'estimated_cost_minor' => $cost, 'owner_approval_required' => $needsOwner,
            'owner_approval' => $needsOwner ? 'pending' : null, 'status' => $needsOwner ? 'awaiting_owner_approval' : 'estimated',
        ])->save();
        return $wo;
    }

    public function ownerDecision(WorkOrder $wo, User $owner, bool $approve): WorkOrder
    {
        $this->expect($wo, ['awaiting_owner_approval']);
        if (! $owner->hasPermission('owner.approve_above_cap')) {
            throw new MaintenanceException('Only the owner approves spending above the cap.');
        }
        $wo->forceFill(['owner_approval' => $approve ? 'approved' : 'rejected', 'status' => $approve ? 'estimated' : 'rejected'])->save();
        return $wo;
    }

    /** Assign to an internal technician or a vendor; a valid warranty routes it to the warranty vendor (REQ-0220). */
    public function assign(WorkOrder $wo, ?User $technician = null, ?Vendor $vendor = null, ?CarbonImmutable $on = null): WorkOrder
    {
        $this->expect($wo, ['estimated']);
        $warranty = $wo->asset_id ? $this->activeWarranty(Asset::find($wo->asset_id), $on ?? \App\Support\Dates\Today::date()) : null;
        if ($warranty) {
            $wo->forceFill(['assignee_type' => 'vendor', 'vendor_id' => $warranty->vendor_id, 'warranty_id' => $warranty->id, 'assignee_user_id' => null, 'status' => 'assigned'])->save();
            return $wo;
        }
        if (($technician === null) === ($vendor === null)) {
            throw new MaintenanceException('Assign to either an internal technician or a vendor.');
        }
        if ($technician && $technician->role->value !== 'technician') {
            throw new MaintenanceException('Internal assignments go to technicians.');
        }
        $wo->forceFill(['assignee_type' => $technician ? 'internal' : 'vendor', 'assignee_user_id' => $technician?->id, 'vendor_id' => $vendor?->id, 'status' => 'assigned'])->save();
        return $wo;
    }

    public function start(WorkOrder $wo, array $photosBefore): WorkOrder
    {
        $this->expect($wo, ['assigned']);
        $wo->forceFill(['status' => 'in_progress', 'photos_before' => $photosBefore])->save();
        return $wo;
    }

    public function addCost(WorkOrder $wo, string $kind, string $descriptionAr, int $quantity, Money $unitCost): CostLine
    {
        if (! in_array($kind, ['labour', 'part', 'vendor'], true) || $quantity < 1) {
            throw new MaintenanceException('Invalid cost line.');
        }
        $this->expect($wo, ['assigned', 'in_progress', 'completed']);
        return CostLine::create(['work_order_id' => $wo->id, 'kind' => $kind, 'description_ar' => $descriptionAr, 'quantity' => $quantity, 'unit_cost_minor' => $unitCost, 'total_minor' => $unitCost->times($quantity)]);
    }

    public function complete(WorkOrder $wo, array $photosAfter): WorkOrder
    {
        $this->expect($wo, ['in_progress']);
        if (! $photosAfter) {
            throw new MaintenanceException('Completion needs after photos.');
        }
        $wo->forceFill(['status' => 'completed', 'photos_after' => $photosAfter, 'completed_at' => now()])->save();
        return $wo;
    }

    public function confirmByTenant(WorkOrder $wo, int $rating, ?string $feedback = null): WorkOrder
    {
        $this->expect($wo, ['completed']);
        if ($rating < 1 || $rating > 5) {
            throw new MaintenanceException('Rating is 1 to 5.');
        }
        $wo->forceFill(['status' => 'confirmed', 'tenant_rating' => $rating, 'tenant_feedback' => $feedback])->save();
        return $wo;
    }

    /**
     * Close and turn the cost into an accounting entry (REQ-0230): paid from the owner's trust under maker/checker.
     * Warranty work costs the owner nothing. Tenant-caused damage becomes a recharge invoice with documented approval (REQ-0232).
     */
    public function close(WorkOrder $wo, User $by, ?string $tenantCausedApprovalDoc = null): WorkOrder
    {
        $this->expect($wo, ['confirmed', 'completed']);
        $total = $this->totalCost($wo);
        // the actual cost may not exceed what the owner approved, or the ceiling when no approval was needed
        // (review M18: only the estimate was checked, so a 400 estimate could close at 5,000 paid from trust)
        if (! $wo->warranty_id && $total->isPositive()) {
            $ceiling = $wo->owner_approval === 'approved' ? $wo->estimated_cost_minor : $this->spendCap($wo->property_id);
            if ($ceiling !== null && $total->greaterThan($ceiling)) {
                throw new MaintenanceException('التكلفة الفعلية ' . $total->toDecimalString() . ' تتجاوز ما وافق عليه المالك أو سقف الصرف (' . $ceiling->toDecimalString() . '). اطلب موافقة المالك على المبلغ قبل الإغلاق.');
            }
        }
        DB::transaction(function () use ($wo, $by, $total, $tenantCausedApprovalDoc) {
            $wo->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
            if ($wo->warranty_id || $total->isZero()) {
                return;
            }
            $owner = OwnershipShare::where('property_id', $wo->property_id)->first()?->owner;
            $this->trust->requestExpense($by, $owner, $wo->property_id, $total, 'أمر عمل ' . $wo->number . ': ' . mb_substr($wo->description, 0, 80), $wo->number, \App\Support\Dates\Today::date());
            if ($tenantCausedApprovalDoc !== null) {
                $lease = Lease::findOrFail($wo->lease_id ?? throw new MaintenanceException('A recharge needs the lease.'));
                $wo->forceFill(['tenant_caused' => true, 'tenant_caused_approval' => $tenantCausedApprovalDoc])->save();
                $this->invoices->issueRecharge($lease, 'تكلفة صيانة بسبب سوء الاستخدام — ' . $wo->number, $total, \App\Support\Dates\Today::date());
            }
        });
        return $wo;
    }

    public function totalCost(WorkOrder $wo): Money
    {
        return Money::sum($wo->costLines->map(fn ($c) => $c->total_minor)->all());
    }

    /** Full history of an asset with cumulative cost — reveals what to replace rather than repair (REQ-0233). */
    public function assetHistory(Asset $asset): array
    {
        $orders = WorkOrder::where('asset_id', $asset->id)->with('costLines')->orderBy('reported_at')->get();
        $cumulative = Money::sum($orders->map(fn ($w) => $this->totalCost($w))->all());
        return [
            'orders' => $orders->map(fn ($w) => ['number' => $w->number, 'type' => $w->type, 'status' => $w->status, 'cost' => $this->totalCost($w)])->all(),
            'cumulative_cost' => $cumulative,
            // Ratio of repairs to the asset's cost in basis points; the UI ranks assets by it (no invented threshold).
            'repair_to_cost_bp' => $asset->cost_minor && $asset->cost_minor->isPositive() ? Money::divide($cumulative->minor() * 10000, $asset->cost_minor->minor(), \App\Support\Money\Rounding::Down) : null,
        ];
    }

    public function activeWarranty(Asset $asset, CarbonImmutable $on): ?Warranty
    {
        return Warranty::whereNull('archived_at')->where('asset_id', $asset->id)
            ->where('starts_on', '<=', $on->toDateString())->where('ends_on', '>=', $on->toDateString())->orderBy('ends_on')->first();
    }

    /** Warranties and annual contracts ending within 30 days — alert list (REQ-0220). */
    public function expiringWarranties(CarbonImmutable $today): Collection
    {
        return Warranty::whereNull('archived_at')->whereBetween('ends_on', [$today->toDateString(), $today->addDays(30)->toDateString()])->orderBy('ends_on')->get();
    }

    /** Create the preventive work orders that are due and move each plan forward (REQ-0223, REQ-0577). */
    public function generatePreventive(CarbonImmutable $today): int
    {
        $n = 0;
        foreach (PpmPlan::whereNull('archived_at')->where('next_due_on', '<=', $today->toDateString())->get() as $plan) {
            DB::transaction(function () use ($plan, &$n, $today) {
                $propertyId = $plan->asset_id ? Asset::find($plan->asset_id)->space()->value('property_id') : \App\Models\Space::find($plan->space_id)->property_id;
                $wo = $this->report('preventive', $propertyId, $plan->title_ar, 'system', null, $plan->asset_id ? Asset::find($plan->asset_id) : null, $plan->space_id);
                $wo->forceFill(['ppm_plan_id' => $plan->id])->save();
                // one work order per run even when overdue, and the next due date jumps past today in one step
                // (review M18: a plan months behind produced one work order per day while catching up)
                $next = CarbonImmutable::parse($plan->next_due_on);
                do {
                    $next = $next->addMonthsNoOverflow($plan->every_months);
                } while ($next->lessThanOrEqualTo($today));
                $plan->forceFill(['next_due_on' => $next->toDateString()])->save();
                $n++;
            });
        }
        return $n;
    }

    /** Unit preparation after a tenant leaves: paint, clean, inspect (REQ-0225). */
    public function unitPreparation(Lease $lease): WorkOrder
    {
        $space = $lease->unit->spaces->first();
        return $this->report('unit_prep', $lease->unit->property_id, 'تجهيز الوحدة ' . $lease->unit->code . ' بعد خروج المستأجر: دهان وتنظيف وفحص', 'system', null, null, $space?->id, [], $lease);
    }

    /**
     * The approval ceiling for a property (review M18): only agreements in force today count, and on a jointly owned
     * property the strictest owner's rule applies — one co-owner cannot waive another's approval. null = no approval.
     */
    public function spendCap(int $propertyId): ?Money
    {
        $today = \App\Support\Dates\Today::date()->toDateString();
        $caps = ManagementAgreement::whereNull('archived_at')->whereHas('properties', fn ($q) => $q->where('properties.id', $propertyId))
            ->where('starts_on', '<=', $today)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->get()->map(fn ($a) => $a->spend_cap_minor)->filter(fn ($c) => $c !== null);
        return $caps->isEmpty() ? null : $caps->sortBy(fn (Money $c) => $c->minor())->first();
    }

    private function expect(WorkOrder $wo, array $statuses): void
    {
        if (! in_array($wo->status, $statuses, true)) {
            throw new MaintenanceException("Work order is {$wo->status}.");
        }
    }
}
