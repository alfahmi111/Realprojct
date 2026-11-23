<?php

namespace App\Integrations\Identity;

/**
 * The identity layer, built once (REQ-0472): Nafath (REQ-0445, REQ-0471), Yaqeen (REQ-0446), Wathq (REQ-0447).
 * Real drivers are added when the owner contracts a licensed provider; until then UnconfiguredIdentity refuses.
 * $hintName is used only by the local simulator to emulate the official record; real drivers ignore it.
 */
interface IdentityGateway
{
    /** Start a Nafath approval: the person picks this number in the Nafath app. @return array{request_id:string, random:string} */
    public function nafathRequest(string $nationalId): array;

    /** @return array{status:string, name_ar:?string}  status: pending | approved | rejected | expired */
    public function nafathStatus(string $requestId, ?string $hintName = null): array;

    /** @return array{found:bool, name_ar:?string} */
    public function yaqeenPerson(string $nationalId, ?string $birthDate, ?string $hintName = null): array;

    /** @return array{found:bool, name_ar:?string, status:?string, expires_on:?string}  status: active | suspended | cancelled */
    public function wathqCompany(string $commercialRegistration, ?string $hintName = null): array;
}
