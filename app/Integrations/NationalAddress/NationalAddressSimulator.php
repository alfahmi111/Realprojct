<?php

namespace App\Integrations\NationalAddress;

use LogicException;

/**
 * Local stand-in for SPL (decision 5): derives a stable fake address from the coordinates so the map flow can be tried.
 * The values are clearly synthetic (street "شارع تجريبي") and the simulator refuses production.
 */
final class NationalAddressSimulator implements NationalAddressGateway
{
    public function __construct()
    {
        if (app()->environment('production')) {
            throw new LogicException('National address simulator cannot run in production.');
        }
    }

    public function byCoordinates(float $latitude, float $longitude): ?array
    {
        $h = crc32(sprintf('%.5f,%.5f', $latitude, $longitude));
        $letters = '';
        foreach ([0, 5, 10, 15] as $shift) {
            $letters .= chr(65 + (($h >> $shift) % 26));
        }
        $num = str_pad((string) (1000 + $h % 9000), 4, '0', STR_PAD_LEFT);
        $city = match (true) {
            abs($latitude - 21.5) < 0.6 && abs($longitude - 39.2) < 0.6 => 'جدة',
            abs($latitude - 24.7) < 0.6 && abs($longitude - 46.7) < 0.6 => 'الرياض',
            abs($latitude - 26.4) < 0.6 && abs($longitude - 50.1) < 0.6 => 'الدمام',
            default => 'مدينة تجريبية',
        };
        return ['short' => $letters . $num, 'building_no' => $num, 'street' => 'شارع تجريبي', 'district' => 'حي تجريبي', 'city' => $city,
            'postal_code' => (string) (10000 + $h % 89999), 'additional_no' => str_pad((string) ($h % 10000), 4, '0', STR_PAD_LEFT),
            'latitude' => (string) $latitude, 'longitude' => (string) $longitude];
    }

    public function byShortAddress(string $short): ?array
    {
        if (! preg_match('/^[A-Z]{4}\d{4}$/', $short)) {
            return null;
        }
        $h = crc32($short);
        return $this->byCoordinates(21.4 + ($h % 1000) / 10000, 39.1 + (($h >> 10) % 1000) / 10000) + ['short' => $short];
    }
}
