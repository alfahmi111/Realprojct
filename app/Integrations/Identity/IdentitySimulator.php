<?php

namespace App\Integrations\Identity;

use App\Integrations\Sadad\SadadGateway;
use LogicException;

/**
 * Deterministic stand-in for local trials and tests (decision 5). Refuses production. Rules, by the last digits:
 *  - Yaqeen: id ending 0000 → not found; ending 9 → a different name (mismatch); otherwise the official name = hint.
 *  - Wathq: CR ending 0000 → not found; ending 9 → suspended; otherwise active for one more year.
 *  - Nafath: id ending 1111 → rejected; otherwise approved on the first status check.
 *  - SADAD: bill number derived from the invoice number.
 */
final class IdentitySimulator implements IdentityGateway, SadadGateway
{
    public array $calls = [];

    private array $requests = [];

    public function __construct()
    {
        if (app()->environment('production')) {
            throw new LogicException('Identity simulator cannot run in production.');
        }
    }

    public function nafathRequest(string $nationalId): array
    {
        $this->calls[] = ['nafath.request'];
        $id = 'NFD-' . substr(hash('sha256', $nationalId . microtime()), 0, 10);
        $this->requests[$id] = $nationalId;
        return ['request_id' => $id, 'random' => (string) (10 + (crc32($id) % 90))];
    }

    public function nafathStatus(string $requestId, ?string $hintName = null): array
    {
        $this->calls[] = ['nafath.status'];
        $nid = $this->requests[$requestId] ?? '';
        return str_ends_with($nid, '1111') ? ['status' => 'rejected', 'name_ar' => null] : ['status' => 'approved', 'name_ar' => $hintName];
    }

    public function yaqeenPerson(string $nationalId, ?string $birthDate, ?string $hintName = null): array
    {
        $this->calls[] = ['yaqeen'];
        return match (true) {
            str_ends_with($nationalId, '0000') => ['found' => false, 'name_ar' => null],
            str_ends_with($nationalId, '9') => ['found' => true, 'name_ar' => 'اسم مختلف في السجل'],
            default => ['found' => true, 'name_ar' => $hintName],
        };
    }

    public function wathqCompany(string $commercialRegistration, ?string $hintName = null): array
    {
        $this->calls[] = ['wathq'];
        return match (true) {
            str_ends_with($commercialRegistration, '0000') => ['found' => false, 'name_ar' => null, 'status' => null, 'expires_on' => null],
            str_ends_with($commercialRegistration, '9') => ['found' => true, 'name_ar' => $hintName, 'status' => 'suspended', 'expires_on' => now()->subMonth()->toDateString()],
            default => ['found' => true, 'name_ar' => $hintName, 'status' => 'active', 'expires_on' => now()->addYear()->toDateString()],
        };
    }

    public function issueBill(array $invoice): array
    {
        $this->calls[] = ['sadad', $invoice['number']];
        return ['bill_number' => str_pad(preg_replace('/\D/', '', $invoice['number']), 12, '0', STR_PAD_LEFT)];
    }
}
