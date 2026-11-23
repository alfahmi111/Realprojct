<?php

namespace App\Integrations\NationalAddress;

/**
 * Saudi National Address (SPL / سبل) — the address is mandatory on invoices and contracts and verified automatically
 * (REQ-0448, spec 9.5). Real driver needs the owner's SPL API subscription; until then UnconfiguredNationalAddress refuses.
 * Returned array: short (e.g. RRRD2929), building_no, street, district, city, postal_code, additional_no, latitude, longitude.
 */
interface NationalAddressGateway
{
    /** @return array<string,?string>|null  null when no address is registered at that point */
    public function byCoordinates(float $latitude, float $longitude): ?array;

    /** @return array<string,?string>|null  null when the short address does not exist */
    public function byShortAddress(string $short): ?array;
}
