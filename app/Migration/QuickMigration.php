<?php

namespace App\Migration;

use App\Integrations\DocumentReader\DocumentReaderGateway;
use App\Leasing\LeasingService;
use App\Leasing\ScheduleGenerator;
use App\Models\ImportBatch;
use App\Models\LeasableUnit;
use App\Models\Lease;
use App\Models\RentEscalation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Quick migration with AI (wizard step 0, REQ-0122, REQ-0047, REQ-0572, REQ-0663): the manager uploads files as they
 * are (Ejar PDF contracts, Excel sheets, photos); fields are extracted through the document-reader layer (REQ-0469)
 * and shown each with a confidence score; NOTHING is saved before the manager confirms.
 * Processing location (in-Kingdom or with explicit consent) is an owner decision (REQ-0567) — the reader stays
 * unconfigured until then.
 */
class QuickMigration
{
    /** Fields of an Ejar contract that the review screen expects. */
    public const CONTRACT_FIELDS = ['ejar_contract_number', 'tenant_name', 'tenant_kind', 'tenant_national_id', 'tenant_phone', 'unit_code', 'usage', 'starts_on', 'ends_on', 'annual_rent', 'payment_frequency', 'deposit', 'escalations'];

    public function __construct(private DocumentReaderGateway $reader, private ScheduleGenerator $schedule, private LeasingService $leasing)
    {
    }

    /** Upload + extract → a staged batch. No business table is touched. */
    public function stage(User $by, string $path, string $kind): ImportBatch
    {
        if (! in_array($kind, ['ejar_pdf', 'excel', 'photo'], true)) {
            throw new MigrationException("Unsupported file kind [$kind].");
        }
        $fields = $this->reader->extract($path, $kind);
        foreach ($fields as $f) {
            if (! isset($f['field'], $f['confidence']) || $f['confidence'] < 0 || $f['confidence'] > 100) {
                throw new MigrationException('Every extracted field needs a confidence between 0 and 100.');
            }
        }
        return ImportBatch::create(['uploaded_by' => $by->id, 'kind' => $kind, 'file_path' => $path, 'extracted' => $fields]);
    }

    /**
     * A filled Excel template (owner request 2026-11-24): read by the platform itself, one staged batch per row, no AI
     * reader needed. Nothing is saved to business tables before each row is reviewed and confirmed.
     * @return list<ImportBatch>
     */
    public function stageTemplate(User $by, string $path): array
    {
        $batches = [];
        foreach (app(ExcelTemplate::class)->parse($path) as $row) {
            $batches[] = ImportBatch::create(['uploaded_by' => $by->id, 'kind' => 'excel', 'file_path' => $path, 'extracted' => $row['fields']]);
        }
        if (! $batches) {
            throw new MigrationException('لا عقود في الملف؛ أضف صفاً لكل عقد تحت العناوين.');
        }
        return $batches;
    }

    /** What the review screen shows: each field with its value and confidence, lowest confidence first. */
    public function review(ImportBatch $batch): array
    {
        $rows = $batch->extracted;
        usort($rows, fn ($a, $b) => $a['confidence'] <=> $b['confidence']);
        return $rows;
    }

    public function discard(ImportBatch $batch): void
    {
        $batch->forceFill(['status' => 'discarded'])->save();
    }

    /**
     * The manager confirms (possibly corrected) values → tenant + lease + schedule + escalations are created.
     * $values: field => value, after review. The unit must already exist (from the building wizard).
     */
    public function confirm(ImportBatch $batch, User $by, array $values): Lease
    {
        if ($batch->status !== 'staged') {
            throw new MigrationException('This batch is not awaiting confirmation.');
        }
        foreach (['tenant_name', 'unit_code', 'usage', 'starts_on', 'ends_on', 'annual_rent', 'payment_frequency'] as $req) {
            if (! isset($values[$req]) || $values[$req] === '') {
                throw new MigrationException("Missing confirmed value: $req");
            }
        }
        return DB::transaction(function () use ($batch, $by, $values) {
            $unit = LeasableUnit::whereNull('archived_at')->where('code', $values['unit_code'])->firstOrFail();
            $tenant = Tenant::create(['kind' => $values['tenant_kind'] ?? 'individual', 'name_ar' => $values['tenant_name'], 'national_id' => $values['tenant_national_id'] ?? null, 'phone' => $values['tenant_phone'] ?? null]);
            $start = CarbonImmutable::parse($values['starts_on']);
            $end = CarbonImmutable::parse($values['ends_on']);
            $lease = Lease::create([
                'leasable_unit_id' => $unit->id, 'tenant_id' => $tenant->id, 'usage' => $values['usage'], 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(),
                'annual_rent_minor' => Money::parse((string) $values['annual_rent']), 'payment_frequency' => $values['payment_frequency'],
                'deposit_minor' => isset($values['deposit']) ? Money::parse((string) $values['deposit']) : 0,
            ]);
            $this->leasing->occupy($unit, $start, $end, 'lease', leaseId: $lease->id);
            foreach ($values['escalations'] ?? [] as $e) {   // escalation clauses read from the Ejar contract (REQ-0190)
                RentEscalation::create(['lease_id' => $lease->id] + $e);
            }
            if (! empty($values['ejar_contract_number'])) {   // an imported registered contract keeps its Ejar number
                $lease->forceFill(['ejar_contract_number' => $values['ejar_contract_number'], 'status' => 'registered'])->save();
                $unit->forceFill(['last_registered_rent_minor' => $lease->annual_rent_minor])->save();
            }
            $this->schedule->generate($lease);
            $batch->forceFill(['status' => 'confirmed', 'reviewed' => $values, 'created_refs' => ['tenant_id' => $tenant->id, 'lease_id' => $lease->id]])->save();
            return $lease;
        });
    }
}
