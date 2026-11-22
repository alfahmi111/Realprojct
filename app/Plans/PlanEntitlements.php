<?php

namespace App\Plans;

use App\Models\LeasableUnit;
use App\Models\Subscriber;

/**
 * Plans and what each includes (spec §13, REQ-0634…REQ-0642). Prices are "for discussion" in the spec, so none are
 * fixed here. Principles: maintenance, tenant portal and asset register in EVERY plan (REQ-0640); pricing by unit,
 * unlimited users in every plan (REQ-0641, REQ-0662).
 */
final class PlanEntitlements
{
    public const PLANS = [
        'owner' => ['ar' => 'مالك', 'max_units' => 20, 'features' => [
            'maintenance', 'tenant_portal', 'asset_register', 'founding_inventory', 'theme_preset', 'logo',
        ]],
        'office' => ['ar' => 'مكتب', 'max_units' => 300, 'features' => [
            'maintenance', 'tenant_portal', 'asset_register', 'founding_inventory', 'theme_preset', 'logo',
            'trust_accounting_full', 'multi_owner', 'vendors', 'custom_primary_color', 'document_identity', 'message_identity',
        ]],
        'company' => ['ar' => 'شركة', 'max_units' => null, 'features' => [
            'maintenance', 'tenant_portal', 'asset_register', 'founding_inventory', 'theme_preset', 'logo',
            'trust_accounting_full', 'multi_owner', 'vendors', 'custom_primary_color', 'document_identity', 'message_identity',
            'white_label', 'custom_domain', 'app_icon_name', 'accounting_integration', 'api',
        ]],
    ];

    /** Add-ons sold on top of any plan (REQ-0638). Waitlist fees stay off until the legal opinion (decision 3). */
    public const ADDONS = ['qr_boards', 'asset_qr_labels', 'waitlist', 'short_term_hospitality', 'extra_whatsapp_messages'];

    public static function has(Subscriber $s, string $feature): bool
    {
        return in_array($feature, self::PLANS[$s->plan]['features'] ?? [], true);
    }

    public static function maxUnits(Subscriber $s): ?int
    {
        return self::PLANS[$s->plan]['max_units'] ?? null;
    }

    /** Users are never limited (REQ-0641). */
    public static function maxUsers(Subscriber $s): ?int
    {
        return null;
    }

    /** Count of leasable units against the plan (composites are views over existing spaces and do not count). */
    public static function assertCanAddUnit(Subscriber $s): void
    {
        $max = self::maxUnits($s);
        if ($max !== null && LeasableUnit::whereNull('archived_at')->where('is_composite', false)->count() >= $max) {
            throw new PlanLimitException("The {$s->plan} plan includes up to $max units.");
        }
    }
}
