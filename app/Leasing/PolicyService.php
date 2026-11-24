<?php

namespace App\Leasing;

use App\Models\Policy;
use App\Models\Property;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Reads regulatory rules from the policies table by region and effective date (REQ-0196). Adding a city to a rule
 * is a new row, never a code change (REQ-0200).
 */
class PolicyService
{
    /** Active rows of a kind for a property's region/city on a date. */
    public function active(string $kind, Property $property, DateTimeInterface $on): Collection
    {
        $d = $on->format('Y-m-d');
        return Policy::query()
            ->where('kind', $kind)
            ->whereNull('archived_at')
            ->where('effective_from', '<=', $d)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $d))
            ->where(fn ($q) => $q->whereNull('region')->orWhere('region', $property->region))
            ->where(fn ($q) => $q->whereNull('city')->orWhere('city', $property->city))
            ->orderBy('id')
            ->get();
    }

    /** The rent freeze covering a property and usage on a date, if any (REQ-0197). */
    public function freezeFor(Property $property, string $usage, DateTimeInterface $on): ?Policy
    {
        return $this->active('rent_freeze', $property, $on)->first(function (Policy $p) use ($property, $usage) {
            $payload = $p->payload ?? [];
            if (! in_array($usage, $payload['usages'] ?? ['residential', 'commercial'], true)) {
                return false;
            }
            // Scope "within the urban boundary": a property whose flag is unknown is treated as inside (fail safe).
            if (($payload['scope'] ?? null) === 'within_urban_boundary' && $property->within_urban_boundary === false) {
                return false;
            }
            return true;
        });
    }

    /**
     * Where collected funds go (owner decision Q33): 'required' (owner's account only), 'default' (owner's account by
     * default, the office trust account with a warning) or null (no rule). Nationwide rows only.
     */
    public function ownerAccountMode(DateTimeInterface $on): ?string
    {
        $d = $on->format('Y-m-d');
        $rows = Policy::query()->where('kind', 'funds_to_owner_account')->whereNull('archived_at')->whereNull('region')
            ->where('effective_from', '<=', $d)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $d))->get();
        $modes = $rows->map(fn (Policy $p) => ($p->payload ?? [])['mode'] ?? 'default');
        return $modes->contains('required') ? 'required' : ($modes->isNotEmpty() ? 'default' : null);
    }

    /** Refuse collection into the office account while the owner-account rule is required (Q33). */
    public function assertOfficeCollectionAllowed(string $paidTo, DateTimeInterface $on): void
    {
        if ($paidTo === 'manager' && $this->ownerAccountMode($on) === 'required') {
            throw new \App\Finance\FinanceException('لائحة إدارة الأملاك توجب إيداع المبالغ المحصّلة في حساب المالك، لا في حساب المكتب.');
        }
    }

    /** Labels for the "received into" choice, with the draft rule's warning (Q33). */
    public function paidToOptions(DateTimeInterface $on): array
    {
        return match ($this->ownerAccountMode($on)) {
            'required' => ['owner' => 'حساب المالك'],
            'default' => ['owner' => 'حساب المالك', 'manager' => 'حساب أمانات المكتب (تحظره مسودة لائحة إدارة الأملاك)'],
            default => ['owner' => 'حساب المالك', 'manager' => 'حساب أمانات المكتب'],
        };
    }

    /** Allowed non-renewal reason codes for a property on a date (REQ-0195). */
    public function nonRenewalReasons(Property $property, DateTimeInterface $on): Collection
    {
        return $this->active('non_renewal_reason', $property, $on);
    }
}
