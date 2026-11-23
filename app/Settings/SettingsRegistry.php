<?php

namespace App\Settings;

/**
 * The fixed schema of every setting (REQ-0374): its levels, type, allowed values and default.
 *
 * Defaults are ONLY the values written in docs/SPEC.md. Where the spec gives none, the default is null and
 * the question is in docs/QUESTIONS.md (rule R-01). Provisional values of open decisions carry a
 * `pending` marker (PENDING-D8/D9/D10, PROMPT.md A.8).
 *
 * Levels (REQ-0321…REQ-0326): platform < subscriber < property < user — the more specific level wins,
 * and the subscriber admin can lock a key so users cannot change it.
 */
final class SettingsRegistry
{
    public const LEVELS = ['platform', 'subscriber', 'property', 'user'];

    /** @return array<string, array{levels: string[], type: string, options?: array, default: mixed, lockable?: bool, pending?: string, req: string}> */
    public static function definitions(): array
    {
        $u = ['subscriber', 'user'];
        return [
            // ب) Dark mode — REQ-0338
            'appearance.mode' => ['levels' => $u, 'type' => 'enum', 'options' => ['light', 'dark', 'auto'], 'default' => 'auto', 'req' => 'REQ-0338'],
            // أ) Themes — REQ-0327…REQ-0332, decision 11 (no platform default fixed in code)
            'platform.default_theme' => ['levels' => ['platform'], 'type' => 'enum', 'options' => ['withaq', 'clay', 'ink', 'plum'], 'default' => 'withaq', 'decision' => 'decision 11 settled by the owner 2026-11-22', 'req' => 'REQ-0328, REQ-0654'],
            'platform.available_themes' => ['levels' => ['platform'], 'type' => 'list', 'options' => ['withaq', 'clay', 'ink', 'plum'], 'default' => ['withaq', 'clay', 'ink', 'plum'], 'req' => 'REQ-0323, REQ-0328'],
            'theme.min_contrast_ratio' => ['levels' => ['platform'], 'type' => 'ratio', 'default' => ['numerator' => 45, 'denominator' => 10], 'decision' => 'Q10 settled 2026-11-23: 4.5:1', 'req' => 'REQ-0329'],
            'theme.preset' => ['levels' => ['subscriber'], 'type' => 'enum', 'options' => ['withaq', 'clay', 'ink', 'plum'], 'default' => null, 'req' => 'REQ-0328'],
            'theme.custom_primary' => ['levels' => ['subscriber'], 'type' => 'color', 'default' => null, 'req' => 'REQ-0329'],
            'theme.logo_light' => ['levels' => ['subscriber'], 'type' => 'string', 'default' => null, 'req' => 'REQ-0329'],
            'theme.logo_dark' => ['levels' => ['subscriber'], 'type' => 'string', 'default' => null, 'req' => 'REQ-0329, REQ-0341'],
            'theme.custom_domain' => ['levels' => ['subscriber'], 'type' => 'string', 'default' => null, 'req' => 'REQ-0332'],
            'theme.app_name' => ['levels' => ['subscriber'], 'type' => 'string', 'default' => null, 'req' => 'REQ-0331, REQ-0337'],

            // ج) Dates — REQ-0342…REQ-0348
            'date.display' => ['levels' => $u, 'type' => 'enum', 'options' => ['hijri', 'gregorian', 'both_hijri_first', 'both_gregorian_first'], 'default' => 'both_hijri_first', 'req' => 'REQ-0343'],
            'date.documents' => ['levels' => ['subscriber'], 'type' => 'enum', 'options' => ['hijri', 'gregorian', 'both'], 'default' => 'both', 'lockable' => true, 'req' => 'REQ-0344'],
            'date.hijri_calendar' => ['levels' => ['platform'], 'type' => 'enum', 'options' => ['umm_al_qura'], 'default' => 'umm_al_qura', 'req' => 'REQ-0345'],
            // Field only — no correction logic until decision 13 is specified (REQ-0346, REQ-0656).
            'date.hijri_correction' => ['levels' => ['subscriber'], 'type' => 'enum', 'options' => [-1, 0, 1], 'default' => 0, 'req' => 'REQ-0346'],
            'date.week_start' => ['levels' => ['subscriber'], 'type' => 'enum', 'options' => ['sunday', 'saturday', 'monday'], 'default' => 'sunday', 'req' => 'REQ-0347'],
            'date.timezone' => ['levels' => ['subscriber'], 'type' => 'timezone', 'default' => 'Asia/Riyadh', 'req' => 'REQ-0348'],

            // هـ) Language, numbers, currency — REQ-0362…REQ-0364
            'locale.interface' => ['levels' => $u, 'type' => 'enum', 'options' => ['ar', 'en'], 'default' => 'ar', 'req' => 'REQ-0362, REQ-0490'],
            'locale.messages' => ['levels' => ['user'], 'type' => 'enum', 'options' => ['ar', 'en'], 'default' => null, 'req' => 'REQ-0362'],
            'numbers.digits' => ['levels' => $u, 'type' => 'enum', 'options' => ['western', 'eastern'], 'default' => 'western', 'req' => 'REQ-0363'],
            'numbers.currency_display' => ['levels' => $u, 'type' => 'enum', 'options' => ['symbol', 'abbreviation'], 'default' => 'symbol', 'decision' => 'Q10 amended by the owner 2026-11-23: the official riyal sign U+20C1', 'req' => 'REQ-0364'],

            // ز) Accessibility — REQ-0373
            'a11y.font_size' => ['levels' => ['user'], 'type' => 'enum', 'options' => ['normal', 'large', 'larger'], 'default' => 'normal', 'req' => 'REQ-0373'],
            'a11y.high_contrast' => ['levels' => ['user'], 'type' => 'bool', 'default' => false, 'req' => 'REQ-0373'],
            'a11y.reduce_motion' => ['levels' => ['user'], 'type' => 'bool', 'default' => false,   // off = follow the device setting (prefers-reduced-motion is always honoured)
            'req' => 'REQ-0373'],
            'a11y.table_density' => ['levels' => ['user'], 'type' => 'enum', 'options' => ['comfortable', 'compact'], 'default' => 'comfortable', 'req' => 'REQ-0373'],
            'a11y.landing_page' => ['levels' => ['user'], 'type' => 'string', 'default' => null, 'req' => 'REQ-0373'],

            // د) Notifications — REQ-0353…REQ-0361
            'notifications.preferences' => ['levels' => ['user'], 'type' => 'object', 'default' => [], 'req' => 'REQ-0326, REQ-0355'],
            'notifications.events' => ['levels' => ['subscriber'], 'type' => 'object', 'default' => null, 'req' => 'REQ-0355'],
            'notifications.mandatory' => ['levels' => ['subscriber'], 'type' => 'list', 'default' => null, 'req' => 'REQ-0356'],
            'notifications.quiet_hours' => ['levels' => ['subscriber'], 'type' => 'time_range', 'default' => ['start' => '22:00', 'end' => '08:00'], 'req' => 'REQ-0357'],
            'notifications.daily_digest' => ['levels' => ['user'], 'type' => 'bool', 'default' => false,   // opt-in; sent 07:00 Riyadh (Q10)
            'req' => 'REQ-0358'],

            // و) Subscriber operational & financial settings — REQ-0365…REQ-0372
            'finance.fiscal_year_start_month' => ['levels' => ['subscriber'], 'type' => 'int', 'min' => 1, 'max' => 12, 'default' => 1, 'req' => 'REQ-0366'],
            'finance.vat_registered' => ['levels' => ['subscriber'], 'type' => 'bool', 'default' => null, 'req' => 'REQ-0366'],
            'finance.tax_period' => ['levels' => ['subscriber'], 'type' => 'enum', 'options' => ['monthly', 'quarterly'], 'default' => 'quarterly', 'req' => 'REQ-0366'],
            // Management fee terms differ from office to office — the owner's decision (2026-11-23): set per subscriber.
            'finance.fee.collection' => ['levels' => ['subscriber'], 'type' => 'enum', 'options' => ['deduct_from_remittance', 'invoice_owner'], 'default' => 'deduct_from_remittance', 'req' => 'REQ-0066, REQ-0305'],
            'finance.fee.percentage_basis' => ['levels' => ['subscriber'], 'type' => 'enum', 'options' => ['collected', 'due'], 'default' => 'collected', 'req' => 'REQ-0066, REQ-0305'],
            'finance.fee.fixed_period' => ['levels' => ['subscriber'], 'type' => 'enum', 'options' => ['monthly', 'quarterly', 'semiannual', 'annual'], 'default' => 'monthly', 'decision' => 'Q13: monthly by default, editable', 'req' => 'REQ-0066'],
            'finance.numbering' => ['levels' => ['subscriber'], 'type' => 'object', 'default' => null, 'req' => 'REQ-0366'],
            'finance.document_templates' => ['levels' => ['subscriber'], 'type' => 'object', 'default' => null, 'req' => 'REQ-0366'],
            'leasing.non_renewal_notice_days' => ['levels' => ['subscriber'], 'type' => 'int', 'min' => 1, 'max' => 3650, 'default' => 60, 'req' => 'REQ-0367'],
            'leasing.renewal_survey_days' => ['levels' => ['subscriber'], 'type' => 'int', 'min' => 1, 'max' => 3650, 'default' => 90, 'req' => 'REQ-0367'],
            'leasing.default_deposit' => ['levels' => ['subscriber'], 'type' => 'object', 'default' => null, 'req' => 'REQ-0367'],
            'leasing.payment_reminders' => ['levels' => ['subscriber', 'property'], 'type' => 'object', 'default' => null, 'req' => 'REQ-0367, REQ-0325'],
            'maintenance.response_hours' => ['levels' => ['subscriber', 'property'], 'type' => 'object', 'default' => ['emergency' => 2, 'urgent' => 24, 'normal' => 72], 'req' => 'REQ-0368, REQ-0325'],
            'maintenance.owner_approval_cap' => ['levels' => ['subscriber'], 'type' => 'int', 'min' => 0, 'default' => null, 'req' => 'REQ-0368'],
            'maintenance.inventory_interval_months' => ['levels' => ['subscriber', 'property'], 'type' => 'object', 'default' => ['unfurnished' => 12, 'furnished' => 6], 'pending' => 'PENDING-D9', 'req' => 'REQ-0368, REQ-0651'],
            'waitlist.long_threshold' => ['levels' => ['subscriber'], 'type' => 'int', 'min' => 1, 'max' => 1000, 'default' => 3, 'decision' => 'Q15 settled 2026-11-23', 'req' => 'REQ-0409'],
            'waitlist.confirm_interval_days' => ['levels' => ['subscriber'], 'type' => 'int', 'min' => 1, 'max' => 3650, 'default' => 60, 'req' => 'REQ-0369, REQ-0405'],
            'waitlist.probable_visibility' => ['levels' => ['subscriber'], 'type' => 'enum', 'options' => ['public', 'registered_only'], 'default' => 'registered_only', 'pending' => 'PENDING-D8', 'req' => 'REQ-0369, REQ-0650'],
            'security.two_factor_roles' => ['levels' => ['subscriber'], 'type' => 'list', 'default' => ['manager', 'owner', 'institutional_owner'], 'lockable' => true, 'req' => 'REQ-0370, REQ-0506'],
            'security.session_minutes' => ['levels' => ['subscriber'], 'type' => 'int', 'min' => 5, 'max' => 10080, 'default' => 120, 'decision' => 'Q10 settled 2026-11-23: idle timeout', 'req' => 'REQ-0370'],
            'security.password_policy' => ['levels' => ['subscriber'], 'type' => 'object', 'default' => ['min_length' => 12], 'decision' => 'Q10 settled 2026-11-23', 'req' => 'REQ-0370'],
            'security.dual_approval' => ['levels' => ['subscriber'], 'type' => 'bool', 'default' => true, 'pending' => 'PENDING-D10', 'req' => 'REQ-0069, REQ-0370, REQ-0652'],
            // Launch gates (REQ-0508 penetration test before launch).
            'launch.penetration_test_passed' => ['levels' => ['platform'], 'type' => 'bool', 'default' => null, 'req' => 'REQ-0508'],
            'data.retention' => ['levels' => ['subscriber'], 'type' => 'object', 'default' => null, 'req' => 'REQ-0371'],
        ];
    }

    public static function get(string $key): array
    {
        $d = self::definitions()[$key] ?? null;
        if ($d === null) {
            throw new UnknownSettingException("Unknown setting [$key].");
        }
        return $d;
    }

    /** Validate a value against the key's schema; returns the normalised value or throws. */
    public static function validate(string $key, mixed $value): mixed
    {
        $d = self::get($key);
        if ($value === null) {
            return null;
        }
        $ok = match ($d['type']) {
            'enum' => in_array($value, $d['options'], true),
            'list' => is_array($value) && array_is_list($value) && (! isset($d['options']) || ! array_diff($value, $d['options'])),
            'bool' => is_bool($value),
            'int' => is_int($value) && (! isset($d['min']) || $value >= $d['min']) && (! isset($d['max']) || $value <= $d['max']),
            'string' => is_string($value) && mb_strlen($value) <= 500,
            'color' => is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1,
            'ratio' => is_array($value) && isset($value['numerator'], $value['denominator']) && is_int($value['numerator']) && is_int($value['denominator']) && $value['denominator'] > 0,
            'timezone' => is_string($value) && in_array($value, timezone_identifiers_list(), true),
            'time_range' => is_array($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value['start'] ?? '') === 1 && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value['end'] ?? '') === 1,
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            default => false,
        };
        if (! $ok) {
            throw new InvalidSettingValueException("Invalid value for setting [$key].");
        }
        return $value;
    }
}
