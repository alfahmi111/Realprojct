<?php

namespace Tests\Feature\Screens;

use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\User;
use App\Properties\BuildingWizard;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test of the manager interface screens (REQ-0271, REQ-0479): every listed page renders for a manager with
 * two-factor set up, in Arabic RTL, and records of other subscribers never appear (REQ-0169).
 */
class ManagerScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->makeSubscriber();
        $this->manager = $this->makeUser($s, 'manager');
        $this->tenant()->set($s->id);
        $this->manager->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');   // 2FA set up
        $w = app(BuildingWizard::class);
        $o = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك الاختبار']);
        $p = $w->createProperty(Portfolio::create(['name_ar' => 'محفظة']), ['name_ar' => 'عمارة الاختبار'], [['owner' => $o, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        $w->generate($p, ['name_ar' => 'A', 'floors' => [['number' => 1, 'units' => [['type' => 'apartment', 'count' => 2]]]]]);
        Filament::setCurrentPanel(Filament::getPanel('manager'));
    }

    public static function pages(): array
    {
        return array_map(fn ($p) => [$p], ['/manage', '/manage/kpis', '/manage/add-building', '/manage/properties', '/manage/owners', '/manage/owners/create', '/manage/tenants', '/manage/tenants/create', '/manage/vendors', '/manage/assets', '/manage/assets/create', '/manage/warranties', '/manage/ppm-plans', '/manage/announcements', '/manage/offer-approval-rules', '/manage/unit-type-asset-templates']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_req_0271_manager_screen_renders(string $url): void
    {
        $this->actingAs($this->manager)->get($url)->assertOk()->assertSee('dir="rtl"', false);
    }

    public function test_req_0169_property_list_shows_only_own_subscriber(): void
    {
        $other = $this->makeSubscriber();
        $this->tenant()->run($other->id, function () {
            $o = Owner::create(['kind' => 'individual', 'name_ar' => 'مالك غريب']);
            app(BuildingWizard::class)->createProperty(Portfolio::create(['name_ar' => 'x']), ['name_ar' => 'عقار غريب'], [['owner' => $o, 'numerator' => 1, 'denominator' => 1]], 'custom');
        });
        $this->actingAs($this->manager)->get('/manage/properties')->assertOk()->assertSee('عمارة الاختبار')->assertDontSee('عقار غريب');
    }
}
