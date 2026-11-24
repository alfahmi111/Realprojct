<?php

namespace App\Integrations\NationalAddress;

use App\Integrations\IntegrationNotConfigured;

/** Bound while no SPL subscription is configured: lookups refuse; the address is entered by hand meanwhile. */
final class UnconfiguredNationalAddress implements NationalAddressGateway
{
    public function byCoordinates(float $latitude, float $longitude): ?array
    {
        throw new IntegrationNotConfigured('لم تُربط خدمة العنوان الوطني (سبل) بعد.');
    }

    public function byShortAddress(string $short): ?array
    {
        throw new IntegrationNotConfigured('لم تُربط خدمة العنوان الوطني (سبل) بعد.');
    }
}
