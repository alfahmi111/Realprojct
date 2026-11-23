<?php

namespace Tests\Feature\Screens;

use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REQ-0271, REQ-0494 — the journey of every role through the screens on a realistic office (the demo office's data):
 * sign in, land on the role's home, open every item of its menu and every "add" screen it is allowed, and meet no error
 * page, no empty page, no screen of another role. PROJECT_LOCK د-3: a service with no screen is not done — this walk
 * is what finds such gaps.
 */
class RoleJourneysTest extends TestCase
{
    use RefreshDatabase;

    private Subscriber $s;

    protected function setUp(): void
    {
        parent::setUp();
        $env = $this->app['env'];
        $this->app['env'] = 'local';                 // DemoSeeder refuses to run anywhere but locally
        $this->seed(DemoSeeder::class);
        $this->app['env'] = $env;
        $this->tenant()->clear();
        $this->s = $this->tenant()->run(null, fn () => Subscriber::where('name_ar', 'مكتب الديار لإدارة الأملاك')->firstOrFail());
    }

    private function user(string $role): User
    {
        $u = $this->tenant()->run($this->s->id, fn () => User::where('subscriber_id', $this->s->id)->where('role', $role)->firstOrFail());
        if ($u->role->requiresTwoFactor() && ! $u->app_authentication_secret) {
            $this->tenant()->run($this->s->id, fn () => $u->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP'));
        }
        return $u;
    }

    /** Every navigation URL of a panel as this user sees it, plus the "create" page of each resource they may create. */
    private function walk(User $user, string $panelId): array
    {
        $panel = Filament::getPanel($panelId);
        Filament::setCurrentPanel($panel);
        $this->actingAs($user);
        $this->tenant()->set($user->subscriber_id);
        $urls = [];
        foreach ($panel->getNavigation() as $group) {
            foreach ($group->getItems() as $item) {
                /** @var NavigationItem $item */
                if ($item->isVisible()) {
                    $urls[$item->getLabel()] = $item->getUrl();
                }
            }
        }
        foreach ($panel->getResources() as $resource) {
            if ($resource::canAccess() && $resource::canCreate() && $resource::hasPage('create')) {
                $urls['إضافة: ' . $resource::getModelLabel()] = $resource::getUrl('create');
            }
        }
        return $urls;
    }

    private function journey(string $role, string $panelId, string $homePath, array $mustSee): void
    {
        $user = $this->user($role);
        // As the sign-in does (TenantUserProvider): the request runs inside the user's office.
        $this->tenant()->set($user->subscriber_id);
        $root = $this->actingAs($user)->get('/' . Filament::getPanel($panelId)->getPath());
        $this->assertTrue($root->isOk() || $root->isRedirect(), "$role: the interface root opens");
        $home = $this->actingAs($user)->get($homePath)->assertOk()->getContent();
        foreach ($mustSee as $needle) {
            $this->assertStringContainsString($needle, $home, "{$role} home shows «{$needle}»");
        }

        $urls = $this->walk($user, $panelId);
        $this->assertNotEmpty($urls, "$role has a menu");
        $failures = [];
        foreach ($urls as $label => $url) {
            $response = $this->actingAs($user)->get($url);
            $html = (string) $response->getContent();
            if (! $response->isOk()) {
                $failures[] = "$label → HTTP {$response->getStatusCode()} ($url)";
            } elseif (! str_contains($html, 'fi-main') || mb_strlen(strip_tags($html)) < 200) {
                $failures[] = "$label → empty page ($url)";
            }
        }
        $this->assertSame([], $failures, "$role journey:\n" . implode("\n", $failures));

        // Never another role's interface.
        foreach (['manage', 'owner', 'tenant', 'tech', 'platform'] as $path) {
            if ($path !== Filament::getPanel($panelId)->getPath()) {
                $other = $this->actingAs($user)->get("/$path");
                $this->assertFalse($other->isOk() && str_contains((string) $other->getContent(), 'fi-main'), "$role must not enter /$path");
            }
        }
    }

    public function test_req_0271_manager_journey(): void
    {
        $this->journey('manager', 'manager', '/manage', ['نسبة الإشغال', 'واجهة المبنى اليوم', 'جديد', 'مكتب الديار']);
    }

    public function test_req_0271_accountant_journey(): void
    {
        $this->journey('accountant', 'manager', '/manage', ['مكتب الديار']);
    }

    public function test_req_0271_technician_journey(): void
    {
        $this->journey('technician', 'technician', '/tech/tasks', ['مهام']);
    }

    public function test_req_0271_owner_journey(): void
    {
        $this->journey('owner', 'owner', '/owner/statement', ['كشف الحساب', 'صافي المحوَّل لك']);
    }

    public function test_req_0271_tenant_journey(): void
    {
        $this->journey('tenant', 'tenant', '/tenant/my-unit', ['مكتب الديار']);
    }

    public function test_req_0271_platform_admin_journey(): void
    {
        $admin = $this->tenant()->run(null, function () {
            $this->tenant()->setPlatform(true);
            return User::where('role', 'platform_admin')->firstOrFail();
        });
        $this->assertNotNull($admin);
        $home = $this->actingAs($admin)->get('/platform')->assertOk()->getContent();
        $this->assertStringContainsString('لوحة المنصة', $home);
        $urls = $this->walk($admin, 'platform');
        $urls['صفحة المشترك'] = \App\Filament\Platform\Resources\Subscribers\SubscriberResource::getUrl('view', ['record' => $this->s]);
        $failures = [];
        foreach ($urls as $label => $url) {
            $r = $this->actingAs($admin)->get($url);
            if (! $r->isOk()) {
                $failures[] = "$label → HTTP {$r->getStatusCode()}";
            }
        }
        $this->assertSame([], $failures, implode("\n", $failures));
    }
}
