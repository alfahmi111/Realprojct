<?php

namespace Tests\Feature\Migration;

use App\Integrations\DocumentReader\DocumentReaderGateway;
use App\Integrations\IntegrationNotConfigured;
use App\Migration\DataExport;
use App\Migration\MigrationException;
use App\Migration\QuickMigration;
use App\Models\ImportBatch;
use App\Models\Lease;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\RentEscalation;
use App\Models\Tenant;
use App\Models\User;
use App\Properties\BuildingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * Wizard step 0 and data ownership. Covered IDs:
 * REQ-0122, REQ-0047, REQ-0572, REQ-0663 (quick migration, nothing saved without confirmation),
 * REQ-0469, REQ-0484 (document reader layer, processing location pending), REQ-0498, REQ-0586 (full export, open formats).
 */
class ImportExportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->makeSubscriber();
        $this->manager = $this->makeUser($s, 'manager');
        $this->tenant()->set($s->id);
        $w = app(BuildingWizard::class);
        $o = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك', 'national_id' => '1010101010']);
        $p = $w->createProperty(Portfolio::create(['name_ar' => 'م']), ['name_ar' => 'ع'], [['owner' => $o, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $w->generate($p, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 1]]]]]);
    }

    private function fakeReader(array $fields): void
    {
        $this->app->instance(DocumentReaderGateway::class, new class($fields) implements DocumentReaderGateway {
            public function __construct(private array $fields)
            {
            }

            public function extract(string $path, string $kind): array
            {
                return $this->fields;
            }
        });
        $this->app->forgetInstance(QuickMigration::class);
    }

    public function test_req_0122_extracted_fields_are_staged_with_confidence_and_nothing_is_saved_before_confirmation(): void
    {
        $this->fakeReader([
            ['field' => 'tenant_name', 'value' => 'فهد', 'confidence' => 97], ['field' => 'annual_rent', 'value' => '36000', 'confidence' => 62],
            ['field' => 'ejar_contract_number', 'value' => '20201234', 'confidence' => 88],
        ]);
        $m = app(QuickMigration::class);
        $batch = $m->stage($this->manager, 'uploads/contract.pdf', 'ejar_pdf');
        $this->assertSame(0, Tenant::count());
        $this->assertSame(0, Lease::count());
        $this->assertSame('annual_rent', $m->review($batch)[0]['field']);   // lowest confidence first for review

        $lease = $m->confirm($batch, $this->manager, [
            'tenant_name' => 'فهد', 'unit_code' => '101', 'usage' => 'residential', 'starts_on' => '2026-03-01', 'ends_on' => '2027-02-28',
            'annual_rent' => '36500', 'payment_frequency' => 'semiannual', 'ejar_contract_number' => '20201234',
            'escalations' => [['kind' => 'percentage', 'percentage_bp' => 300, 'contract_year' => 2]],
        ]);
        $this->assertSame('registered', $lease->status);
        $this->assertSame(3650000, $lease->annual_rent_minor->minor());   // the manager's corrected value
        $this->assertCount(2, $lease->scheduleLines);
        $this->assertSame(1, RentEscalation::count());
        $this->assertSame('confirmed', $batch->fresh()->status);
        $this->expectException(MigrationException::class);
        $m->confirm($batch->fresh(), $this->manager, []);
    }

    public function test_req_0122_a_discarded_batch_saves_nothing(): void
    {
        $this->fakeReader([['field' => 'tenant_name', 'value' => 'x', 'confidence' => 50]]);
        $m = app(QuickMigration::class);
        $b = $m->stage($this->manager, 'uploads/a.xlsx', 'excel');
        $m->discard($b);
        $this->assertSame(0, Tenant::count());
        $this->expectException(MigrationException::class);
        $m->confirm($b->fresh(), $this->manager, ['tenant_name' => 'x']);
    }

    public function test_req_0484_document_reading_stays_off_until_processing_location_is_decided(): void
    {
        $this->expectException(IntegrationNotConfigured::class);
        app(QuickMigration::class)->stage($this->manager, 'uploads/contract.pdf', 'ejar_pdf');
    }

    public function test_req_0498_full_export_in_open_formats_with_only_own_data(): void
    {
        $other = $this->makeSubscriber();
        $this->tenant()->run($other->id, fn () => Owner::create(['kind' => 'individual', 'name_ar' => 'مالك آخر']));
        $this->tenant()->set($this->manager->subscriber_id);
        $path = app(DataExport::class)->export();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        foreach (['leases.csv', 'payments.csv', 'assets.csv', 'inventory_counts.csv', 'owners.csv', 'README.txt'] as $f) {
            $this->assertNotFalse($zip->locateName($f), $f);
        }
        $owners = $zip->getFromName('owners.csv');
        $zip->close();
        unlink($path);
        $this->assertStringContainsString('1010101010', $owners);   // decrypted for the data controller
        $this->assertStringNotContainsString('مالك آخر', $owners);  // RLS: other subscribers excluded
    }

    public function test_req_0586_export_route_is_one_click_for_the_manager_and_forbidden_to_others(): void
    {
        $tenantUser = $this->makeUser(\App\Models\Subscriber::find($this->manager->subscriber_id), 'tenant');
        $this->actingAs($tenantUser)->get('/export')->assertForbidden();
        $this->actingAs($this->manager)->get('/export')->assertForbidden();   // review M18: no export before two-factor is enrolled
        $this->manager->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($this->manager->fresh())->get('/export')->assertOk()->assertHeader('content-disposition');
    }
}
