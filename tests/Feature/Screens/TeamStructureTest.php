<?php

namespace Tests\Feature\Screens;

use App\Filament\Manager\Pages\TeamStructurePage;
use App\Filament\Platform\Resources\Subscribers\Pages\ViewSubscriber;
use App\Models\AuditLog;
use App\Models\Subscriber;
use App\Models\User;
use App\Organization\TeamStructure;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The office's organisation chart (owner's request 2026-11-23; roles REQ-0057…REQ-0063): departments by role, who
 * reports to whom, drag-and-drop or "نقل" moves with safety rules, owners and tenants kept outside; the platform home
 * with states, attention list and readiness (REQ-0323).
 */
class TeamStructureTest extends TestCase
{
    use RefreshDatabase;

    private Subscriber $s;
    private User $manager;
    private User $accountant;
    private User $supervisor;
    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSubscriber('office', 'مكتب السنابل');
        $this->manager = $this->makeUser($this->s, 'manager', ['name' => 'المدير']);
        $this->accountant = $this->makeUser($this->s, 'accountant', ['name' => 'المحاسب']);
        $this->supervisor = $this->makeUser($this->s, 'maintenance_supervisor', ['name' => 'المشرف']);
        $this->tech = $this->makeUser($this->s, 'technician', ['name' => 'الفني']);
        $this->makeUser($this->s, 'tenant', ['name' => 'مستأجر']);
        $this->tenant()->set($this->s->id);
    }

    private function team(): TeamStructure
    {
        return app(TeamStructure::class);
    }

    public function test_the_chart_puts_staff_under_the_manager_by_department_and_keeps_portal_accounts_apart(): void
    {
        $t = $this->team()->tree($this->s->id);
        $this->assertCount(1, $t['roots']);
        $root = $t['roots'][0];
        $this->assertSame([$this->manager->id, 'الإدارة'], [$root['id'], $root['department']]);
        $this->assertSame(['المالية', 'الصيانة', 'الصيانة'], array_column($root['children'], 'department'));
        $this->assertTrue($root['children'][0]['implied'], 'no line saved yet: shown under the first manager');
        $this->assertSame(['مستأجر'], array_column($t['external'], 'name'));
        $this->assertSame(4, $t['count']);
    }

    public function test_moves_follow_the_rules_and_are_audited(): void
    {
        $this->team()->move($this->s->id, $this->tech->id, $this->supervisor->id);
        $tree = $this->team()->tree($this->s->id)['roots'][0];
        $sup = collect($tree['children'])->firstWhere('id', $this->supervisor->id);
        $this->assertSame([$this->tech->id], array_column($sup['children'], 'id'));
        $this->assertSame(1, AuditLog::where('action', 'team.moved')->count());

        $refused = [
            [$this->manager->id, $this->tech->id, 'الصلاحية أدنى'],            // nobody reports to lower authority
            [$this->supervisor->id, $this->tech->id, 'الصلاحية أدنى'],
            [$this->tech->id, $this->tech->id, 'لنفسه'],
            [$this->tech->id, null, 'القمة للمديرين'],
        ];
        foreach ($refused as [$who, $to, $msg]) {
            try {
                $this->team()->move($this->s->id, $who, $to);
                $this->fail("move $who → " . var_export($to, true) . ' should be refused');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }
        // A loop: the supervisor cannot report to someone already below them.
        $this->team()->move($this->s->id, $this->accountant->id, $this->supervisor->id);
        $this->expectExceptionMessage('يتبع أصلاً');
        $this->team()->move($this->s->id, $this->supervisor->id, $this->accountant->id);
    }

    public function test_another_office_is_out_of_reach(): void
    {
        $other = $this->makeSubscriber('office', 'آخر');
        $stranger = $this->makeUser($other, 'manager');
        $this->expectException(InvalidArgumentException::class);
        $this->team()->move($this->s->id, $this->tech->id, $stranger->id);
    }

    public function test_the_move_form_offers_only_valid_managers(): void
    {
        $this->team()->move($this->s->id, $this->tech->id, $this->supervisor->id);
        $options = $this->team()->possibleManagers($this->s->id, $this->supervisor->id);
        $this->assertArrayHasKey($this->manager->id, $options);
        $this->assertArrayHasKey($this->accountant->id, $options);
        $this->assertArrayNotHasKey($this->tech->id, $options, 'below in authority and in the line');
    }

    public function test_the_office_manager_moves_people_on_the_team_page(): void
    {
        $this->manager->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        Filament::setCurrentPanel(Filament::getPanel('manager'));
        $this->actingAs($this->manager)->get('/manage/team')->assertOk()->assertSee('الهيكل التنظيمي')->assertSee('المشرف')->assertSee('الصيانة');
        Livewire::actingAs($this->manager)->test(TeamStructurePage::class)->call('moveUser', $this->tech->id, $this->supervisor->id)->assertNotified('نُقل الموظف في الهيكل التنظيمي');
        Livewire::actingAs($this->manager)->test(TeamStructurePage::class)
            ->callAction(TestAction::make('moveUser')->arguments(['user' => $this->tech->id]), ['manager' => $this->manager->id])->assertHasNoFormErrors();
        $this->assertSame($this->manager->id, User::find($this->tech->id)->reports_to_id);

        $tech = $this->tech;
        $tech->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($tech)->get('/manage/team')->assertForbidden();
    }

    public function test_the_platform_admin_sees_the_chart_and_the_home_dashboard(): void
    {
        $this->tenant()->clear();
        $this->tenant()->setPlatform(true);
        $admin = User::factory()->create(['subscriber_id' => null, 'role' => 'platform_admin']);
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->s->forceFill(['subscription_ends_on' => now()->addDays(5)->toDateString(), 'billing_cycle' => 'annual', 'price_minor' => 1200000])->save();

        Livewire::actingAs($admin)->test(ViewSubscriber::class, ['record' => $this->s->id])->call('moveUser', $this->tech->id, $this->supervisor->id);
        $this->assertSame($this->supervisor->id, $this->tenant()->run($this->s->id, fn () => User::find($this->tech->id))->reports_to_id);

        $html = $this->actingAs($admin)->get('/platform')->assertOk()->getContent();
        foreach (['لوحة المنصة', 'المشتركون', 'يحتاج انتباهك', 'مكتب السنابل', 'ينتهي خلال 5 يوماً', 'الباقات', 'جاهزية المنصة', 'أحدث المشتركين'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString(\App\Support\Dates\NumberFormatter::money(100000), $html, '12,000 a year = 1,000 a month');
    }
}
