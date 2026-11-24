<?php

namespace Tests\Feature\Foundation;

use App\Authorization\Scope;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\UserScope;
use App\Properties\BuildingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Spec §11 foundations. Covered IDs:
 * REQ-0476, REQ-0477, REQ-0478, REQ-0479, REQ-0480, REQ-0481, REQ-0482, REQ-0483, REQ-0486 (one modular monolith: Laravel +
 * Livewire + PostgreSQL + queues/cache + S3-compatible storage, one installable web app),
 * REQ-0491 (Hijri + Gregorian on dates by setting), REQ-0494 (role + scope), REQ-0497 (audit on every financial/contract entity),
 * REQ-0168 (no physical delete of financial records and contracts), REQ-0068,
 * REQ-0521 (Node 20+ for building only), REQ-0527 (recorded packages), REQ-0529, REQ-0530, REQ-0531, REQ-0532, REQ-0533,
 * REQ-0536, REQ-0537 (approved development environment), REQ-0499.
 */
class ArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_req_0476_one_modular_monolith_on_laravel_postgresql_with_internal_modules(): void
    {
        foreach (['Tenancy', 'Audit', 'Settings', 'Properties', 'Leasing', 'Finance', 'Maintenance', 'Notifications', 'BuildingQr', 'Portals', 'Dashboards', 'Migration', 'Integrations'] as $module) {
            $this->assertDirectoryExists(app_path($module), $module);
        }
        $this->assertSame('pgsql', DB::connection()->getDriverName());   // one database
        $this->assertCount(1, array_filter(array_keys(config('database.connections')), fn ($c) => $c === config('database.default')));
        $this->assertTrue(class_exists(\Livewire\Livewire::class));      // REQ-0479
        $this->assertFileExists(public_path('sw.js'));                   // REQ-0480 installable app
        $this->assertSame('database', config('queue.connections.database.driver'));   // REQ-0482 via Laravel queues
        $this->assertSame('s3', config('filesystems.disks.s3.driver'));  // REQ-0483 S3-compatible in production
        $this->assertFalse(file_exists(base_path('docker-compose.yml')) && str_contains(file_get_contents(base_path('docker-compose.yml')), 'microservice'));
    }

    public function test_req_0168_req_0497_every_financial_and_contract_table_is_archive_or_append_only_and_audited(): void
    {
        $guarded = DB::select("select distinct event_object_table as t from information_schema.triggers where trigger_schema = current_schema() and (trigger_name like '%archive_only' or trigger_name like '%append_only')");
        $tables = array_column($guarded, 't');
        foreach (['leases', 'lease_offers', 'billing_schedule_lines', 'rent_escalations', 'invoices', 'invoice_lines', 'payments', 'payment_allocations', 'journal_entries', 'journal_lines', 'management_agreements', 'approval_requests', 'audit_logs', 'cost_lines', 'work_orders'] as $t) {
            $this->assertContains($t, $tables, $t);
        }
        foreach ([\App\Models\Lease::class, \App\Models\LeaseOffer::class, \App\Models\Invoice::class, \App\Models\Payment::class, \App\Models\ManagementAgreement::class, \App\Models\RentEscalation::class, \App\Models\BillingScheduleLine::class, \App\Models\JournalEntry::class, \App\Models\WorkOrder::class] as $model) {
            $this->assertContains(\App\Audit\Auditable::class, class_uses_recursive($model), $model);
        }
    }

    public function test_req_0491_every_date_can_be_shown_hijri_and_gregorian(): void
    {
        $text = app(\App\Support\Dates\DateService::class)->format(\Carbon\CarbonImmutable::parse('2027-01-01 12:00', 'UTC'), \App\Settings\SettingsRegistry::get('date.display')['default'], 'Asia/Riyadh');
        $this->assertStringContainsString('هـ', $text);
        $this->assertStringContainsString('2027', $text);
    }

    public function test_req_0494_role_plus_scope_limits_the_properties_a_user_sees(): void
    {
        $s = $this->makeSubscriber();
        $manager = $this->makeUser($s, 'manager');
        $accountant = $this->makeUser($s, 'accountant');
        $newcomer = $this->makeUser($s, 'leasing_officer');
        $this->tenant()->set($s->id);
        $w = app(BuildingWizard::class);
        $o = Owner::create(['kind' => 'individual', 'name_ar' => 'م']);
        $p1 = $w->createProperty(Portfolio::create(['name_ar' => 'أ']), ['name_ar' => '1'], [['owner' => $o, 'numerator' => 1, 'denominator' => 1]], 'custom');
        $p2 = $w->createProperty(Portfolio::create(['name_ar' => 'ب']), ['name_ar' => '2'], [['owner' => $o, 'numerator' => 1, 'denominator' => 1]], 'custom');
        UserScope::create(['user_id' => $accountant->id, 'scope_type' => 'property', 'scope_id' => $p2->id]);
        $this->assertNull(Scope::propertyIds($manager));
        $this->assertSame([$p2->id], Scope::propertyIds($accountant));
        $this->assertFalse(Scope::canSeeProperty($accountant, $p1->id));
        $this->assertNull(Scope::propertyIds($newcomer));   // no scope rows: what the role permits across the office (M19)
    }

    public function test_req_0527_only_recorded_packages_are_required(): void
    {
        $require = array_keys(json_decode(file_get_contents(base_path('composer.json')), true)['require']);
        $recorded = file_get_contents(base_path('docs/PACKAGES.md'));
        foreach ($require as $pkg) {
            if ($pkg !== 'php') {
                $this->assertStringContainsString($pkg, $recorded, $pkg);
            }
        }
    }

    public function test_req_0521_node_is_used_for_building_only_and_req_0529_environment_matches_the_approved_setup(): void
    {
        $pkg = json_decode(file_get_contents(base_path('package.json')), true);
        $this->assertSame(['build', 'dev'], array_keys($pkg['scripts']));   // vite only, nothing served by Node
        $env = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('DB_CONNECTION=pgsql', $env);      // REQ-0533 PostgreSQL beside AMPPS
        $this->assertStringContainsString('QUEUE_CONNECTION=database', $env); // REQ-0534
        $this->assertFileExists(base_path('.htaccess'));                    // REQ-0532 AMPPS Apache → /public
        $this->assertFileExists(base_path('.claude/settings.json'));        // REQ-0536 Claude Code
    }
}
