<?php

namespace App\BuildingQr;

use App\Leasing\PolicyService;
use App\Models\Building;
use App\Models\LeasableUnit;
use App\Models\Lease;
use App\Models\OwnershipShare;
use App\Models\Prospect;
use App\Models\RatePlan;
use App\Models\Subscriber;
use App\Models\User;
use App\Notifications\NotificationCenter;
use App\Settings\Settings;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Building QR, free version (spec §8; REQ-0585).
 *  - permanent short link that never changes, even if the manager or building data change (REQ-0393);
 *  - scan count and source (REQ-0394); pause without removing the board (REQ-0395);
 *  - live page: vacant now (type, area, price) and units that WILL be vacant by month (REQ-0377…REQ-0381);
 *  - shown only with a valid real-estate ad licence number, displayed prominently (REQ-0389);
 *  - everything public is under the subscriber's name and licence, never the platform's (REQ-0452);
 *  - Riyadh: the price shown is the last registered rent, nothing else (REQ-0392);
 *  - free registration with explicit consent (decision 3; REQ-0391); 60-day re-confirmation (REQ-0405);
 *  - the current tenant is protected: no name, no reason, month not day (REQ-0404).
 */
class BuildingQrService
{
    public function __construct(private TenantContext $ctx, private Settings $settings, private PolicyService $policies, private NotificationCenter $notifications)
    {
    }

    public function create(Building $building): \App\Models\BuildingQr
    {
        return DB::transaction(function () use ($building) {
            do {
                $code = $this->randomCode();
            } while (DB::table('qr_codes')->where('code', $code)->exists());
            DB::table('qr_codes')->insert(['code' => $code, 'subscriber_id' => $building->subscriber_id, 'building_id' => $building->id, 'created_at' => now(), 'updated_at' => now()]);
            return \App\Models\BuildingQr::create(['building_id' => $building->id, 'code' => $code]);
        });
    }

    /** Public resolution of /b/{code}: enters the owning subscriber's context. */
    public function resolve(string $code): ?\App\Models\BuildingQr
    {
        $map = DB::table('qr_codes')->where('code', $code)->first();
        if (! $map) {
            return null;
        }
        $this->ctx->set((int) $map->subscriber_id);
        return \App\Models\BuildingQr::where('code', $code)->first();
    }

    public function recordScan(\App\Models\BuildingQr $qr, ?string $source, ?string $referrer, CarbonImmutable $at): void
    {
        DB::table('qr_scans')->insert(['subscriber_id' => $qr->subscriber_id, 'building_qr_id' => $qr->id, 'source' => $source ? mb_substr($source, 0, 60) : null, 'referrer' => $referrer ? mb_substr($referrer, 0, 250) : null, 'scanned_at' => $at]);
        DB::table('building_qrs')->where('id', $qr->id)->increment('scan_count');
    }

    public function setAdLicense(\App\Models\BuildingQr $qr, string $number, CarbonImmutable $expires): void
    {
        $qr->forceFill(['ad_license_no' => $number, 'ad_license_expires_on' => $expires->toDateString()])->save();
    }

    public function pause(\App\Models\BuildingQr $qr, bool $paused): void
    {
        $qr->forceFill(['paused' => $paused])->save();
    }

    /** Units of a building can be listed only with a valid ad licence (REQ-0389). */
    public function canList(\App\Models\BuildingQr $qr, CarbonImmutable $today): bool
    {
        return ! $qr->paused && $qr->ad_license_no && $qr->ad_license_expires_on && CarbonImmutable::parse($qr->ad_license_expires_on)->endOfDay() >= $today;
    }

    /** Data for the public page. */
    public function page(\App\Models\BuildingQr $qr, CarbonImmutable $today): array
    {
        $building = Building::with('property')->find($qr->building_id);
        $subscriber = $this->ctx->run(null, fn () => Subscriber::find($qr->subscriber_id));
        $base = ['publisher_ar' => $subscriber->name_ar, 'publisher_en' => $subscriber->name_en, 'building_ar' => $building->name_ar, 'ad_license_no' => $qr->ad_license_no];
        if ($qr->paused) {
            return $base + ['state' => 'paused', 'vacant' => [], 'upcoming' => []];
        }
        if (! $this->canList($qr, $today)) {
            return $base + ['state' => 'no_valid_ad_license', 'vacant' => [], 'upcoming' => []];
        }
        $this->syncConfirmedVacancies($today);
        $units = LeasableUnit::whereNull('archived_at')->where('is_composite', false)
            ->whereHas('spaces', fn ($q) => $q->where('building_id', $building->id))->with('spaces')->orderBy('code')->get();
        $vacant = [];
        $upcoming = [];
        $freeze = $this->policies->freezeFor($building->property, 'residential', $today);
        foreach ($units as $u) {
            $occupied = DB::table('space_occupancies')->whereIn('space_id', $u->spaces->pluck('id'))
                ->whereRaw('period @> ?::date', [$today->toDateString()])->exists();
            if (! $occupied) {
                $vacant[] = ['code' => $u->code, 'type' => $u->unit_type, 'area_sqm' => $u->spaces->first()->attributes['area_sqm'] ?? null, 'annual_price' => $this->publicPrice($u, $freeze !== null)];
            }
        }
        foreach (\App\Models\ExpectedVacancy::whereIn('leasable_unit_id', $units->pluck('id'))->where('confidence', 'confirmed')->orderBy('month')->get() as $ev) {
            $u = $units->firstWhere('id', $ev->leasable_unit_id);
            $upcoming[] = ['code' => $u->code, 'type' => $u->unit_type, 'available_from_month' => $ev->month];   // month only, no tenant data
        }
        return $base + ['state' => 'live', 'vacant' => $vacant, 'upcoming' => $upcoming, 'rent_frozen' => $freeze !== null];
    }

    /** Free registration of a prospect with explicit consent (REQ-0380, REQ-0391, decision 3). */
    public function register(\App\Models\BuildingQr $qr, array $data, bool $consent, CarbonImmutable $now): Prospect
    {
        if (! $consent) {
            throw new BuildingQrException('Explicit consent is required to register.');
        }
        if (empty($data['name']) || empty($data['phone'])) {
            throw new BuildingQrException('Name and phone are required.');
        }
        $p = Prospect::create([
            'building_qr_id' => $qr->id, 'name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? null,
            'unit_type' => $data['unit_type'] ?? null, 'budget_minor' => $data['budget_minor'] ?? null, 'desired_month' => $data['desired_month'] ?? null,
            'consent_at' => $now, 'consent_version' => 'v1', 'last_confirmed_at' => $now,
        ]);
        // Manager always; owner too when the owner is not the manager (REQ-0309, REQ-0383).
        $recipients = User::where('role', 'manager')->whereNull('archived_at')->get()->all();
        $building = Building::find($qr->building_id);
        foreach (OwnershipShare::where('property_id', $building->property_id)->with('owner')->get() as $s) {
            if ($s->owner->user_id && ($u = User::find($s->owner->user_id)) && $u->role->value === 'owner') {
                $recipients[] = $u;
            }
        }
        $this->notifications->dispatch('waitlist.new_prospect', $recipients, ['building' => $building->name_ar, 'unit_type' => (string) ($data['unit_type'] ?? '')], $now, subject: $p);
        return $p;
    }

    public function confirmInterest(Prospect $p, CarbonImmutable $now): void
    {
        $p->forceFill(['last_confirmed_at' => $now])->save();
    }

    /** Live list: whoever does not confirm within the interval is removed and their personal data erased (REQ-0405). */
    public function prune(CarbonImmutable $now): int
    {
        $days = (int) $this->settings->get('waitlist.confirm_interval_days');
        $stale = Prospect::where('status', 'active')->where('last_confirmed_at', '<', $now->subDays($days))->get();
        foreach ($stale as $p) {
            $this->erase($p, 'not_confirmed');
        }
        return $stale->count();
    }

    /** Right to deletion (REQ-0391): personal fields are erased, the row keeps only counts for reports. */
    public function erase(Prospect $p, string $reason): void
    {
        $p->forceFill(['name' => '—', 'phone' => '—', 'email' => null, 'status' => 'removed', 'removal_reason' => $reason])->save();
    }

    /** Active waitlist length per building and unit type — a real demand indicator (REQ-0293, REQ-0407). */
    public function waitlistLength(\App\Models\BuildingQr $qr): array
    {
        return Prospect::where('building_qr_id', $qr->id)->where('status', 'active')->selectRaw("coalesce(unit_type, 'any') as t, count(*) as n")->groupBy('t')->pluck('n', 't')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * REQ-0409 (Q15 settled 2026-11-23: threshold 3): a long waitlist while a matching unit stands vacant is an operational
     * problem, not a demand problem → alert the owner (and the manager). At most once per building and unit type a week.
     * Returns the number of alerts raised.
     */
    public function alertLongWaitlistWhileVacant(CarbonImmutable $today): int
    {
        $threshold = (int) $this->settings->get('waitlist.long_threshold');
        if ($threshold < 1) {
            return 0;
        }
        $raised = 0;
        foreach (\App\Models\BuildingQr::all() as $qr) {
            $lengths = $this->waitlistLength($qr);
            $building = Building::find($qr->building_id);
            $units = LeasableUnit::whereNull('archived_at')->where('is_composite', false)
                ->whereHas('spaces', fn ($q) => $q->where('building_id', $building->id))->with('spaces')->get();
            $vacant = $units->filter(fn ($u) => ! DB::table('space_occupancies')->whereIn('space_id', $u->spaces->pluck('id'))
                ->whereRaw('period @> ?::date', [$today->toDateString()])->exists());
            foreach ($lengths as $type => $count) {
                $matching = $type === 'any' ? $vacant : $vacant->where('unit_type', $type);
                if ($count < $threshold || $matching->isEmpty()) {
                    continue;
                }
                $recent = DB::table('notifications_outbox')->where('event', 'waitlist.long_while_vacant')
                    ->where('subject_type', 'BuildingQr')->where('subject_id', $qr->id)->where('payload->unit_type', $type)
                    ->where('created_at', '>=', $today->subDays(7))->exists();
                if ($recent) {
                    continue;
                }
                $recipients = User::where('role', 'manager')->whereNull('archived_at')->get()->all();
                foreach (OwnershipShare::where('property_id', $building->property_id)->with('owner')->get() as $s) {
                    if ($s->owner->user_id && ($u = User::find($s->owner->user_id)) && $u->role->value === 'owner') {
                        $recipients[] = $u;
                    }
                }
                $this->notifications->dispatch('waitlist.long_while_vacant', $recipients, [
                    'building' => $building->name_ar, 'unit_type' => $type, 'waitlist' => (string) $count,
                    'vacant_units' => $matching->pluck('code')->implode('، '),
                ], $today, subject: $qr);
                $raised++;
            }
        }
        return $raised;
    }

    /** A confirmed expected vacancy when a non-renewal notice is given or eviction requested (REQ-0399, REQ-0156, REQ-0410). */
    public function syncConfirmedVacancies(CarbonImmutable $today): void
    {
        foreach (Lease::where('status', 'notice_given')->get() as $l) {
            $from = CarbonImmutable::parse($l->ends_on)->addDay();
            \App\Models\ExpectedVacancy::updateOrCreate(['leasable_unit_id' => $l->leasable_unit_id, 'lease_id' => $l->id], ['month' => $from->format('Y-m'), 'confidence' => 'confirmed', 'source' => 'notice']);
        }
    }

    private function publicPrice(LeasableUnit $u, bool $frozen): ?string
    {
        if ($frozen) {   // Riyadh: only the last registered rent may be shown (REQ-0392)
            return $u->last_registered_rent_minor?->toDecimalString();
        }
        return RatePlan::where('leasable_unit_id', $u->id)->where('duration_unit', 'year')->whereNull('archived_at')->first()?->price_minor->toDecimalString();
    }

    private function randomCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';   // no 0/O/1/I
        $c = '';
        for ($i = 0; $i < 6; $i++) {
            $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $c;
    }
}
