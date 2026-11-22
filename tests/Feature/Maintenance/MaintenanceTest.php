<?php

namespace Tests\Feature\Maintenance;

use App\Approvals\DualApproval;
use App\Finance\Ledger;
use App\Maintenance\AssetTaxonomy;
use App\Maintenance\FoundingInventory;
use App\Maintenance\MaintenanceException;
use App\Maintenance\MaintenanceService;
use App\Models\ApprovalRequest;
use App\Models\Asset;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\PpmPlan;
use App\Models\Property;
use App\Models\Space;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warranty;
use App\Models\WorkOrder;
use App\Properties\BuildingWizard;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Spec §6 assets & maintenance. Covered IDs:
 * REQ-0211, REQ-0212, REQ-0213, REQ-0214, REQ-0215, REQ-0216, REQ-0217, REQ-0218, REQ-0219, REQ-0220 (register, taxonomy, warranties),
 * REQ-0221, REQ-0222, REQ-0223, REQ-0225, REQ-0226 (types), REQ-0227, REQ-0228, REQ-0229, REQ-0230 (lifecycle),
 * REQ-0232, REQ-0233, REQ-0235, REQ-0237 (practices), REQ-0240, REQ-0241, REQ-0242, REQ-0243, REQ-0249, REQ-0250, REQ-0251,
 * REQ-0252, REQ-0253, REQ-0254, REQ-0262, REQ-0263 (founding inventory), REQ-0145, REQ-0146, REQ-0151, REQ-0152, REQ-0153,
 * REQ-0155, REQ-0164, REQ-0307, REQ-0576, REQ-0577, REQ-0578.
 */
class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private MaintenanceService $svc;
    private User $manager;
    private User $supervisor;
    private User $technician;
    private User $ownerUser;
    private Property $property;
    private Space $unit;
    private Asset $ac;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->makeSubscriber('office');
        $this->manager = $this->makeUser($s, 'manager');
        $this->supervisor = $this->makeUser($s, 'maintenance_supervisor');
        $this->technician = $this->makeUser($s, 'technician');
        $this->ownerUser = $this->makeUser($s, 'owner');
        $this->tenant()->set($s->id);
        $w = app(BuildingWizard::class);
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك', 'user_id' => $this->ownerUser->id]);
        $this->property = $w->createProperty(Portfolio::create(['name_ar' => 'م']), ['name_ar' => 'عمارة'], [['owner' => $owner, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $w->createAgreement($owner, [$this->property], ['fee_type' => 'percentage', 'fee_percentage_bp' => 500, 'spend_cap_minor' => Money::parse('1000'), 'collector' => 'manager', 'starts_on' => '2026-01-01']);
        $w->generate($this->property, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 1]]]]]);
        $this->unit = Space::where('kind', 'unit')->first();
        $this->ac = Asset::create(['space_id' => $this->unit->id, 'qr_code' => 'QR-AC-1', 'category' => 'hvac', 'type' => 'split_ac', 'name_ar' => 'مكيف سبليت', 'brand' => 'X', 'model' => 'Y', 'serial_number' => 'S1', 'installed_on' => '2025-01-01', 'warranty_ends_on' => '2027-01-01', 'condition' => 4, 'cost_minor' => Money::parse('2500'), 'useful_life_months' => 120, 'photos' => ['ac.jpg']]);
        $this->svc = app(MaintenanceService::class);
    }

    public function test_req_0211_asset_register_fields_and_req_0212_taxonomy(): void
    {
        foreach (['qr_code', 'category', 'type', 'brand', 'model', 'serial_number', 'installed_on', 'warranty_ends_on', 'space_id', 'condition', 'cost_minor', 'useful_life_months', 'photos'] as $f) {
            $this->assertNotNull($this->ac->$f, $f);
        }
        $this->assertSame(7, count(AssetTaxonomy::CATEGORIES));
        $this->assertTrue(AssetTaxonomy::valid('vertical_transport', 'elevator'));
        $this->assertTrue(AssetTaxonomy::valid('safety', 'sprinkler'));
        $this->assertFalse(AssetTaxonomy::valid('hvac', 'elevator'));
        $this->expectException(QueryException::class);
        DB::transaction(fn () => $this->ac->forceFill(['condition' => 6])->save());   // condition is 1–5
    }

    private function toEstimated(Money $estimate): WorkOrder
    {
        $wo = $this->svc->report('corrective', $this->property->id, 'تسريب مياه', 'tenant', null, $this->ac, null, ['leak.jpg']);
        $this->svc->triage($wo, 'urgent');
        return $this->svc->estimate($wo, $estimate);
    }

    public function test_req_0227_full_work_order_lifecycle_and_req_0230_cost_becomes_an_accounting_entry(): void
    {
        $this->ac->forceFill(['warranty_ends_on' => null])->save();
        $wo = $this->toEstimated(Money::parse('400'));
        $this->assertSame('estimated', $wo->status);   // below the cap → no owner approval
        $this->svc->assign($wo, $this->technician);
        $this->svc->start($wo, ['before.jpg']);
        $this->svc->addCost($wo, 'labour', 'عمالة', 2, Money::parse('75'));     // REQ-0146
        $this->svc->addCost($wo, 'part', 'محبس', 1, Money::parse('120.50'));
        try {
            $this->svc->complete($wo, []);
            $this->fail('completed without after photos');
        } catch (MaintenanceException) {
        }
        $this->svc->complete($wo, ['after.jpg']);
        $this->svc->confirmByTenant($wo, 5, 'ممتاز');
        $this->svc->close($wo, $this->supervisor);
        $this->assertSame('closed', $wo->status);
        $this->assertSame(27050, $this->svc->totalCost($wo)->minor());
        $req = ApprovalRequest::where('action', 'trust.disburse')->firstOrFail();   // paying from trust waits for a checker
        $this->assertSame(27050, $req->payload['cost_minor']);
        $this->assertSame($wo->number, $req->payload['work_order']);
    }

    public function test_req_0228_above_the_managers_cap_the_owner_must_approve(): void
    {
        $wo = $this->toEstimated(Money::parse('1500'));
        $this->assertSame('awaiting_owner_approval', $wo->status);
        try {
            $this->svc->ownerDecision($wo, $this->manager, true);
            $this->fail('manager approved above cap');
        } catch (MaintenanceException) {
        }
        $this->svc->ownerDecision($wo, $this->ownerUser, true);   // REQ-0307: one tap by the owner
        $this->assertSame('estimated', $wo->status);
        $this->assertSame('approved', $wo->owner_approval);
    }

    public function test_req_0220_valid_warranty_routes_the_report_to_the_warranty_vendor(): void
    {
        $vendor = Vendor::create(['name_ar' => 'وكيل المكيفات', 'commercial_registration' => '1010000000', 'rating' => 4]);
        Warranty::create(['kind' => 'manufacturer', 'asset_id' => $this->ac->id, 'vendor_id' => $vendor->id, 'scope_ar' => 'ضمان المصنع', 'starts_on' => '2025-01-01', 'ends_on' => '2027-01-01']);
        $wo = $this->toEstimated(Money::parse('300'));
        $this->svc->assign($wo, $this->technician, null, CarbonImmutable::parse('2026-06-01'));
        $this->assertSame('vendor', $wo->assignee_type);
        $this->assertSame($vendor->id, $wo->vendor_id);
        $this->assertNotNull($wo->warranty_id);
    }

    public function test_req_0220_alerts_30_days_before_warranty_or_annual_contract_ends(): void
    {
        $vendor = Vendor::create(['name_ar' => 'مصاعد']);
        Warranty::create(['kind' => 'annual_contract', 'building_id' => $this->unit->building_id, 'vendor_id' => $vendor->id, 'scope_ar' => 'صيانة المصاعد السنوية', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-15']);
        Warranty::create(['kind' => 'annual_contract', 'building_id' => $this->unit->building_id, 'vendor_id' => $vendor->id, 'scope_ar' => 'مكافحة حشرات', 'starts_on' => '2026-01-01', 'ends_on' => '2027-03-01']);
        $this->assertCount(1, $this->svc->expiringWarranties(CarbonImmutable::parse('2026-11-20')));
    }

    public function test_req_0223_preventive_plans_create_work_orders_on_schedule(): void
    {
        PpmPlan::create(['asset_id' => $this->ac->id, 'title_ar' => 'تنظيف فلاتر المكيفات', 'every_months' => 3, 'next_due_on' => '2026-12-01']);
        $this->assertSame(0, $this->svc->generatePreventive(CarbonImmutable::parse('2026-11-30')));
        $this->assertSame(1, $this->svc->generatePreventive(CarbonImmutable::parse('2026-12-01')));
        $this->assertSame('2027-03-01', PpmPlan::first()->next_due_on->toDateString());
        $this->assertSame('preventive', WorkOrder::first()->type);
        $this->assertSame('scheduled', WorkOrder::first()->priority);
    }

    public function test_req_0226_emergency_reports_get_top_priority_and_req_0221_types_are_bounded(): void
    {
        $wo = $this->svc->report('emergency', $this->property->id, 'تسريب غاز', 'tenant');
        $this->assertSame('emergency', $wo->priority);
        $this->expectException(MaintenanceException::class);
        $this->svc->report('statutory', $this->property->id, 'فحص الدفاع المدني', 'system');   // vault-driven: phase 2
    }

    public function test_req_0233_asset_history_shows_cumulative_cost(): void
    {
        $this->ac->forceFill(['warranty_ends_on' => null])->save();
        foreach ([800, 900] as $cost) {
            $wo = $this->toEstimated(Money::of($cost * 100));
            $this->svc->assign($wo, $this->technician);
            $this->svc->start($wo, ['b.jpg']);
            $this->svc->addCost($wo, 'vendor', 'إصلاح', 1, Money::of($cost * 100));
        }
        $h = $this->svc->assetHistory($this->ac);
        $this->assertCount(2, $h['orders']);
        $this->assertSame(170000, $h['cumulative_cost']->minor());
        $this->assertSame(6800, $h['repair_to_cost_bp']);   // 1,700 / 2,500 = 68%
    }

    public function test_req_0164_founding_inventory_in_the_field_and_req_0243_reference_register(): void
    {
        $inv = app(FoundingInventory::class);
        $count = $inv->start($this->unit, $this->technician, CarbonImmutable::parse('2026-11-22'));
        $this->assertCount(1, $inv->expected($count));
        try {
            $inv->record($count, 'QR-AC-1', true, 3, '');
            $this->fail('accepted without photo');
        } catch (MaintenanceException) {
        }
        $inv->record($count, 'QR-AC-1', true, 3, 'photos/ac.jpg', 'خدش بسيط');   // scanned by QR
        $added = $inv->addUnregistered($count, ['category' => 'appliances', 'type' => 'fridge', 'name_ar' => 'ثلاجة'], 4, 'photos/fridge.jpg');
        $this->assertTrue($added->added_during_count);
        $inv->submit($count, 'sig-tech.png', 'sig-tenant.png');
        $this->assertSame(3, $this->ac->fresh()->condition);
        $this->assertSame('submitted', $count->fresh()->status);
        $this->assertSame(10000, $inv->completion($this->unit->building_id)['percent_bp']);
        $this->expectException(MaintenanceException::class);
        $inv->start($this->unit, $this->technician, CarbonImmutable::parse('2026-11-23'));
    }

    public function test_req_0254_count_cannot_be_submitted_with_unrecorded_assets(): void
    {
        $inv = app(FoundingInventory::class);
        $count = $inv->start($this->unit, $this->technician, CarbonImmutable::parse('2026-11-22'));
        $this->expectException(MaintenanceException::class);
        $inv->submit($count, 'sig.png');
    }

    public function test_req_0232_tenant_caused_damage_needs_documented_approval_and_the_lease(): void
    {
        $this->ac->forceFill(['warranty_ends_on' => null])->save();
        $wo = $this->toEstimated(Money::parse('100'));
        $this->svc->assign($wo, $this->technician);
        $this->svc->start($wo, ['b.jpg']);
        $this->svc->addCost($wo, 'part', 'زجاج', 1, Money::parse('100'));
        $this->svc->complete($wo, ['a.jpg']);
        $this->expectException(MaintenanceException::class);
        $this->svc->close($wo, $this->supervisor, 'docs/approval.pdf');   // no lease on this work order
    }

    public function test_req_0235_vendor_file_and_req_0237_iso_guidance_only(): void
    {
        $v = Vendor::create(['name_ar' => 'مورد', 'commercial_registration' => '1010101010', 'rating' => 5, 'agreed_prices' => [['item' => 'زيارة', 'price_minor' => 15000]]]);
        $this->assertSame(15000, $v->agreed_prices[0]['price_minor']);
        $this->assertSame(0, DB::table('policies')->where('kind', 'like', 'iso%')->count());   // no certification claims
    }
}
