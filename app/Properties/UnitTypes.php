<?php

namespace App\Properties;

use InvalidArgumentException;

/**
 * Unit types and their mandatory attributes, exactly as in spec §4.3 (REQ-0083…REQ-0095, REQ-0055, REQ-0078).
 * The tax note is informational only: tax is decided by usage + lessor registration + track (REQ-0420), never by
 * the unit type alone. Items marked "يُؤكَّد مع مستشار" are flagged NEEDS-PRO-REVIEW (rule R-19).
 */
final class UnitTypes
{
    /** attribute type: area | decimal | int | bool | text | enum:<a,b,c> */
    public const TYPES = [
        'apartment' => ['ar' => 'شقة', 'en' => 'Apartment', 'tax_note' => 'سكني: معفى من ضريبة القيمة المضافة', 'attributes' => [
            'area_sqm' => 'area', 'rooms' => 'int', 'bathrooms' => 'int', 'furnished' => 'bool', 'electricity_meter_no' => 'text', 'water_account_no' => 'text',
        ], 'req' => 'REQ-0084'],
        'villa' => ['ar' => 'فيلا', 'en' => 'Villa', 'tax_note' => 'سكني: معفى', 'attributes' => [
            'area_sqm' => 'area', 'floors' => 'int', 'rooms' => 'int', 'annex' => 'bool', 'pool' => 'bool', 'garden' => 'bool',
        ], 'req' => 'REQ-0085'],
        'shop' => ['ar' => 'محل تجاري', 'en' => 'Shop', 'tax_note' => 'تجاري: 15% إذا كان المؤجر مسجلاً', 'attributes' => [
            'area_sqm' => 'area', 'frontage_m' => 'decimal', 'permitted_activity' => 'text', 'municipal_license_no' => 'text',
        ], 'req' => 'REQ-0086'],
        'office' => ['ar' => 'مكتب', 'en' => 'Office', 'tax_note' => 'تجاري: 15%', 'attributes' => [
            'area_sqm' => 'area', 'furnished_equipped' => 'bool', 'parking_spaces' => 'int',
        ], 'req' => 'REQ-0087'],
        'whole_building' => ['ar' => 'عمارة كاملة', 'en' => 'Whole building', 'tax_note' => 'حسب الاستخدام', 'attributes' => [], 'composite' => true, 'req' => 'REQ-0088'],
        'warehouse' => ['ar' => 'مستودع', 'en' => 'Warehouse', 'tax_note' => 'تجاري: 15%', 'attributes' => [
            'area_sqm' => 'area', 'height_m' => 'decimal', 'floor_type' => 'text', 'loading_door' => 'bool', 'three_phase_power' => 'bool',
        ], 'req' => 'REQ-0089'],
        'kiosk' => ['ar' => 'كشك / وحدة صغيرة', 'en' => 'Kiosk / small unit', 'tax_note' => 'تجاري: 15%', 'attributes' => [
            'area_sqm' => 'area', 'location_description' => 'text', 'permitted_activity' => 'text',
        ], 'req' => 'REQ-0090'],
        'parking' => ['ar' => 'موقف', 'en' => 'Parking', 'tax_note' => 'يتبع العقد الأصلي إن أُجّر معه، وإلا تجاري', 'attributes' => [
            'number' => 'text', 'covered' => 'bool', 'dedicated' => 'bool',
        ], 'req' => 'REQ-0091'],
        'billboard' => ['ar' => 'لوحة إعلانية', 'en' => 'Billboard', 'tax_note' => 'تجاري: 15%', 'attributes' => [
            'dimensions' => 'text', 'placement' => 'enum:roof,facade', 'illuminated' => 'bool', 'municipal_permit_no' => 'text',
        ], 'req' => 'REQ-0092'],
        'atm_tower' => ['ar' => 'صرّاف آلي / برج اتصالات', 'en' => 'ATM / telecom tower', 'tax_note' => 'تجاري: 15%', 'attributes' => [
            'location_description' => 'text', 'occupied_area_sqm' => 'area', 'power_consumption' => 'text', 'lessee_entity' => 'text',
        ], 'req' => 'REQ-0093'],
        'land' => ['ar' => 'أرض فضاء', 'en' => 'Vacant land', 'tax_note' => 'حسب الاستخدام — يُؤكَّد مع مستشار', 'attributes' => [
            'area_sqm' => 'area', 'deed_no' => 'text', 'permitted_use' => 'enum:residential,commercial,agricultural',
        ], 'needs_pro_review' => true, 'req' => 'REQ-0094'],
        'open_space' => ['ar' => 'مساحة مفتوحة', 'en' => 'Open space', 'tax_note' => 'تجاري: 15%', 'attributes' => [
            'area_sqm' => 'area', 'use' => 'enum:events,exhibitions,temporary_storage',
        ], 'req' => 'REQ-0095'],
        // Tree node type from §4.2 (no mandatory attributes listed in §4.3).
        'room' => ['ar' => 'غرفة', 'en' => 'Room', 'tax_note' => null, 'attributes' => [], 'req' => 'REQ-0078'],
    ];

    public static function keys(): array
    {
        return array_keys(self::TYPES);
    }

    public static function get(string $type): array
    {
        return self::TYPES[$type] ?? throw new InvalidArgumentException("Unknown unit type [$type].");
    }

    public static function isComposite(string $type): bool
    {
        return (bool) (self::get($type)['composite'] ?? false);
    }

    /**
     * Validate the mandatory attributes of a unit type. Every listed attribute is required (§4.3 "الخصائص الإلزامية").
     * Areas/decimals are kept as strings with up to two decimals — never floats.
     *
     * @return array normalised attributes
     */
    public static function validate(string $type, array $attributes): array
    {
        $def = self::get($type);
        $out = [];
        $errors = [];
        foreach ($def['attributes'] as $name => $kind) {
            if (! array_key_exists($name, $attributes) || $attributes[$name] === null || $attributes[$name] === '') {
                $errors[] = "$name is required";
                continue;
            }
            $v = $attributes[$name];
            $ok = match (true) {
                $kind === 'area', $kind === 'decimal' => is_string($v) || is_int($v) ? preg_match('/^\d{1,9}(\.\d{1,2})?$/', (string) $v) === 1 : false,
                $kind === 'int' => is_int($v) && $v >= 0,
                $kind === 'bool' => is_bool($v),
                $kind === 'text' => is_string($v) && mb_strlen(trim($v)) > 0 && mb_strlen($v) <= 255,
                str_starts_with($kind, 'enum:') => in_array($v, explode(',', substr($kind, 5)), true),
                default => false,
            };
            if (! $ok) {
                $errors[] = "$name is invalid";
                continue;
            }
            $out[$name] = in_array($kind, ['area', 'decimal'], true) ? (string) $v : $v;
        }
        if ($errors) {
            throw new InvalidUnitAttributes(self::get($type)['en'] . ': ' . implode(', ', $errors));
        }
        return $out;
    }

    /** Area in hundredths of m² as an integer — for allocations by area without floats. */
    public static function areaHundredths(string $area): int
    {
        [$i, $f] = array_pad(explode('.', $area), 2, '');
        return (int) $i * 100 + (int) str_pad($f, 2, '0');
    }
}
