<?php

namespace Tests\Feature\Foundation;

use App\Integrations\Accounting\AccountingGateway;
use App\Integrations\DocumentReader\DocumentReaderGateway;
use App\Integrations\EInvoicing\EInvoicingGateway;
use App\Integrations\Ejar\EjarGateway;
use App\Integrations\Ejar\Events\EjarStatusRecorded;
use App\Integrations\IntegrationNotConfigured;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Simulators;
use App\Integrations\Sms\SmsGateway;
use App\Integrations\WhatsApp\WhatsAppGateway;
use App\Models\SubscriberIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * REQ-0489, REQ-0463 — one intermediary layer per external integration; EjarGateway with a manual implementation.
 * REQ-0647 — e-invoicing through a provider: only a test simulator exists, the provider is the owner's choice.
 * REQ-0436, REQ-0451, REQ-0449, REQ-0644 — the platform is a software provider and never touches money.
 * REQ-0372 — each subscriber holds its own integration accounts. REQ-0559 — nothing that depends on an
 * unchosen provider can run.
 */
class IntegrationsAndProviderLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACTS = [EInvoicingGateway::class, PaymentGateway::class, WhatsAppGateway::class, SmsGateway::class, AccountingGateway::class, DocumentReaderGateway::class];

    public function test_req_0489_every_integration_is_reached_only_through_its_gateway_interface(): void
    {
        foreach (self::CONTRACTS as $c) {
            $this->assertTrue(interface_exists($c));
            $this->assertInstanceOf($c, app($c));
        }
        $this->assertInstanceOf(EjarGateway::class, app(EjarGateway::class));
    }

    public function test_req_0559_unchosen_providers_fail_instead_of_running(): void
    {
        $this->expectException(IntegrationNotConfigured::class);
        app(EInvoicingGateway::class)->submit(['type' => 'b2c']);
    }

    public function test_req_0647_the_einvoicing_simulator_is_available_for_tests_only(): void
    {
        config(['integrations.einvoicing' => 'simulator']);
        $this->app->forgetInstance(EInvoicingGateway::class);
        $this->assertSame('simulator', app(EInvoicingGateway::class)->providerName());
        $this->assertSame('cleared', app(EInvoicingGateway::class)->submit(['type' => 'b2b'])['status']);

        $this->app['env'] = 'production';
        try {
            new Simulators();
            $this->fail('simulator ran in production');
        } catch (\LogicException) {
            $this->assertTrue(true);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_req_0463_ejar_manual_gateway_prepares_data_and_requires_the_contract_number(): void
    {
        Event::fake();
        $g = app(EjarGateway::class);
        $this->assertSame('manual', $g->mode());
        $this->assertSame(['unit' => 'A-101'], $g->prepareRegistration(['unit' => 'A-101'])['data']);
        $g->recordStatus('L1', 'awaiting_parties');
        Event::assertDispatched(EjarStatusRecorded::class, fn ($e) => $e->status === 'awaiting_parties');
        $this->expectException(InvalidArgumentException::class);
        $g->recordStatus('L1', 'registered');   // no contract number
    }

    public function test_req_0463_ejar_csv_export_is_parsed_for_reconciliation(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'ejar');
        file_put_contents($csv, "\xEF\xBB\xBFرقم العقد,الحالة,القيمة\n10001,موثق,60000\n10002,منتهي,30000\n");
        $rows = app(EjarGateway::class)->importContracts($csv);
        unlink($csv);
        $this->assertCount(2, $rows);
        $this->assertSame('10001', $rows[0]['رقم العقد']);
        $this->assertSame('منتهي', $rows[1]['الحالة']);
    }

    public function test_req_0436_payment_links_use_the_subscribers_own_merchant_account_only(): void
    {
        config(['integrations.payments' => 'simulator']);
        $this->app->forgetInstance(PaymentGateway::class);
        $link = app(PaymentGateway::class)->createPaymentLink(['merchant_id' => 'OFFICE-123'], 600000, 'SAR', 'INV-1');
        $this->assertSame('OFFICE-123', $link['merchant_id']);
        $this->expectException(\LogicException::class);
        app(PaymentGateway::class)->createPaymentLink([], 600000, 'SAR', 'INV-2');   // no platform fallback account
    }

    public function test_req_0451_no_wallet_escrow_or_payout_structures_exist(): void
    {
        $tables = array_column(DB::select('select table_name from information_schema.tables where table_schema = current_schema()'), 'table_name');
        foreach ($tables as $t) {
            $this->assertDoesNotMatchRegularExpression('/wallet|escrow|payout|settlement_account|platform_balance/i', $t);
        }
        $this->assertFalse(config('services.platform_merchant', false));
    }

    public function test_req_0372_integration_credentials_belong_to_the_subscriber_and_are_encrypted(): void
    {
        $s = $this->makeSubscriber();
        $this->tenant()->set($s->id);
        $i = SubscriberIntegration::create(['integration' => 'payments', 'account_holder_name' => 'مكتب', 'credentials' => ['merchant_id' => 'M-1', 'secret' => 'abc']]);
        $raw = DB::table('subscriber_integrations')->where('id', $i->id)->value('credentials');
        $this->assertStringNotContainsString('abc', $raw);
        $this->assertSame('M-1', $i->fresh()->credentials['merchant_id']);
        $audit = DB::table('audit_logs')->where('auditable_type', $i->getMorphClass())->value('after');
        $this->assertStringNotContainsString('abc', (string) $audit);

        $other = $this->makeSubscriber();
        $this->tenant()->set($other->id);
        $this->assertSame(0, SubscriberIntegration::count());   // RLS
    }
}
