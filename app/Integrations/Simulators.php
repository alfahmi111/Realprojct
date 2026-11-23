<?php

namespace App\Integrations;

use App\Integrations\Accounting\AccountingGateway;
use App\Integrations\DocumentReader\DocumentReaderGateway;
use App\Integrations\EInvoicing\EInvoicingGateway;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Sms\SmsGateway;
use App\Integrations\WhatsApp\WhatsAppGateway;
use Illuminate\Support\Str;
use LogicException;

/**
 * Deterministic simulators for automated tests only (decision 5: simulator for tests, real provider chosen by
 * the owner). They refuse to run in production. Every call is recorded in $calls for assertions.
 */
final class Simulators implements AccountingGateway, DocumentReaderGateway, EInvoicingGateway, PaymentGateway, SmsGateway, WhatsAppGateway
{
    public array $calls = [];

    public function __construct()
    {
        if (app()->environment('production')) {
            throw new LogicException('Integration simulators cannot run in production.');
        }
    }

    public function exportEntries(array $account, array $entries): array
    {
        $this->calls[] = ['accounting', $entries];
        return array_map(fn () => 'SIM-' . Str::upper(Str::random(8)), $entries);
    }

    public function system(): string
    {
        return 'simulator';
    }

    public function extract(string $path, string $kind): array
    {
        $this->calls[] = ['extract', $path, $kind];
        return [];
    }

    public function submit(array $invoice): array
    {
        $this->calls[] = ['einvoice', $invoice];
        return ['uuid' => (string) Str::uuid(), 'status' => ($invoice['type'] ?? 'b2c') === 'b2b' ? 'cleared' : 'reported', 'qr' => base64_encode('SIM')];
    }

    public function providerName(): string
    {
        return 'simulator';
    }

    public function createPaymentLink(array $merchant, int $amountMinor, string $currency, string $reference): array
    {
        if (empty($merchant['merchant_id'])) {
            throw new LogicException('A payment link needs the subscriber\'s own merchant account.');
        }
        $this->calls[] = ['payment_link', $merchant['merchant_id'], $amountMinor, $currency, $reference];
        return ['id' => 'SIMPAY-' . $reference, 'url' => 'https://pay.simulator.test/' . $reference, 'merchant_id' => $merchant['merchant_id']];
    }

    public function fetchPayment(array $merchant, string $paymentId): array
    {
        return ['id' => $paymentId, 'status' => 'paid', 'merchant_id' => $merchant['merchant_id'] ?? null];
    }

    public function send(array $account, string $to, string $text): string
    {
        $this->calls[] = ['sms', $to, $text];
        return 'SIMSMS-' . count($this->calls);
    }

    public function sendTemplate(array $account, string $to, string $template, string $language, array $variables): string
    {
        $this->calls[] = ['whatsapp', $to, $template, $language, $variables];
        return 'SIMWA-' . count($this->calls);
    }
}
