<?php

namespace App\Dashboards;

use App\Authorization\Scope;
use App\Finance\InvoiceService;
use App\Leasing\LeasingService;
use App\Maintenance\MaintenanceService;
use App\Models\ApprovalRequest;
use App\Models\Building;
use App\Models\Invoice;
use App\Models\LeasableUnit;
use App\Models\Lease;
use App\Models\Property;
use App\Models\ReconciliationIssue;
use App\Models\Space;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Dates\DateService;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Data of the manager home page: greeting and date, portfolio vitals, the building facade (each unit's status today in
 * the five fixed status colours, REQ-0330), what needs attention, permitted quick actions and contracts nearing their
 * notice deadline. Everything is limited to the user's property scope (REQ-0494) on top of RLS (REQ-0169).
 */
class HomeOverview
{
    public function __construct(private Kpis $kpis, private ManagerInbox $inbox, private LeasingService $leasing, private InvoiceService $invoices, private DateService $dates, private MaintenanceService $maintenance)
    {
    }

    public function propertyIds(User $user): array
    {
        $scoped = Scope::propertyIds($user);
        return $scoped ?? Property::whereNull('archived_at')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public function header(User $user, CarbonImmutable $now, string $tz = 'Asia/Riyadh'): array
    {
        $local = $now->setTimezone($tz);
        return [
            'greeting' => $local->hour < 12 ? 'صباح الخير' : 'مساء الخير',
            'name' => explode(' ', trim($user->name))[0],
            'role' => $user->role->labelAr(),
            'day' => \IntlDateFormatter::formatObject($local, 'EEEE', 'ar_SA'),
            'hijri' => $this->dates->format($now, 'hijri', $tz),
            'gregorian' => $this->dates->format($now, 'gregorian', $tz),
        ];
    }

    /** Four vitals: occupancy, collection this month, overdue receivables, open work orders. */
    public function vitals(array $propertyIds, CarbonImmutable $today): array
    {
        $units = LeasableUnit::whereIn('property_id', $propertyIds)->whereNull('archived_at')->where('is_composite', false)->with('spaces')->get();
        $leased = $units->filter(fn ($u) => $this->occupiedOn($u, $today, 'lease'))->count();
        $aging = $this->kpis->aging($propertyIds, $today);
        $overdue = Money::sum(array_values($aging));
        $overdueLeases = Invoice::whereIn('property_id', $propertyIds)->where('status', 'issued')->where('period_start', '<=', $today->toDateString())->get()
            ->filter(fn ($i) => $this->invoices->outstanding($i)->isPositive())->pluck('lease_id')->unique()->count();
        $openWo = WorkOrder::whereIn('property_id', $propertyIds)->whereNotIn('status', ['closed', 'rejected'])->get();
        return [
            'occupancy' => ['bp' => $units->count() ? intdiv($leased * 10000, $units->count()) : 0, 'leased' => $leased, 'total' => $units->count()],
            'collection' => ['bp' => $this->kpis->collectionRate($propertyIds, $today->startOfYear(), $today)],
            'overdue' => ['amount' => $overdue, 'leases' => $overdueLeases, 'over90' => $aging['90_plus']],
            'work_orders' => ['open' => $openWo->count(), 'emergency' => $openWo->where('priority', 'emergency')->count()],
        ];
    }

    /**
     * The facade of one building: floors top to bottom, each unit with its status today —
     * paid (leased, nothing overdue) · late (overdue invoice) · vacant · maint (open preparation/maintenance hold) · soon (notice given).
     */
    public function facade(int $buildingId, CarbonImmutable $today): array
    {
        $floors = Space::where('building_id', $buildingId)->where('kind', 'floor')->whereNull('archived_at')->get()
            ->sortByDesc(fn ($f) => (int) preg_replace('/\D/', '', $f->code))->values();
        $rows = [];
        foreach ($floors as $floor) {
            $units = Space::where('parent_id', $floor->id)->where('kind', 'unit')->whereNull('archived_at')->orderBy('sort')->get();
            $rows[] = ['floor' => $floor->name_ar, 'units' => $units->map(fn ($s) => $this->unitTile($s, $today))->all()];
        }
        $counts = ['paid' => 0, 'late' => 0, 'vacant' => 0, 'maint' => 0, 'soon' => 0];
        foreach ($rows as $r) {
            foreach ($r['units'] as $u) {
                $counts[$u['status']]++;
            }
        }
        return ['rows' => $rows, 'counts' => $counts];
    }

    /** Windows for the building outlook, in days (owner decision 2026-11-23: 90, 60 and 30). */
    public const OUTLOOK_WINDOWS = [90, 60, 30];

    /**
     * Building outlook (owner request 2026-11-23; REQ-0397 confidence grades, REQ-0262): occupancy today, and for each
     * window how many leased units stay leased and how many will be vacated (notice given or an expected vacancy
     * graded confirmed/likely falling inside the window), plus maintenance spend and the yearly depreciation of assets.
     */
    public function buildingOutlook(int $buildingId, CarbonImmutable $today): array
    {
        $b = Building::find($buildingId);
        $spaceIds = Space::where('building_id', $buildingId)->pluck('id');
        $units = LeasableUnit::whereNull('archived_at')->where('is_composite', false)
            ->whereHas('spaces', fn ($q) => $q->whereIn('spaces.id', $spaceIds))->get();
        // same statuses as the facade and the occupancy vital, so the three figures agree
        $active = Lease::whereIn('leasable_unit_id', $units->pluck('id'))->whereIn('status', ['registered', 'active', 'notice_given', 'draft', 'sent_for_registration', 'awaiting_parties'])
            ->where('starts_on', '<=', $today->toDateString())->where('ends_on', '>=', $today->toDateString())->get()->keyBy('leasable_unit_id');
        $expected = \App\Models\ExpectedVacancy::whereIn('leasable_unit_id', $active->keys())->whereIn('confidence', ['confirmed', 'probable'])->get()->keyBy('leasable_unit_id');
        $total = $units->count();
        $leased = $active->count();
        $windows = [];
        foreach (self::OUTLOOK_WINDOWS as $days) {
            $until = $today->addDays($days);
            $vacating = $active->filter(function (Lease $l) use ($expected, $until) {
                $ends = CarbonImmutable::parse($l->ends_on);
                if ($l->status === 'notice_given') {
                    return $ends <= $until;
                }
                $ev = $expected[$l->leasable_unit_id] ?? null;
                return $ev && CarbonImmutable::parse($ev->month . '-01') <= $until;
            })->count();
            $renewing = $active->filter(fn (Lease $l) => $l->status !== 'notice_given' && CarbonImmutable::parse($l->ends_on) <= $until && ! isset($expected[$l->leasable_unit_id]))->count();
            $staying = $leased - $vacating;
            $windows[$days] = [
                'staying' => $staying, 'vacating' => $vacating, 'renewing' => $renewing,
                'staying_bp' => $total ? intdiv($staying * 10000, $total) : 0,
                'vacating_bp' => $total ? intdiv($vacating * 10000, $total) : 0,
            ];
        }
        $valuation = app(\App\Maintenance\AssetValuation::class)->building($buildingId, (int) $b->property_id, $today);
        return [
            'name' => $b->name_ar, 'property' => $b->property?->name_ar, 'photo' => $b->property?->photoUrl(),
            'total' => $total, 'leased' => $leased, 'occupancy_bp' => $total ? intdiv($leased * 10000, $total) : 0,
            'windows' => $windows,
            'spend_year' => $valuation['totals']['spend_year'], 'spend_all' => $valuation['totals']['spend_all'],
            'assets_yearly' => $valuation['totals']['yearly'], 'assets_book' => $valuation['totals']['book'], 'assets_near' => $valuation['totals']['near'] + $valuation['totals']['ended'],
        ];
    }

    public function buildings(array $propertyIds): array
    {
        return Building::whereIn('property_id', $propertyIds)->whereNull('archived_at')->with('property')->orderBy('id')->get()
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->property->name_ar . ' — ' . $b->name_ar])->all();
    }

    /** What needs the user's attention, most urgent first; only items the user can act on. */
    public function attention(User $user, array $propertyIds, CarbonImmutable $today): array
    {
        $box = $this->inbox->for($user);
        $items = [];
        $emergency = WorkOrder::whereIn('property_id', $propertyIds)->where('priority', 'emergency')->whereNotIn('status', ['closed', 'rejected', 'completed', 'confirmed'])->count();
        if ($emergency && $user->hasPermission('maintenance.view')) {
            $items[] = ['tone' => 'late', 'count' => $emergency, 'title' => 'بلاغ طارئ مفتوح', 'hint' => 'يحتاج إسناداً فورياً', 'url' => 'work-orders'];
        }
        if ($n = $box['counts']['approvals']) {
            $items[] = ['tone' => 'soon', 'count' => $n, 'title' => 'اعتماد ينتظرك', 'hint' => 'عروض تأجير وقيود حساسة', 'url' => count($box['approvals']['entries']) ? 'approval-requests' : 'lease-offers'];
        }
        $awaitingOwner = WorkOrder::whereIn('property_id', $propertyIds)->where('status', 'awaiting_owner_approval')->count();
        if ($awaitingOwner && $user->hasPermission('maintenance.view')) {
            $items[] = ['tone' => 'maint', 'count' => $awaitingOwner, 'title' => 'صيانة بانتظار موافقة المالك', 'hint' => 'تجاوزت سقف الصرف', 'url' => 'work-orders'];
        }
        $new = WorkOrder::whereIn('property_id', $propertyIds)->where('status', 'reported')->count();
        if ($new && $user->hasPermission('maintenance.view')) {
            $items[] = ['tone' => 'maint', 'count' => $new, 'title' => 'بلاغ صيانة جديد', 'hint' => 'لم يُفرز بعد', 'url' => 'work-orders'];
        }
        if ($n = $box['counts']['arrears']) {
            $items[] = ['tone' => 'late', 'count' => $n, 'title' => 'متأخرات مصعّدة إليك', 'hint' => 'مرّ أسبوع على الاستحقاق', 'url' => 'invoices'];
        }
        $recon = $user->hasPermission('finance.view') ? ReconciliationIssue::where('status', 'open')->count() : 0;
        if ($recon) {
            $items[] = ['tone' => 'vacant', 'count' => $recon, 'title' => 'فرق في التسوية', 'hint' => 'عقد أو فاتورة أو دفعة غير متطابقة', 'url' => 'reconciliation-issues'];
        }
        $warranties = $user->hasPermission('maintenance.view') ? $this->maintenance->expiringWarranties($today)->count() : 0;
        if ($warranties) {
            $items[] = ['tone' => 'vacant', 'count' => $warranties, 'title' => 'ضمان أو عقد صيانة ينتهي', 'hint' => 'خلال 30 يوماً', 'url' => 'warranties'];
        }
        return $items;
    }

    /** Contracts whose notice deadline is within 90 days, with the share of the notice window already elapsed. */
    public function renewals(array $propertyIds, CarbonImmutable $today): array
    {
        $out = [];
        $leases = Lease::whereIn('status', ['registered', 'active', 'notice_given'])->whereHas('unit', fn ($q) => $q->whereIn('property_id', $propertyIds))
            ->with(['unit', 'tenant'])->orderBy('ends_on')->get();
        foreach ($leases as $l) {
            $deadline = $this->leasing->noticeDeadline($l);
            $daysToDeadline = (int) $today->diffInDays($deadline, false);
            if ($daysToDeadline > 90 || CarbonImmutable::parse($l->ends_on) < $today) {
                continue;
            }
            $out[] = [
                'lease_id' => $l->id, 'unit' => $l->unit->code, 'tenant' => $l->tenant->name_ar,
                'ends_on' => $this->dates->format(CarbonImmutable::parse($l->ends_on), 'both_hijri_first', 'Asia/Riyadh'),
                'deadline' => $deadline->toDateString(), 'days' => $daysToDeadline,
                'notice' => $l->status === 'notice_given',
                // 90-day window before the deadline → 0..100 % elapsed (integer, for the bar)
                'elapsed' => max(0, min(100, intdiv((90 - max(0, $daysToDeadline)) * 100, 90))),
            ];
        }
        return array_slice($out, 0, 6);
    }

    private function unitTile(Space $space, CarbonImmutable $today): array
    {
        $unit = $space->leasableUnits()->whereNull('archived_at')->where('is_composite', false)->first();
        $status = 'vacant';
        $tenant = null;
        $lease = $unit ? Lease::where('leasable_unit_id', $unit->id)->whereIn('status', ['registered', 'active', 'notice_given', 'draft', 'sent_for_registration', 'awaiting_parties'])
            ->where('starts_on', '<=', $today->toDateString())->where('ends_on', '>=', $today->toDateString())->with('tenant')->first() : null;
        if (DB::table('space_occupancies')->where('space_id', $space->id)->where('kind', 'maintenance')->whereRaw('period @> ?::date', [$today->toDateString()])->exists()
            || WorkOrder::where('space_id', $space->id)->where('type', 'unit_prep')->whereNotIn('status', ['closed', 'rejected'])->exists()) {
            $status = 'maint';
        } elseif ($lease) {
            $tenant = $lease->tenant->name_ar;
            $overdue = Invoice::where('lease_id', $lease->id)->where('status', 'issued')->where('period_start', '<=', $today->toDateString())->get()
                ->contains(fn ($i) => $this->invoices->outstanding($i)->isPositive());
            $status = $lease->status === 'notice_given' ? 'soon' : ($overdue ? 'late' : 'paid');
        }
        return ['code' => $space->code, 'type' => $space->unit_type, 'status' => $status, 'tenant' => $tenant, 'unit_id' => $unit?->id];
    }

    private function occupiedOn(LeasableUnit $u, CarbonImmutable $on, string $kind): bool
    {
        return DB::table('space_occupancies')->whereIn('space_id', $u->spaces->pluck('id'))->where('kind', $kind)->whereRaw('period @> ?::date', [$on->toDateString()])->exists();
    }
    /**
     * First-run setup steps for a new office, each checked against real data (not a flag), shown on the manager home
     * until done. Only the steps the user's role may perform are listed.
     *
     * @return list<array{key:string,label:string,hint:string,url:string,done:bool}>
     */
    public function setupSteps(User $user): array
    {
        $steps = [
            ['key' => 'settings', 'perm' => 'settings.subscriber', 'url' => 'subscriber-settings', 'label' => 'راجع إعدادات المكتب', 'hint' => 'التسجيل الضريبي وطريقة تحصيل الأتعاب والتقويم',
                'done' => DB::table('subscriber_settings')->exists()],
            // office identity and official data (owner request 2026-11-24)
            ['key' => 'identity', 'perm' => 'settings.subscriber', 'url' => 'subscriber-settings', 'label' => 'أضف شعار مكتبك', 'hint' => 'يظهر لموظفيك وملاكك ومستأجريك وفي صفحة المبنى',
                'done' => (bool) app(\App\Settings\Settings::class)->get('theme.logo_light')],
            ['key' => 'official', 'perm' => 'settings.subscriber', 'url' => 'subscriber-settings', 'label' => 'سجّل السجل التجاري ورخصة المكتب', 'hint' => 'يظهران في العقود، وينبّهك النظام قبل انتهاء الرخصة',
                'done' => (bool) ($sub = app(\App\Tenancy\TenantContext::class)->run(null, fn () => \App\Models\Subscriber::find($user->subscriber_id))) && $sub->commercial_registration && $sub->license_no],
            ['key' => 'owner', 'perm' => 'properties.create', 'url' => 'owners/create', 'label' => 'أضف أول مالك', 'hint' => 'فرد أو ورثة أو شركة',
                'done' => \App\Models\Owner::exists()],
            ['key' => 'property', 'perm' => 'properties.create', 'url' => 'add-building', 'label' => 'أضف أول عقار', 'hint' => 'المعالج يبني الأدوار والوحدات والأسعار',
                'done' => Property::exists()],
            ['key' => 'agreement', 'perm' => 'properties.view', 'url' => 'owners', 'label' => 'سجّل عقد الإدارة مع المالك', 'hint' => 'من صفحة المالك ← عقود الإدارة؛ يلزم لكشف الحساب والأتعاب',
                'done' => \App\Models\ManagementAgreement::whereNull('archived_at')->exists()],
            ['key' => 'team', 'perm' => 'users.manage', 'url' => 'users/create', 'label' => 'أضف فريق العمل', 'hint' => 'المحاسب والمشرف والفني، كلٌّ بصلاحياته',
                'done' => User::whereNotNull('subscriber_id')->count() > 1],
            ['key' => 'lease', 'perm' => 'properties.import', 'url' => 'quick-migration', 'label' => 'انقل عقودك القائمة', 'hint' => 'من ملف إيجار أو صورة العقد، ولا يُحفظ شيء قبل تأكيدك',
                'done' => Lease::exists()],
        ];
        return array_values(array_map(fn ($s) => array_diff_key($s, ['perm' => 1]), array_filter($steps, fn ($s) => $user->hasPermission($s['perm']))));
    }
}
