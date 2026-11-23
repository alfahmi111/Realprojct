<?php

namespace App\Integrations\Identity;

use App\Integrations\IntegrationNotConfigured;
use App\Integrations\Sadad\SadadGateway;

/** Bound while no licensed provider is contracted for Nafath, Yaqeen, Wathq or SADAD: every call fails loudly. */
final class UnconfiguredIdentity implements IdentityGateway, SadadGateway
{
    private function fail(string $what): never
    {
        throw new IntegrationNotConfigured("No provider is configured for [$what] — owner decision pending.");
    }

    public function nafathRequest(string $nationalId): array { $this->fail('nafath'); }
    public function nafathStatus(string $requestId, ?string $hintName = null): array { $this->fail('nafath'); }
    public function yaqeenPerson(string $nationalId, ?string $birthDate, ?string $hintName = null): array { $this->fail('yaqeen'); }
    public function wathqCompany(string $commercialRegistration, ?string $hintName = null): array { $this->fail('wathq'); }
    public function issueBill(array $invoice): array { $this->fail('sadad'); }
}
