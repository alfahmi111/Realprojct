<?php

namespace Tests\Feature\Foundation;

use App\Filament\Manager\Pages\SubscriberSettingsPage;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\Subscriber;
use App\Models\User;
use App\Privacy\DataRetention;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * REQ-0371 — retention period per data type, set by the office (data controller, REQ-0444). Empty = keep. Applied daily,
 * audited, limited to the office's own data; financial records, contracts and the audit log are never touched.
 */
class DataRetentionTest extends TestCase
{
    use RefreshDatabase;

    private Subscriber $s;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        $this->s = $this->makeSubscriber();
        $this->manager = $this->makeUser($this->s, 'manager');
        $this->tenant()->set($this->s->id);
    }

    private function outbox(string $status, CarbonImmutable $at): int
    {
        return DB::table('notifications_outbox')->insertGetId(['subscriber_id' => $this->tenant()->subscriberId(), 'event' => 'test', 'channel' => 'in_app', 'recipient_type' => 'user',
            'recipient_id' => 1, 'locale' => 'ar', 'body' => 'نص فيه بيانات', 'status' => $status, 'created_at' => $at, 'updated_at' => $at]);
    }

    private function batch(string $status, CarbonImmutable $at): ImportBatch
    {
        Storage::disk(config('filesystems.default'))->put("subscribers/{$this->s->id}/imports/$status.pdf", 'pdf');
        $b = ImportBatch::create(['uploaded_by' => $this->manager->id, 'kind' => 'ejar_pdf', 'file_path' => "subscribers/{$this->s->id}/imports/$status.pdf", 'extracted' => [['field' => 'tenant_name', 'value' => 'اسم']]]);
        $b->forceFill(['status' => $status])->save();
        DB::table('import_batches')->where('id', $b->id)->update(['updated_at' => $at]);
        return $b;
    }

    public function test_req_0371_nothing_is_removed_while_no_period_is_set(): void
    {
        $this->outbox('sent', CarbonImmutable::now()->subYears(5));
        $this->assertSame(['import_files' => null, 'notifications' => null, 'qr_scans' => null, 'offline_sync' => null], app(DataRetention::class)->periods());
        $this->assertSame([], app(DataRetention::class)->apply(CarbonImmutable::now()));
        $this->assertSame(1, DB::table('notifications_outbox')->count());
    }

    public function test_req_0371_each_type_is_removed_after_its_own_period_and_the_removal_is_audited(): void
    {
        $now = CarbonImmutable::parse('2026-11-23 10:00');
        app(DataRetention::class)->save(['import_files' => 6, 'notifications' => 3]);

        $oldSent = $this->outbox('sent', $now->subMonths(4));
        $oldQueued = $this->outbox('queued', $now->subMonths(4));     // not delivered yet: kept
        $recent = $this->outbox('read', $now->subMonths(2));
        $oldDone = $this->batch('confirmed', $now->subMonths(7));
        $staged = $this->batch('staged', $now->subMonths(7));            // still under review: kept
        DB::table('qr_scans')->insert(['subscriber_id' => $this->s->id, 'building_qr_id' => $this->qr(), 'scanned_at' => $now->subYears(3)]);

        // Another office's old notification is out of reach (RLS).
        $other = $this->makeSubscriber('office', 'مكتب آخر');
        $this->tenant()->run($other->id, fn () => $this->outbox('sent', $now->subYears(2)));

        $done = app(DataRetention::class)->apply($now);
        $this->assertSame(['import_files' => 1, 'notifications' => 1], $done);
        $this->assertNull(DB::table('notifications_outbox')->find($oldSent));
        $this->assertNotNull(DB::table('notifications_outbox')->find($oldQueued));
        $this->assertNotNull(DB::table('notifications_outbox')->find($recent));
        $oldDone->refresh();
        $this->assertSame('', $oldDone->file_path);
        $this->assertSame([], $oldDone->extracted);
        Storage::disk(config('filesystems.default'))->assertMissing("subscribers/{$this->s->id}/imports/confirmed.pdf");
        Storage::disk(config('filesystems.default'))->assertExists("subscribers/{$this->s->id}/imports/staged.pdf");
        $this->assertNotSame('', $staged->fresh()->file_path);
        $this->assertSame(1, DB::table('qr_scans')->count(), 'no period for scans: kept');
        $this->assertSame(2, AuditLog::where('action', 'data.retention.applied')->count());
        $this->assertSame(1, $this->tenant()->run($other->id, fn () => DB::table('notifications_outbox')->count()));
    }

    public function test_req_0371_the_office_sets_periods_on_its_settings_page(): void
    {
        $this->manager->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        Filament::setCurrentPanel(Filament::getPanel('manager'));
        $this->actingAs($this->manager)->get('/manage/subscriber-settings')->assertOk()->assertSee('مدد الاحتفاظ بالبيانات');
        Livewire::actingAs($this->manager)->test(SubscriberSettingsPage::class)
            ->fillForm(['retention' => ['import_files' => 12, 'notifications' => '', 'qr_scans' => 24, 'offline_sync' => null]])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(['import_files' => 12, 'notifications' => null, 'qr_scans' => 24, 'offline_sync' => null], app(DataRetention::class)->periods());
    }

    public function test_req_0371_the_daily_command_runs_for_every_office(): void
    {
        app(DataRetention::class)->save(['notifications' => 1]);
        $this->outbox('sent', CarbonImmutable::now()->subMonths(2));
        $this->tenant()->clear();
        $this->artisan('data:retention')->assertSuccessful();
        $this->tenant()->set($this->s->id);
        $this->assertSame(0, DB::table('notifications_outbox')->count());
    }

    private function qr(): int
    {
        return DB::table('building_qrs')->insertGetId(['subscriber_id' => $this->s->id, 'code' => 'T' . random_int(1000, 9999), 'building_id' => $this->building(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function building(): int
    {
        $o = \App\Models\Owner::create(['kind' => 'individual', 'name_ar' => 'مالك']);
        $p = app(\App\Properties\BuildingWizard::class)->createProperty(\App\Models\Portfolio::create(['name_ar' => 'أ']), ['name_ar' => 'عمارة'], [['owner' => $o, 'numerator' => 1, 'denominator' => 1]], 'residential_building');
        return \App\Models\Building::create(['property_id' => $p->id, 'name_ar' => 'أ'])->id;
    }
}
