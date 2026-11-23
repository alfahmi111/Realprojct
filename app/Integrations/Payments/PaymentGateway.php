<?php

namespace App\Integrations\Payments;

/**
 * Licensed payment gateway with a merchant account in the MANAGER's or OWNER's name (REQ-0436, REQ-0451).
 * The platform never receives funds: implementations only create payment links on the subscriber's own
 * merchant credentials and read back payment results for matching.
 */
interface PaymentGateway
{
    /** @param array $merchant the subscriber's own merchant credentials (never platform credentials) */
    public function createPaymentLink(array $merchant, int $amountMinor, string $currency, string $reference): array;

    public function fetchPayment(array $merchant, string $paymentId): array;
}
