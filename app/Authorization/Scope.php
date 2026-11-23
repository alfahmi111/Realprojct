<?php

namespace App\Authorization;

use App\Models\Building;
use App\Models\Property;
use App\Models\User;
use App\Models\UserScope;

/**
 * Role + scope (REQ-0494): which properties a user sees. Managers and institutional owners see the whole subscriber;
 * staff with scope rows see only their portfolios/properties/buildings. RLS already confines everyone to the subscriber.
 */
final class Scope
{
    /** @return int[]|null null = every property of the subscriber */
    public static function propertyIds(User $user): ?array
    {
        $rows = UserScope::where('user_id', $user->id)->get();
        if ($rows->isEmpty()) {
            return in_array($user->role, [Role::Manager, Role::InstitutionalOwner], true) ? null : [];
        }
        $ids = [];
        foreach ($rows as $r) {
            $ids = array_merge($ids, match ($r->scope_type) {
                'portfolio' => Property::where('portfolio_id', $r->scope_id)->pluck('id')->all(),
                'property' => [$r->scope_id],
                'building', 'unit' => array_filter([Building::find($r->scope_id)?->property_id]),
                default => [],
            });
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    public static function canSeeProperty(User $user, int $propertyId): bool
    {
        $ids = self::propertyIds($user);
        return $ids === null || in_array($propertyId, $ids, true);
    }
}
