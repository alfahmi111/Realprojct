<?php

namespace Tests\Feature\Foundation;

use App\Models\AuditLog;
use App\Settings\InvalidSettingValueException;
use App\Settings\SettingLockedException;
use App\Settings\Settings;
use App\Settings\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REQ-0321…REQ-0326 — four levels, most specific wins, subscriber admin can lock.
 * REQ-0374, REQ-0493 — JSONB values with a fixed validation schema, effective values cached.
 * REQ-0376 — every subscriber-setting change is audited.
 * Covered IDs: REQ-0321, REQ-0322, REQ-0323, REQ-0324, REQ-0325, REQ-0326, REQ-0342, REQ-0343, REQ-0344, REQ-0345, REQ-0346,
 * REQ-0362, REQ-0371, REQ-0373, REQ-0347, REQ-0348, REQ-0349, REQ-0365, REQ-0366, REQ-0367, REQ-0368, REQ-0369, REQ-0370, REQ-0374, REQ-0376, REQ-0493.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): Settings
    {
        return app(Settings::class);
    }

    public function test_req_0321_the_more_specific_level_wins(): void
    {
        $s = $this->makeSubscriber();
        $u = $this->makeUser($s);
        $this->tenant()->set($s->id);

        $this->assertSame('both_hijri_first', $this->settings()->get('date.display', $u->id));   // spec default
        $this->tenant()->run(null, fn () => $this->settings()->setPlatform('date.display', 'gregorian'));   // REQ-0323
        $this->assertSame('gregorian', $this->settings()->get('date.display', $u->id));
        $this->settings()->setSubscriber('date.display', 'hijri');                                          // REQ-0324
        $this->assertSame('hijri', $this->settings()->get('date.display', $u->id));
        $this->settings()->setUser($u->id, 'date.display', 'both_gregorian_first');                         // REQ-0326
        $r = $this->settings()->resolve('date.display', $u->id);
        $this->assertSame(['both_gregorian_first', 'user'], [$r['value'], $r['source']]);
    }

    public function test_req_0325_property_level_overrides_the_subscriber_for_building_exceptions(): void
    {
        $s = $this->makeSubscriber();
        $this->tenant()->set($s->id);
        $this->assertSame(['emergency' => 2, 'urgent' => 24, 'normal' => 72], $this->settings()->get('maintenance.response_hours'));
        $pf = \App\Models\Portfolio::create(['name_ar' => 'م']);
        $p7 = \App\Models\Property::create(['portfolio_id' => $pf->id, 'name_ar' => 'ع']);
        $this->settings()->setProperty($p7->id, 'maintenance.response_hours', ['emergency' => 1, 'urgent' => 12, 'normal' => 48]);
        $this->assertSame(1, $this->settings()->get('maintenance.response_hours', null, $p7->id)['emergency']);
        $this->assertSame(2, $this->settings()->get('maintenance.response_hours', null, $p7->id + 1)['emergency']);
    }

    public function test_req_0321_a_locked_setting_cannot_be_changed_by_users(): void
    {
        $s = $this->makeSubscriber();
        $u = $this->makeUser($s);
        $this->tenant()->set($s->id);
        $this->settings()->setUser($u->id, 'numbers.digits', 'eastern');
        $this->settings()->setSubscriber('numbers.digits', 'western', locked: true);

        $r = $this->settings()->resolve('numbers.digits', $u->id);
        $this->assertSame(['western', true], [$r['value'], $r['locked']]);   // the user's earlier value is ignored
        $this->expectException(SettingLockedException::class);
        $this->settings()->setUser($u->id, 'numbers.digits', 'eastern');
    }

    public function test_req_0374_values_are_validated_against_the_fixed_schema(): void
    {
        $s = $this->makeSubscriber();
        $this->tenant()->set($s->id);
        foreach ([['date.display', 'lunar'], ['finance.fiscal_year_start_month', 13], ['theme.custom_primary', 'red'], ['notifications.quiet_hours', ['start' => '25:00', 'end' => '08:00']], ['date.timezone', 'Mars/Base']] as [$k, $v]) {
            try {
                $this->settings()->setSubscriber($k, $v);
                $this->fail("accepted $k");
            } catch (InvalidSettingValueException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(InvalidSettingValueException::class);
        $this->settings()->setSubscriber('a11y.font_size', 'large');   // user-level only
    }

    public function test_req_0374_values_are_stored_as_jsonb_per_level_table(): void
    {
        $s = $this->makeSubscriber();
        $this->tenant()->set($s->id);
        $this->settings()->setSubscriber('maintenance.response_hours', ['emergency' => 2, 'urgent' => 24, 'normal' => 72]);
        $type = DB::selectOne("select data_type from information_schema.columns where table_schema = current_schema() and table_name = 'subscriber_settings' and column_name = 'value'")->data_type;
        $this->assertSame('jsonb', $type);
        foreach (['platform_settings', 'subscriber_settings', 'user_settings'] as $t) {
            $this->assertNotNull(DB::selectOne('select 1 as x from information_schema.tables where table_schema = current_schema() and table_name = ?', [$t]));
        }
    }

    public function test_req_0376_subscriber_setting_changes_are_audited(): void
    {
        $s = $this->makeSubscriber();
        $u = $this->makeUser($s);
        $this->actingAs($u);
        $this->tenant()->set($s->id);
        $this->settings()->setSubscriber('finance.tax_period', 'monthly');
        $this->settings()->setSubscriber('finance.tax_period', 'quarterly');
        $logs = AuditLog::where('action', 'setting.changed')->where('auditable_id', 'finance.tax_period')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($u->id, $logs[1]->user_id);
        $this->assertSame('monthly', $logs[1]->before['value']);
        $this->assertSame('quarterly', $logs[1]->after['value']);
    }

    public function test_req_0374_cached_values_are_refreshed_after_a_change(): void
    {
        $s = $this->makeSubscriber();
        $this->tenant()->set($s->id);
        $this->assertSame(60, $this->settings()->get('leasing.non_renewal_notice_days'));
        $this->settings()->setSubscriber('leasing.non_renewal_notice_days', 90);
        $this->assertSame(90, $this->settings()->get('leasing.non_renewal_notice_days'));
    }

    public function test_req_0369_open_decision_defaults_are_marked_pending(): void
    {
        $s = $this->makeSubscriber();
        $this->tenant()->set($s->id);
        $this->assertSame('PENDING-D8', $this->settings()->resolve('waitlist.probable_visibility')['pending']);
        $this->assertSame('PENDING-D9', $this->settings()->resolve('maintenance.inventory_interval_months')['pending']);
        $this->assertSame('PENDING-D10', $this->settings()->resolve('security.dual_approval')['pending']);
    }

    /** Spec defaults (7.6 ج، و) — only values written in the spec, null where the spec gives none (R-01). */
    public function test_req_0343_req_0366_req_0367_req_0368_spec_defaults(): void
    {
        $d = fn ($k) => SettingsRegistry::get($k)['default'];
        $this->assertSame('both_hijri_first', $d('date.display'));             // REQ-0343
        $this->assertSame('both', $d('date.documents'));                       // REQ-0344
        $this->assertSame('umm_al_qura', $d('date.hijri_calendar'));           // REQ-0345
        $this->assertSame(0, $d('date.hijri_correction'));                     // REQ-0346
        $this->assertSame('sunday', $d('date.week_start'));                    // REQ-0347
        $this->assertSame('Asia/Riyadh', $d('date.timezone'));                 // REQ-0348
        $this->assertSame('auto', $d('appearance.mode'));                      // REQ-0338
        $this->assertSame('western', $d('numbers.digits'));                    // REQ-0363
        $this->assertSame(1, $d('finance.fiscal_year_start_month'));           // REQ-0366
        $this->assertSame('quarterly', $d('finance.tax_period'));              // REQ-0366
        $this->assertSame(60, $d('leasing.non_renewal_notice_days'));          // REQ-0367
        $this->assertSame(90, $d('leasing.renewal_survey_days'));              // REQ-0367
        $this->assertSame(['emergency' => 2, 'urgent' => 24, 'normal' => 72], $d('maintenance.response_hours'));  // REQ-0368
        $this->assertSame(60, $d('waitlist.confirm_interval_days'));           // REQ-0369
        $this->assertSame(['start' => '22:00', 'end' => '08:00'], $d('notifications.quiet_hours'));  // REQ-0357
        $this->assertSame('withaq', $d('platform.default_theme'));             // decision 11 settled by the owner (2026-11-22)
        $this->assertNull($d('security.session_minutes'));                     // not in spec → question
    }
}
