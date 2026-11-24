<?php

namespace App\Leasing;

use App\Audit\AuditLogger;
use App\Integrations\Ejar\EjarGateway;
use App\Models\LeasableUnit;
use App\Models\Lease;
use App\Models\LeaseOffer;
use App\Models\LeaseOfferApproval;
use App\Models\OfferApprovalRule;
use App\Models\RatePlan;
use App\Models\RentEscalation;
use App\Models\SpaceOccupancy;
use App\Models\Tenant;
use App\Models\User;
use App\Settings\Settings;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lease engine (spec §5.1–§5.7, §10.1).
 *
 *   offer → (internal approvals) → accepted by tenant
 *   → draft → sent for registration → awaiting parties → registered → active
 *   → (non-renewal notice) → expired / renewed → settlement & close           (REQ-0181…REQ-0183)
 */
class LeasingService
{
    /** Allowed state transitions of a lease (REQ-0181…REQ-0183, REQ-0459). */
    public const TRANSITIONS = [
        'draft' => ['sent_for_registration', 'cancelled'],
        'sent_for_registration' => ['awaiting_parties', 'draft', 'cancelled'],
        'awaiting_parties' => ['registered', 'draft', 'cancelled'],
        'registered' => ['active'],
        'active' => ['notice_given', 'expired', 'renewed'],
        'notice_given' => ['expired'],
        'expired' => ['settled_closed', 'renewed'],
        'renewed' => ['settled_closed'],
        'settled_closed' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private ScheduleGenerator $schedule,
        private PolicyService $policies,
        private Settings $settings,
        private EjarGateway $ejar,
    ) {
    }

    // ───────────────────────── Offers (REQ-0184) ─────────────────────────

    public function createOffer(User $by, LeasableUnit $unit, Tenant $tenant, array $terms): LeaseOffer
    {
        $this->assertTrackAllowed($unit, $terms['duration_unit']);
        $listed = RatePlan::where('leasable_unit_id', $unit->id)->where('duration_unit', 'year')->whereNull('archived_at')->first()?->price_minor;
        $offer = LeaseOffer::create($terms + [
            'leasable_unit_id' => $unit->id, 'tenant_id' => $tenant->id, 'created_by' => $by->id,
            'listed_annual_rent_minor' => $listed,
        ]);
        $this->assertRegionalPrice($offer);
        return $offer;
    }

    /** Discount from the listed price in basis points (0 when there is no listed price or no discount). */
    public function discountBp(LeaseOffer $offer): int
    {
        $listed = $offer->listed_annual_rent_minor;
        if ($listed === null || ! $listed->isPositive() || ! $offer->annual_rent_minor->lessThan($listed)) {
            return 0;
        }
        return Money::divide(($listed->minor() - $offer->annual_rent_minor->minor()) * 10000, $listed->minor(), \App\Support\Money\Rounding::Up);
    }

    /** Submit for internal approval: steps come from the manager's rules by role and discount. */
    public function submitOffer(LeaseOffer $offer): LeaseOffer
    {
        $this->assertOfferStatus($offer, ['draft']);
        $discount = $this->discountBp($offer);
        $rules = OfferApprovalRule::where('min_discount_bp', '<=', $discount)->orderBy('step')->get();
        DB::transaction(function () use ($offer, $rules) {
            foreach ($rules as $r) {
                LeaseOfferApproval::create(['lease_offer_id' => $offer->id, 'step' => $r->step, 'approver_role' => $r->approver_role]);
            }
            $offer->forceFill(['status' => $rules->isEmpty() ? 'approved' : 'pending_approval'])->save();
        });
        return $offer;
    }

    public function decideOffer(LeaseOffer $offer, User $user, bool $approve, ?string $reason = null): LeaseOffer
    {
        $this->assertOfferStatus($offer, ['pending_approval']);
        $step = $offer->approvals()->whereNull('decision')->first();
        if (! $step || $step->approver_role !== $user->role->value) {
            throw new LeasingException('This user is not the approver of the current step.');
        }
        if ($user->id === $offer->created_by) {
            throw new LeasingException('The creator cannot approve their own offer.');
        }
        DB::transaction(function () use ($offer, $step, $user, $approve, $reason) {
            $step->forceFill(['decided_by' => $user->id, 'decision' => $approve ? 'approved' : 'rejected', 'decided_at' => now()])->save();
            if (! $approve) {
                $offer->forceFill(['status' => 'rejected', 'loss_reason' => $reason ?? 'rejected internally'])->save();
                $offer->archive();
            } elseif (! $offer->approvals()->whereNull('decision')->exists()) {
                $offer->forceFill(['status' => 'approved'])->save();
            }
        });
        return $offer->refresh();
    }

    /** Send the approved offer to the tenant as an acceptance link and hold the unit tentatively. */
    public function sendOffer(LeaseOffer $offer): string
    {
        $this->assertOfferStatus($offer, ['approved']);
        return DB::transaction(function () use ($offer) {
            [$start, $end] = $this->termOf($offer);
            $this->occupy($offer->unit, $start, $end, 'hold', offerId: $offer->id);
            $token = Str::random(48);
            $offer->forceFill(['status' => 'sent', 'accept_token' => hash('sha256', $token)])->save();
            DB::table('lease_offer_tokens')->insert(['token_hash' => hash('sha256', $token), 'subscriber_id' => $offer->subscriber_id]);
            return $token;
        });
    }

    /** The tenant accepts through the link; the offer becomes a full draft lease without re-entry. */
    public function acceptOffer(string $token): Lease
    {
        $offer = LeaseOffer::where('accept_token', hash('sha256', $token))->where('status', 'sent')->first()
            ?? throw new LeasingException('Invalid or used offer link.');
        if ($offer->valid_until && $offer->valid_until->endOfDay()->isPast()) {
            $this->expireOffer($offer, 'offer expired');
            throw new LeasingException('This offer has expired.');
        }
        return DB::transaction(function () use ($offer) {
            [$start, $end] = $this->termOf($offer);
            SpaceOccupancy::where('lease_offer_id', $offer->id)->delete();   // release the hold, then occupy as lease
            $lease = Lease::create([
                'leasable_unit_id' => $offer->leasable_unit_id, 'tenant_id' => $offer->tenant_id, 'lease_offer_id' => $offer->id,
                'usage' => $offer->usage, 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(),
                'annual_rent_minor' => $offer->annual_rent_minor, 'payment_frequency' => $offer->payment_frequency, 'deposit_minor' => $offer->deposit_minor,
            ]);
            $this->occupy($offer->unit, $start, $end, 'lease', leaseId: $lease->id);
            foreach ($offer->escalations ?? [] as $e) {
                RentEscalation::create(['lease_id' => $lease->id] + $e);
            }
            $this->schedule->generate($lease);
            $offer->forceFill(['status' => 'accepted', 'accept_token' => null])->save();
            return $lease;
        });
    }

    /** Rejected or expired offers stay archived for loss analysis. */
    public function expireOffer(LeaseOffer $offer, string $reason): void
    {
        DB::transaction(function () use ($offer, $reason) {
            SpaceOccupancy::where('lease_offer_id', $offer->id)->delete();
            $offer->forceFill(['status' => 'expired', 'loss_reason' => $reason, 'accept_token' => null])->save();
            $offer->archive();
        });
    }

    // ───────────────────────── Lease lifecycle (REQ-0182, REQ-0461) ─────────────────────────

    public function transition(Lease $lease, string $to): Lease
    {
        if (! in_array($to, self::TRANSITIONS[$lease->status] ?? [], true)) {
            throw new LeasingException("A lease cannot go from {$lease->status} to $to.");
        }
        $from = $lease->status;
        $lease->forceFill(['status' => $to])->save();
        if ($to === 'cancelled') {
            SpaceOccupancy::where('lease_id', $lease->id)->delete();   // a cancelled lease frees its unit (review M18)
        }
        AuditLogger::record('lease.status', $lease, ['status' => $from], ['status' => $to]);
        return $lease;
    }

    /** Manual-assisted Ejar: prepare the data package and mark as sent (REQ-0461). */
    public function sendForRegistration(Lease $lease): array
    {
        $package = $this->ejar->prepareRegistration($lease->only(['id', 'leasable_unit_id', 'tenant_id', 'starts_on', 'ends_on', 'annual_rent_minor', 'payment_frequency', 'deposit_minor', 'usage']));
        $this->transition($lease, 'sent_for_registration');
        $this->ejar->recordStatus((string) $lease->id, 'sent');
        return $package;
    }

    public function markAwaitingParties(Lease $lease): void
    {
        $this->transition($lease, 'awaiting_parties');
        $this->ejar->recordStatus((string) $lease->id, 'awaiting_parties');
    }

    /** The employee enters the registered Ejar contract number (the link key, REQ-0186); the unit's last registered rent is updated (REQ-0199). */
    public function register(Lease $lease, string $ejarNumber): void
    {
        DB::transaction(function () use ($lease, $ejarNumber) {
            $this->ejar->recordStatus((string) $lease->id, 'registered', $ejarNumber);
            $lease->forceFill(['ejar_contract_number' => $ejarNumber])->save();
            $this->transition($lease, 'registered');
            $lease->unit->forceFill(['last_registered_rent_minor' => $lease->annual_rent_minor])->save();
        });
    }

    public function activate(Lease $lease): void
    {
        $this->transition($lease, 'active');
    }

    /** Invoices only for "registered" or "active" leases (REQ-0185). */
    public function canInvoice(Lease $lease): bool
    {
        return in_array($lease->status, ['registered', 'active', 'notice_given'], true);
    }

    // ───────────────────────── Renewal (REQ-0191, REQ-0194, REQ-0195) ─────────────────────────

    /** Leases renew automatically unless notice is given in writing before the notice period; alerts use this date. */
    public function noticeDeadline(Lease $lease): CarbonImmutable
    {
        $days = (int) $this->settings->get('leasing.non_renewal_notice_days');
        return CarbonImmutable::parse($lease->ends_on)->subDays($days);
    }

    /**
     * Record a written non-renewal notice. A lessor's refusal must carry a reason code from the policies table and a
     * supporting document wherever the region defines reason codes (Riyadh); a refusal without both is blocked.
     */
    public function giveNotice(Lease $lease, string $by, CarbonImmutable $on, ?string $reasonCode = null, ?string $document = null): void
    {
        if (! in_array($by, ['lessor', 'tenant'], true)) {
            throw new LeasingException('Notice is given by the lessor or the tenant.');
        }
        if ($on->greaterThan($this->noticeDeadline($lease))) {
            throw new LeasingException('The notice period has passed; the lease renews automatically.');
        }
        if ($by === 'lessor') {
            $codes = $this->policies->nonRenewalReasons($lease->unit->property, $on);
            if ($codes->isNotEmpty()) {
                if ($reasonCode === null || ! $codes->contains('code', $reasonCode) || $document === null) {
                    throw new LeasingException('A refusal to renew here needs an allowed reason code and a supporting document.');
                }
            }
        }
        DB::transaction(function () use ($lease, $by, $on, $reasonCode, $document) {
            $lease->forceFill(['non_renewal_notice_on' => $on->toDateString(), 'non_renewal_by' => $by, 'non_renewal_reason_code' => $reasonCode, 'non_renewal_document' => $document])->save();
            $this->transition($lease, 'notice_given');
        });
    }

    /** Renew: a new lease for the next term. Under a rent freeze, the rent cannot exceed the last registered rent (REQ-0198). */
    public function renew(Lease $lease, Money $annualRent, int $years = 1, ?int $months = null, bool $automatic = false): Lease
    {
        $start = CarbonImmutable::parse($lease->ends_on)->addDay();
        $freeze = $this->policies->freezeFor($lease->unit->property, $lease->usage, $start);
        $last = $lease->unit->last_registered_rent_minor;
        if ($freeze) {
            if ($last === null) {
                throw new LeasingException('The last registered rent of this unit is required inside a frozen area.');
            }
            if ($annualRent->greaterThan($last)) {
                throw new LeasingException('Renewal rent cannot exceed the last registered rent while the freeze applies: ' . $freeze->title_ar);
            }
        }
        return DB::transaction(function () use ($lease, $annualRent, $years, $months, $automatic, $start) {
            $end = self::lastDay($start, $start->addMonthsNoOverflow($months ?? 12 * $years));
            $new = Lease::create([
                'leasable_unit_id' => $lease->leasable_unit_id, 'tenant_id' => $lease->tenant_id, 'parent_lease_id' => $lease->id, 'kind' => 'renewal',
                'usage' => $lease->usage, 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(),
                'annual_rent_minor' => $annualRent, 'payment_frequency' => $lease->payment_frequency, 'deposit_minor' => $lease->deposit_minor,
            ]);
            $this->occupy($lease->unit, $start, $end, 'lease', leaseId: $new->id);
            if ($automatic) {   // Q35: the renewal stands by the contract itself; it runs without a fresh registration step
                $new->forceFill(['status' => 'active', 'auto_renewed_at' => now(), 'ejar_contract_number' => $lease->ejar_contract_number])->save();
                AuditLogger::record('lease.auto_renewed', $new, null, ['parent_lease_id' => $lease->id, 'ends_on' => $end->toDateString()]);
            }
            $this->schedule->generate($new);
            $this->transition($lease, 'renewed');
            return $new;
        });
    }

    /**
     * Owner decision Q35: a lease that reached its end with no notice renews by itself for the same term at the same
     * rent (a freeze never blocks the same rent; a missing registered rent inside a frozen area does, and then a person
     * decides). The renewal is active at once and can be ended early with {@see terminateRenewal()}.
     */
    public function autoRenew(Lease $lease): Lease
    {
        $start = CarbonImmutable::parse($lease->starts_on);
        $months = max(1, (int) round($start->diffInMonths(CarbonImmutable::parse($lease->ends_on)->addDay())));
        return $this->renew($lease, $lease->annual_rent_minor, months: $months, automatic: true);
    }

    /**
     * End an automatically renewed lease early (Q35 «ويمكن إنهاؤه»). The term is cut to `$endOn`; unbilled
     * instalments after it are voided and the one that straddles it is prorated by days; the unit is freed after it.
     * An invoice already issued for days after `$endOn` stays as is: its credit is a separate, approved step.
     */
    public function terminateRenewal(Lease $lease, CarbonImmutable $endOn, string $by, string $reason, CarbonImmutable $today): Lease
    {
        if ($lease->auto_renewed_at === null || $lease->status !== 'active') {
            throw new LeasingException('يمكن إنهاء العقد المجدَّد تلقائياً وهو ساري فقط.');
        }
        if (! in_array($by, ['lessor', 'tenant'], true) || trim($reason) === '') {
            throw new LeasingException('حدّد من طلب الإنهاء وسببه.');
        }
        if ($endOn->lessThan($today) || $endOn->lessThan($lease->starts_on) || $endOn->greaterThanOrEqualTo($lease->ends_on)) {
            throw new LeasingException('تاريخ الإنهاء يكون من اليوم فصاعداً وقبل نهاية العقد.');
        }
        return DB::transaction(function () use ($lease, $endOn, $by, $reason) {
            $end = $endOn->toDateString();
            foreach ($lease->scheduleLines()->where('status', 'scheduled')->get() as $line) {
                $ps = CarbonImmutable::parse($line->period_start);
                $pe = CarbonImmutable::parse($line->period_end);
                if ($ps->toDateString() > $end) {
                    $line->forceFill(['status' => 'void'])->save();
                } elseif ($pe->toDateString() > $end) {
                    $all = (int) $ps->diffInDays($pe) + 1;
                    $kept = (int) $ps->diffInDays($endOn) + 1;
                    $amount = $line->amount_minor instanceof Money ? $line->amount_minor : Money::of((int) $line->amount_minor);
                    $line->forceFill(['period_end' => $end, 'amount_minor' => $amount->multiplyRatio($kept, $all)])->save();
                }
            }
            DB::update("UPDATE space_occupancies SET period = daterange(lower(period), (?::date + 1), '[)') WHERE lease_id = ?", [$end, $lease->id]);
            $old = CarbonImmutable::parse($lease->ends_on)->toDateString();
            $lease->forceFill(['ends_on' => $end, 'status' => 'notice_given', 'non_renewal_notice_on' => now()->toDateString(),
                'terminated_by' => $by, 'termination_reason' => mb_substr($reason, 0, 500), 'terminated_at' => now()])->save();
            AuditLogger::record('lease.terminated', $lease, ['ends_on' => $old, 'status' => 'active'], ['ends_on' => $end, 'status' => 'notice_given', 'by' => $by, 'reason' => $reason]);
            return $lease;
        });
    }

    /** Sublease rent may not exceed the original lease rent (REQ-0202). */
    public function createSublease(Lease $parent, Tenant $subtenant, Money $annualRent, CarbonImmutable $start, CarbonImmutable $end): Lease
    {
        if ($annualRent->greaterThan($parent->annual_rent_minor)) {
            throw new LeasingException('A sublease rent cannot exceed the original lease rent.');
        }
        if ($start->lessThan($parent->starts_on) || $end->greaterThan($parent->ends_on)) {
            throw new LeasingException('A sublease must fall inside the original lease term.');
        }
        return Lease::create([
            'leasable_unit_id' => $parent->leasable_unit_id, 'tenant_id' => $subtenant->id, 'parent_lease_id' => $parent->id, 'kind' => 'sublease',
            'usage' => $parent->usage, 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(),
            'annual_rent_minor' => $annualRent, 'payment_frequency' => $parent->payment_frequency,
        ]);
    }

    /** Renewals board: leases ending within 30/60/90/180 days (REQ-0194). Intent grouping arrives with the survey (P2). */
    public function renewalsBoard(CarbonImmutable $today): array
    {
        $out = [];
        foreach ([30, 60, 90, 180] as $days) {
            $out[$days] = Lease::whereIn('status', ['registered', 'active', 'notice_given'])
                ->whereBetween('ends_on', [$today->toDateString(), $today->addDays($days)->toDateString()])
                ->orderBy('ends_on')->get()->map(fn ($l) => ['lease_id' => $l->id, 'ends_on' => $l->ends_on->toDateString(), 'notice_deadline' => $this->noticeDeadline($l)->toDateString(), 'intent' => 'unknown'])->all();
        }
        return $out;
    }

    // ───────────────────────── Availability (REQ-0178…REQ-0180) ─────────────────────────

    /**
     * Occupy every space of a leasable unit for [start, end]. Because a composite unit and its parts share spaces,
     * PostgreSQL's exclusion constraint rejects a whole-building lease while any apartment is leased, and vice versa
     * (REQ-0102, REQ-0179).
     */
    public function occupy(LeasableUnit $unit, CarbonImmutable $start, CarbonImmutable $end, string $kind, ?int $leaseId = null, ?int $offerId = null): void
    {
        try {
            DB::transaction(function () use ($unit, $start, $end, $kind, $leaseId, $offerId) {
                foreach ($unit->spaces as $space) {
                    $o = new SpaceOccupancy();
                    $o->forceFill(['space_id' => $space->id, 'lease_id' => $leaseId, 'lease_offer_id' => $offerId, 'kind' => $kind,
                        'period' => '[' . $start->toDateString() . ',' . $end->toDateString() . ']'])->save();
                }
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'space_occupancies_no_overlap')) {
                throw new LeasingException('This unit (or a unit sharing its spaces) is already leased, held or under maintenance for these dates.');
            }
            throw $e;
        }
    }

    /**
     * Availability grid for a property: per leasable unit, the status for each day in [from, to]:
     * leased | held | maintenance | vacant, and "vacating within N days" with confidence (confirmed when notice given).
     */
    public function availability(int $propertyId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];
        foreach (LeasableUnit::where('property_id', $propertyId)->whereNull('archived_at')->with('spaces')->orderBy('code')->get() as $unit) {
            $spaceIds = $unit->spaces->pluck('id')->all();
            $occ = SpaceOccupancy::whereIn('space_id', $spaceIds)
                ->whereRaw('period && daterange(?, ?, \'[]\')', [$from->toDateString(), $to->toDateString()])
                ->selectRaw('kind, lease_id, lower(period) as s, upper(period) - 1 as e')->get();
            $days = [];
            $dayLease = [];   // which lease occupies each day, so back-to-back leases are shown apart (review M18)
            for ($d = $from; $d <= $to; $d = $d->addDay()) {
                $hit = $occ->first(fn ($o) => $d->toDateString() >= $o->s && $d->toDateString() <= $o->e);
                $days[$d->toDateString()] = $hit ? ['lease' => 'leased', 'hold' => 'held', 'maintenance' => 'maintenance'][$hit->kind] : 'vacant';
                $dayLease[$d->toDateString()] = $hit?->lease_id;
            }
            $today = CarbonImmutable::today();
            $vacating = Lease::where('leasable_unit_id', $unit->id)->where('status', 'notice_given')->where('ends_on', '>=', $today->toDateString())->orderBy('ends_on')->first();
            $rows[$unit->code] = [
                'unit_id' => $unit->id, 'days' => $days, 'day_leases' => $dayLease, 'composite' => (bool) $unit->is_composite,
                // counted from today, not from the window start (review M18)
                'vacating' => $vacating ? ['in_days' => (int) $today->diffInDays(CarbonImmutable::parse($vacating->ends_on)), 'confidence' => 'confirmed'] : null,
            ];
        }
        return $rows;
    }

    // ───────────────────────── Guards ─────────────────────────

    private function termOf(LeaseOffer $offer): array
    {
        $start = CarbonImmutable::parse($offer->starts_on);
        $end = match ($offer->duration_unit) {
            'month' => $start->addMonthsNoOverflow($offer->duration_count),
            'year' => $start->addYearsNoOverflow($offer->duration_count),
            default => throw new LeasingException('Daily/weekly durations belong to phase 3.'),
        };
        return [$start, self::lastDay($start, $end)];
    }

    /** Long track = month/year leases documented in Ejar; day/week is short-term hospitality (phase 3, REQ-0172…REQ-0176). */
    private function assertTrackAllowed(LeasableUnit $unit, string $durationUnit): void
    {
        if (in_array($durationUnit, ['day', 'week'], true)) {
            throw new LeasingException('Daily/weekly letting (tourist hospitality) is phase 3 (decision 6).');
        }
        if (! in_array($durationUnit, ['month', 'year'], true)) {
            throw new LeasingException("Unknown duration unit [$durationUnit].");
        }
        if ($unit->allowed_tracks === 'short') {
            throw new LeasingException('This unit is open for short-term letting only.');
        }
    }

    /** In a frozen area the price of a vacant unit is fixed at the last registered rent (REQ-0197, REQ-0392). */
    private function assertRegionalPrice(LeaseOffer $offer): void
    {
        $unit = $offer->unit()->with('property')->first();
        $freeze = $this->policies->freezeFor($unit->property, $offer->usage, CarbonImmutable::parse($offer->starts_on));
        if ($freeze && $unit->last_registered_rent_minor !== null && $offer->annual_rent_minor->greaterThan($unit->last_registered_rent_minor)) {
            throw new LeasingException('Inside a frozen area the rent cannot exceed the last registered rent of the unit: ' . $freeze->title_ar);
        }
    }

    private function assertOfferStatus(LeaseOffer $offer, array $allowed): void
    {
        if (! in_array($offer->status, $allowed, true)) {
            throw new LeasingException("Offer is {$offer->status}.");
        }
    }

    /**
     * Last day of a term that starts on $start and whose anniversary is $anniversary. When adding months or years had to
     * clip a month-end start (31 Jan + 1 month = 28 Feb, 29 Feb + 1 year = 28 Feb) the clipped day itself is the last day,
     * otherwise the day before the anniversary (review M18: the clipped case lost a day).
     */
    public static function lastDay(CarbonImmutable $start, CarbonImmutable $anniversary): CarbonImmutable
    {
        return $anniversary->day < $start->day ? $anniversary : $anniversary->subDay();
    }
}
