<?php

namespace App\Integrations\Ejar;

/**
 * Intermediary layer for Ejar (REQ-0463, REQ-0489). Two implementations: manual-assisted now (REQ-0461) and
 * automatic later (REQ-0462, decision 12 — BLOCKED). The rest of the system never talks to Ejar directly.
 */
interface EjarGateway
{
    /** Prepare the full contract data package for registration (manual: shown to the employee). */
    public function prepareRegistration(array $lease): array;

    /** Record the outcome reported for a contract: draft | sent | awaiting_parties | registered | expired (REQ-0459). */
    public function recordStatus(string $leaseId, string $status, ?string $ejarContractNumber = null): void;

    /** Contracts as exported by the subscriber from Ejar (CSV) for nightly reconciliation (REQ-0435). */
    public function importContracts(string $csvPath): array;

    public function mode(): string;
}
