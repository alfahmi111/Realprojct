<?php

namespace Tests\Feature\Screens;

use App\Dashboards\HomeOverview;
use App\Filament\Manager\Resources\Owners\Pages\EditOwner;
use App\Filament\Manager\Resources\Owners\RelationManagers\AgreementsRelationManager;
use App\Models\ManagementAgreement;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\Subscriber;
use App\Models\User;
use App\Properties\BuildingWizard;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * REQ-0315 — the manager home guides a new office through its setup, each step checked against real data.
 * REQ-0066, REQ-0067 — management agreements are entered from the owner's page (the statement needs one).
 */
class SetupAndAgreementsTest extends TestCase
{
    use RefreshDatabase;

    private Subscriber $s;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSubscriber();
        $this->manager = $this->makeUser($this->s, 'manager', ['name' => 'مدير المكتب']);
        $this->tenant()->set($this->s->id);
        $this->manager->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        Filament::setCurrentPanel(Filament::getPanel('manager'));
    }

    public function test_req_0315_a_new_office_sees_its_setup_steps_until_they_are_done(): void
    {
        $steps = collect(app(HomeOverview::class)->setupSteps($this->manager))->keyBy('key');
        $this->assertSame(['settings', 'owner', 'property', 'agreement', 'team', 'lease'], $steps->keys()->all());
        $this->assertFalse($steps->contains('done', true));

        $html = $this->actingAs($this->manager)->get('/manage')->assertOk()->getContent();
        $this->assertStringContainsString('جهّز مكتبك للعمل', $html);
        $this->assertStringContainsString('0 من 6 خطوات', $html);

        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك']);
        app(BuildingWizard::class)->createProperty(Portfolio::create(['name_ar' => 'أ']), ['name_ar' => 'عمارة'], [['owner' => $owner, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $steps = collect(app(HomeOverview::class)->setupSteps($this->manager))->keyBy('key');
        $this->assertTrue($steps['owner']['done']);
        $this->assertTrue($steps['property']['done']);
        $this->assertFalse($steps['agreement']['done']);
        $this->assertStringContainsString('2 من 6 خطوات', $this->actingAs($this->manager)->get('/manage')->getContent());
    }

    public function test_req_0315_a_technician_never_sees_office_setup_steps(): void
    {
        $tech = $this->makeUser($this->s, 'technician');
        $this->assertSame([], app(HomeOverview::class)->setupSteps($tech));
    }

    public function test_req_0066_manager_adds_a_management_agreement_from_the_owner_page(): void
    {
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك']);
        $p = app(BuildingWizard::class)->createProperty(Portfolio::create(['name_ar' => 'أ']), ['name_ar' => 'عمارة النخيل'], [['owner' => $owner, 'numerator' => 1, 'denominator' => 1]], 'residential_building');

        $this->actingAs($this->manager)->get("/manage/owners/{$owner->id}/edit")->assertOk()->assertSee('AgreementsRelationManager', false);
        Livewire::actingAs($this->manager)->test(AgreementsRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditOwner::class])
            ->callAction(\Filament\Actions\Testing\TestAction::make(CreateAction::class)->table(), [
                'fee_type' => 'mixed', 'fee_percentage' => '7.5', 'fee_fixed_minor' => '250', 'spend_cap_minor' => '1000',
                'collector' => 'manager', 'starts_on' => '2026-01-01', 'property_ids' => [$p->id],
            ])->assertHasNoFormErrors();

        $a = ManagementAgreement::firstOrFail();
        $this->assertSame(750, $a->fee_percentage_bp);
        $this->assertSame(25000, $a->fee_fixed_minor->minor());
        $this->assertSame(100000, $a->spend_cap_minor->minor());
        $this->assertSame([$p->id], $a->properties->pluck('id')->all());
        $this->assertSame('7.50% + ' . \App\Support\Dates\NumberFormatter::amount($a->fee_fixed_minor, 'western', 'abbreviation'), AgreementsRelationManager::feeText($a));
        $this->assertTrue(collect(app(HomeOverview::class)->setupSteps($this->manager))->firstWhere('key', 'agreement')['done']);
    }

    public function test_req_0067_a_self_agreement_has_no_fee(): void
    {
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك مدير']);
        $p = app(BuildingWizard::class)->createProperty(Portfolio::create(['name_ar' => 'ب']), ['name_ar' => 'فيلا'], [['owner' => $owner, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        Livewire::actingAs($this->manager)->test(AgreementsRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditOwner::class])
            ->callAction(\Filament\Actions\Testing\TestAction::make(CreateAction::class)->table(), [
                'is_self' => true, 'collector' => 'owner', 'starts_on' => '2026-01-01', 'property_ids' => [$p->id],
            ])->assertHasNoFormErrors();
        $a = ManagementAgreement::firstOrFail();
        $this->assertTrue($a->is_self);
        $this->assertSame(0, $a->fee_percentage_bp);
        $this->assertTrue($a->fee_fixed_minor->isZero());
    }
}
