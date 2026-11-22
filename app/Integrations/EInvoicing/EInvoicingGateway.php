<?php

namespace App\Integrations\EInvoicing;

/**
 * Certified e-invoicing provider layer (REQ-0423, REQ-0489, decision 5). The real provider is chosen by the
 * owner (REQ-0565); only a simulator exists, for tests.
 */
interface EInvoicingGateway
{
    /** Clear (B2B) or report (B2C) an invoice; returns ['uuid' => ..., 'status' => ..., 'qr' => ...]. */
    public function submit(array $invoice): array;

    public function providerName(): string;
}
