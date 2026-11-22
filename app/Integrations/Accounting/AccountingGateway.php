<?php

namespace App\Integrations\Accounting;

/** Accounting export layer: Qoyod / Zoho / Odoo (REQ-0473, REQ-0056, REQ-0489). */
interface AccountingGateway
{
    /** Push journal entries; returns provider references per entry. */
    public function exportEntries(array $account, array $entries): array;

    public function system(): string;
}
