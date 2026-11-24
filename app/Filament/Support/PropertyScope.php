<?php

namespace App\Filament\Support;

use App\Authorization\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * The user's property scope on every resource (REQ-0494, review M18 A2): staff given specific portfolios, properties or
 * buildings see only the records that belong to them, on top of RLS (which already confines everyone to the office).
 * Managers and institutional owners (no scope rows) see everything. Each record type reaches its property its own way.
 */
final class PropertyScope
{
    /** @return int[]|null null = no limit */
    public static function ids(): ?array
    {
        $user = auth()->user();
        return $user ? Scope::propertyIds($user) : [];
    }

    public static function apply(Builder $q, string $kind): Builder
    {
        $ids = self::ids();
        if ($ids === null) {
            return $q;
        }
        $ids = $ids ?: [0];
        $t = $q->getModel()->getTable();
        $unitIds = fn ($s) => $s->select('id')->from('leasable_units')->whereIn('property_id', $ids);
        $leaseIds = fn ($s) => $s->select('id')->from('leases')->whereIn('leasable_unit_id', $unitIds);
        $spaceIds = fn ($s) => $s->select('id')->from('spaces')->whereIn('property_id', $ids);
        $assetIds = fn ($s) => $s->select('id')->from('assets')->whereIn('space_id', $spaceIds);
        $buildingIds = fn ($s) => $s->select('id')->from('buildings')->whereIn('property_id', $ids);
        return match ($kind) {
            'property' => $q->whereIn("$t.id", $ids),
            'property_id' => $q->whereIn("$t.property_id", $ids),
            'unit' => $q->whereIn("$t.leasable_unit_id", $unitIds),
            'lease' => $q->whereIn("$t.lease_id", $leaseIds),
            'space' => $q->whereIn("$t.space_id", $spaceIds),
            'asset' => $q->whereIn("$t.asset_id", $assetIds),
            'asset_or_space' => $q->where(fn ($w) => $w->whereIn("$t.asset_id", $assetIds)->orWhereIn("$t.space_id", $spaceIds)),
            'building' => $q->whereIn("$t.building_id", $buildingIds),
            'building_or_all' => $q->where(fn ($w) => $w->whereNull("$t.building_id")->orWhereIn("$t.building_id", $buildingIds)),
            'warranty' => $q->where(fn ($w) => $w->whereIn("$t.asset_id", $assetIds)->orWhereIn("$t.building_id", $buildingIds)),
            'tenant' => $q->whereIn("$t.id", fn ($s) => $s->select('tenant_id')->from('leases')->whereIn('leasable_unit_id', $unitIds)),
            'owner' => $q->whereIn("$t.id", fn ($s) => $s->select('owner_id')->from('ownership_shares')->whereIn('property_id', $ids)),
            'payment' => $q->where(fn ($w) => $w->whereIn("$t.id", fn ($s) => $s->select('payment_id')->from('payment_allocations')
                ->whereIn('invoice_id', fn ($i) => $i->select('id')->from('invoices')->whereIn('property_id', $ids)))
                ->orWhereIn("$t.tenant_id", fn ($s) => $s->select('tenant_id')->from('leases')->whereIn('leasable_unit_id', $unitIds))),
            // a property's papers by its property; a tenant's or owner's papers when that party is inside the scope
            'document' => $q->where(fn ($w) => $w->whereIn("$t.property_id", $ids)
                ->orWhere(fn ($d) => $d->where("$t.documentable_type", 'tenant')->whereIn("$t.documentable_id", fn ($s) => $s->select('tenant_id')->from('leases')->whereIn('leasable_unit_id', $unitIds)))
                ->orWhere(fn ($d) => $d->where("$t.documentable_type", 'owner')->whereIn("$t.documentable_id", fn ($s) => $s->select('owner_id')->from('ownership_shares')->whereIn('property_id', $ids)))),
            default => throw new \InvalidArgumentException("Unknown scope kind $kind"),
        };
    }
}
