<?php

namespace App\Compliance;

/**
 * Saudi national address (REQ-0448): mandatory on invoices and contracts. Structural validation here; live
 * verification against SPL goes through an integration layer once an access route is chosen.
 * Fields: building_no (4 digits), street, district, city, postal_code (5 digits), additional_no (4 digits).
 */
final class NationalAddress
{
    public const REQUIRED = ['building_no', 'street', 'district', 'city', 'postal_code', 'additional_no'];

    /** @return string[] problems (empty = valid) */
    public static function problems(?array $a): array
    {
        if (! $a) {
            return ['national address is missing'];
        }
        $p = [];
        foreach (self::REQUIRED as $f) {
            if (! isset($a[$f]) || trim((string) $a[$f]) === '') {
                $p[] = "$f is required";
            }
        }
        if (isset($a['building_no']) && ! preg_match('/^\d{4}$/', (string) $a['building_no'])) {
            $p[] = 'building_no must be 4 digits';
        }
        if (isset($a['postal_code']) && ! preg_match('/^\d{5}$/', (string) $a['postal_code'])) {
            $p[] = 'postal_code must be 5 digits';
        }
        if (isset($a['additional_no']) && ! preg_match('/^\d{4}$/', (string) $a['additional_no'])) {
            $p[] = 'additional_no must be 4 digits';
        }
        return $p;
    }

    public static function isValid(?array $a): bool
    {
        return self::problems($a) === [];
    }
}
