<?php

namespace Tests\Feature\Foundation;

use App\Filament\Manager\Resources\Invoices\InvoiceResource;
use App\Filament\Manager\Resources\LeasableUnits\LeasableUnitResource;
use App\Filament\Manager\Resources\Leases\LeaseResource;
use App\Filament\Manager\Resources\Owners\OwnerResource;
use App\Filament\Manager\Resources\Properties\PropertyResource;
use App\Filament\Manager\Resources\Tenants\TenantResource;
use App\Filament\Manager\Resources\WorkOrders\WorkOrderResource;
use App\Models\LeasableUnit;
use App\Models\Lease;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserScope;
use App\Models\WorkOrder;
use App\Properties\BuildingWizard;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REQ-0494 (review M18 A2): a staff member limited to some properties sees only their records on every screen, and
 * cannot open another property's photo or work order media by URL. A manager with no scope rows sees everything.
 */
class PropertyScopeM19Test extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $officer;
    private Property $a;
    private Property $b;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->makeSubscriber();
        $this->manager = $this->makeUser($s, 'manager');
        $this->officer = $this->makeUser($s, 'leasing_officer');
        $this->tenant()->set($s->id);
        $w = app(BuildingWizard::class);
        [$this->a, $this->b] = array_map(function ($name) use ($w) {
            $o = Owner::create(['kind' => 'individual', 'name_ar' => "مالك $name"]);
            $p = $w->createProperty(Portfolio::create(['name_ar' => "م $name"]), ['name_ar' => $name], [['owner' => $o, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
            $w->generate($p, ['name_ar' => $name, 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 1]]]]]);
            $unit = LeasableUnit::where('property_id', $p->id)->first();
            $t = Tenant::create(['kind' => 'individual', 'name_ar' => "مستأجر $name"]);
            $l = Lease::create(['leasable_unit_id' => $unit->id, 'tenant_id' => $t->id, 'kind' => 'lease', 'usage' => 'residential', 'track' => 'long',
                'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'annual_rent_minor' => Money::parse('12000'), 'payment_frequency' => 'monthly', 'deposit_minor' => Money::zero()]);
            app(\App\Maintenance\MaintenanceService::class)->report('corrective', $p->id, "بلاغ $name", 'supervisor');
            return $p;
        }, ['أ', 'ب']);
        UserScope::create(['user_id' => $this->officer->id, 'scope_type' => 'property', 'scope_id' => $this->a->id]);
        Filament::setCurrentPanel(Filament::getPanel('manager'));
    }

    public function test_req_0494_scoped_staff_see_only_their_properties_records(): void
    {
        $this->actingAs($this->officer);
        $this->assertSame([$this->a->id], PropertyResource::getEloquentQuery()->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([$this->a->id], LeasableUnitResource::getEloquentQuery()->pluck('property_id')->map(fn ($v) => (int) $v)->unique()->values()->all());
        $this->assertSame(1, LeaseResource::getEloquentQuery()->count());
        $this->assertSame(['مستأجر أ'], TenantResource::getEloquentQuery()->pluck('name_ar')->all());
        $this->assertSame(['مالك أ'], OwnerResource::getEloquentQuery()->pluck('name_ar')->all());
        $this->assertSame(['بلاغ أ'], WorkOrderResource::getEloquentQuery()->pluck('description')->all());
        $this->assertSame(0, InvoiceResource::getEloquentQuery()->where('property_id', $this->b->id)->count());

        $this->actingAs($this->manager);   // no scope rows: the whole office
        $this->assertSame(2, PropertyResource::getEloquentQuery()->count());
        $this->assertSame(2, TenantResource::getEloquentQuery()->count());
    }

    public function test_req_0494_scoped_staff_cannot_open_another_propertys_work_order_media(): void
    {
        $wo = WorkOrder::where('property_id', $this->b->id)->first();
        $wo->forceFill(['photos_report' => ['x/none.jpg']])->save();
        $this->officer->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($this->officer)->get(route('media.work_order', [$wo->id, 'report', 0]))->assertForbidden();
    }
}
