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

    /** Allowed non-renewal reason codes for a property on a date (REQ-0195). */
    public function nonRenewalReasons(Property $property, DateTimeInterface $on): Collection
    {
        return $this->active('non_renewal_reason', $property, $on);
    }
}
