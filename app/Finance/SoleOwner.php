<?php

namespace App\Finance;

use App\Models\Owner;
use App\Models\OwnershipShare;

/**
 * Who bears a property's cost when the system must name one owner (M22, audit 2026-11-24). Until the accountant settles
 * how costs, deposit deductions, the rent supplier and the distribution are split among heirs and partners (REQ-0561,
 * phase 2), a property with several owners is refused here instead of charging the first owner on file.
 */
final class SoleOwner
{
    public static function shareCount(int $propertyId): int
    {
        return OwnershipShare::where('property_id', $propertyId)->count();
    }

    /** The property's only owner, or a refusal in Arabic naming what was stopped. */
    public static function of(int $propertyId, string $whatAr): Owner
    {
        self::assert($propertyId, $whatAr);
        return OwnershipShare::where('property_id', $propertyId)->firstOrFail()->owner;
    }

    public static function assert(int $propertyId, string $whatAr): void
    {
        $n = self::shareCount($propertyId);
        if ($n > 1) {
            throw new FinanceException(self::message($n, $whatAr));
        }
    }

    public static function message(int $owners, string $whatAr): string
    {
        return 'هذا العقار مملوك لأكثر من مالك (' . $owners . ' ملاك)، وتوزيع ' . $whatAr . ' على حصصهم موقوف مؤقتاً حتى تُعتمد طريقته مع المحاسب، '
            . 'فلا يُحمَّل على المالك الأول تلقائياً. لم يُنشأ أي قيد.';
    }
}
