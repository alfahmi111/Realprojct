<?php

namespace App\Integrations;

use App\Integrations\Accounting\AccountingGateway;
use App\Integrations\DocumentReader\DocumentReaderGateway;
use App\Integrations\EInvoicing\EInvoicingGateway;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Sms\SmsGateway;
use App\Integrations\WhatsApp\WhatsAppGateway;

/**
 * Bound while the owner has not chosen a provider (REQ-0565…REQ-0567). Every call fails loudly, so nothing
 * that depends on an unchosen provider can run in production (REQ-0559).
 */
final class UnconfiguredIntegration implements AccountingGateway, DocumentReaderGateway, EInvoicingGateway, PaymentGateway, SmsGateway, WhatsAppGateway
{
    public function __construct(private string $integration)
    {
    }

    private function fail(): never
    {
        throw new IntegrationNotConfigured("No provider is configured for [{$this->integration}] — owner decision pending.");
    }

    public function exportEntries(array $account, array $entries): array { $this->fail(); }
    public function system(): string { $this->fail(); }
    public function extract(string $path, string $kind): array { $this->fail(); }
    public function submit(array $invoice): array { $this->fail(); }
    public function providerName(): string { $this->fail(); }
    public function createPaymentLink(array $merchant, int $amountMinor, string $currency, string $reference): array { $this->fail(); }
    public function fetchPayment(array $merchant, string $paymentId): array { $this->fail(); }
    public function send(array $account, string $to, string $text): string { $this->fail(); }
    public function sendTemplate(array $account, string $to, string $template, string $language, array $variables): string { $this->fail(); }
}
