<?php

namespace App\Portals;

use App\Finance\InvoiceService;
use App\Integrations\Payments\PaymentGateway;
use App\Leasing\LeasingService;
use App\Maintenance\MaintenanceService;
use App\Models\Announcement;
use App\Models\Asset;
use App\Models\InventoryLine;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\SubscriberIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;

/**
 * Tenant portal (spec §7.1, MVP items; REQ-0582): my unit with its registered assets and condition at handover,
 * my lease (copy, Ejar number, end date, notice deadline), my invoices (due, paid, e-invoices, pay), maintenance
 * (open with asset + description + photos, follow, rate), management announcements, and guidance to transfer the
 * meters through Ejar. Renewal intent (P2), voice reports (P3) and extra services (P3) are not here.
 */
class TenantPortal
{
    public function __construct(private LeasingService $leasing, private InvoiceService $invoices, private MaintenanceService $maintenance, private PaymentGateway $payments)
    {
    }

    public function tenantOf(User $user): Tenant
    {
        return Tenant::where('user_id', $user->id)->firstOrFail();
    }

    private function currentLease(Tenant $t): ?Lease
    {
        return Lease::where('tenant_id', $t->id)->whereIn('status', ['registered', 'active', 'notice_given'])->orderByDesc('starts_on')->with('unit.spaces')->first();
    }

    /** وحدتي — unit data and registered assets with their condition at the founding/handover count (REQ-0272). */
    public function myUnit(User $user): ?array
    {
        $lease = $this->currentLease($this->tenantOf($user));
        if (! $lease) {
            return null;
        }
        $spaceIds = $lease->unit->spaces->pluck('id');
        $assets = Asset::whereIn('space_id', $spaceIds)->whereNull('archived_at')->get()->map(function ($a) {
            $atHandover = InventoryLine::where('asset_id', $a->id)->whereHas('count', fn ($q) => $q->whereIn('type', ['founding', 'entry'])->where('status', 'submitted'))->orderByDesc('id')->value('condition');
            return ['id' => $a->id, 'name' => $a->name_ar, 'category' => $a->category, 'condition_at_handover' => $atHandover, 'condition' => $a->condition];
        })->all();
        return ['unit' => $lease->unit->code, 'type' => $lease->unit->unit_type, 'attributes' => $lease->unit->spaces->first()->attributes, 'assets' => $assets];
    }

    /** عقدي — Ejar number, end date and the notice deadline (REQ-0273). */
    public function myLease(User $user): ?array
    {
        $lease = $this->currentLease($this->tenantOf($user));
        return $lease ? ['lease_id' => $lease->id, 'ejar_contract_number' => $lease->ejar_contract_number, 'starts_on' => $lease->starts_on->toDateString(), 'ends_on' => $lease->ends_on->toDateString(), 'notice_deadline' => $this->leasing->noticeDeadline($lease)->toDateString()] : null;
    }

    /** فواتيري — due, paid, e-invoice reference (REQ-0274). */
    public function myInvoices(User $user): array
    {
        return Invoice::where('tenant_id', $this->tenantOf($user)->id)->whereIn('status', ['issued'])->orderBy('period_start')->get()->map(fn ($i) => [
            'id' => $i->id, 'number' => $i->number, 'total' => $i->total_minor, 'outstanding' => $this->invoices->outstanding($i),
            'einvoice_uuid' => $i->einvoice_uuid, 'einvoice_status' => $i->einvoice_status, 'period' => [$i->period_start?->toDateString(), $i->period_end?->toDateString()], 'sadad' => $i->sadad_bill_number,
        ])->all();
    }

    /** Pay online — mada / Apple Pay / cards through the gateway on the manager's or owner's merchant account (REQ-0274, REQ-0436). */
    public function payLink(User $user, Invoice $invoice): array
    {
        abort_unless($invoice->tenant_id === $this->tenantOf($user)->id, 403);
        $merchant = SubscriberIntegration::where('integration', 'payments')->where('active', true)->first()?->credentials ?? [];
        return $this->payments->createPaymentLink($merchant, $this->invoices->outstanding($invoice)->minor(), 'SAR', $invoice->number);
    }

    /** الصيانة — open a report choosing the asset, with description and photos/video (REQ-0275). */
    public function openReport(User $user, ?Asset $asset, string $description, array $photos, bool $emergency = false): WorkOrder
    {
        $tenant = $this->tenantOf($user);
        $lease = $this->currentLease($tenant) ?? abort(403);
        if ($asset && ! $lease->unit->spaces->pluck('id')->contains($asset->space_id)) {
            abort(403);   // only assets of the tenant's own unit
        }
        return $this->maintenance->report($emergency ? 'emergency' : 'corrective', $lease->unit->property_id, $description, 'tenant', $user, $asset, $lease->unit->spaces->first()->id, $photos, $lease);
    }

    public function myReports(User $user): array
    {
        $lease = $this->currentLease($this->tenantOf($user));
        return $lease ? WorkOrder::where('lease_id', $lease->id)->orderByDesc('reported_at')->get(['id', 'number', 'status', 'priority', 'reported_at'])->toArray() : [];
    }

    public function rate(User $user, WorkOrder $wo, int $rating, ?string $feedback = null): void
    {
        abort_unless($wo->lease_id && Lease::find($wo->lease_id)->tenant_id === $this->tenantOf($user)->id, 403);
        $this->maintenance->confirmByTenant($wo, $rating, $feedback);
    }

    /** الإعلانات — notices from the management (REQ-0278). */
    public function announcements(User $user): array
    {
        $lease = $this->currentLease($this->tenantOf($user));
        $building = $lease?->unit->spaces->first()->building_id;
        return Announcement::visibleTo($building ? (int) $building : null, $lease?->leasable_unit_id ? (int) $lease->leasable_unit_id : null)
            ->orderByDesc('published_at')->get(['id', 'title_ar', 'body_ar', 'published_at', 'expires_at', 'attachments'])->toArray();
    }

    /** نقل العدادات — guidance to the meter-transfer service Ejar provides at registration (REQ-0280). */
    public function meterTransferGuidance(): array
    {
        return ['text_ar' => 'يمكنك نقل عدادات الكهرباء والمياه باسمك عبر الخدمة التي توفرها منصة إيجار عند توثيق العقد.', 'source' => 'https://sabq.org/article/HD6CB6V'];
    }
}
