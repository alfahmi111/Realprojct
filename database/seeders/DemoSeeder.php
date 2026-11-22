<?php

namespace Database\Seeders;

use App\Finance\InvoiceService;
use App\Finance\PaymentService;
use App\Leasing\LeasingService;
use App\Maintenance\MaintenanceService;
use App\Models\Asset;
use App\Models\LeasableUnit;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Models\Space;
use App\Models\Subscriber;
use App\Models\Tenant;
use App\Models\User;
use App\Properties\BuildingWizard;
use App\Support\Money\Money;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo office for local development and screenshots only (php artisan db:seed --class=DemoSeeder).
 * Everything goes through the real services, so the data obeys every rule (RLS, no overlap, schedules, ledger).
 * Refuses to run outside the local environment.
 */
class DemoSeeder extends Seeder
{
    /** Local demo only: recovery codes for the demo 2FA accounts (stored hashed). */
    public const DEMO_RECOVERY_CODES = ['withaq-demo-0001', 'withaq-demo-0002', 'withaq-demo-0003', 'withaq-demo-0004'];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->error('DemoSeeder runs only in the local environment.');
            return;
        }
        $ctx = app(TenantContext::class);
        $s = $ctx->run(null, fn () => Subscriber::firstOrCreate(['name_ar' => 'مكتب الديار لإدارة الأملاك'], ['name_en' => 'Al Diyar Property Management', 'plan' => 'office']));
        $ctx->set($s->id);
        if (LeasableUnit::exists()) {
            $this->command?->info('Demo data already present.');
            return;
        }
        $mk = fn (string $role, string $name, string $email) => User::create(['subscriber_id' => $s->id, 'name' => $name, 'email' => $email, 'password' => 'demo-password-123', 'role' => $role]);
        $manager = $mk('manager', 'أحمد الفاهمي', 'manager@withaq.test');
        $accountant = $mk('accountant', 'سارة العتيبي', 'accountant@withaq.test');
        $tech = $mk('technician', 'خالد القحطاني', 'tech@withaq.test');
        $ownerUser = $mk('owner', 'عبدالله الشهري', 'owner@withaq.test');
        foreach ([$manager, $ownerUser] as $u) {   // demo only: 2FA already set up (secret printed for an authenticator app)
            $u->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');   // well-known demo secret — local only
            $u->saveAppAuthenticationRecoveryCodes(array_map(fn ($c) => \Illuminate\Support\Facades\Hash::make($c), self::DEMO_RECOVERY_CODES));
        }

        $w = app(BuildingWizard::class);
        $owner = Owner::create(['kind' => 'individual', 'name_ar' => 'عبدالله الشهري', 'vat_registered' => true, 'vat_number' => '300000000000003', 'user_id' => $ownerUser->id]);
        $pf = Portfolio::create(['name_ar' => 'محفظة الشهري', 'owner_id' => $owner->id]);
        $p = $w->createProperty($pf, ['name_ar' => 'عمارة النخيل', 'region' => 'makkah', 'city' => 'jeddah', 'deed_number' => '420112000318'], [['owner' => $owner, 'numerator' => 1, 'denominator' => 1]], 'mixed_building');
        $w->createAgreement($owner, [$p], ['fee_type' => 'percentage', 'fee_percentage_bp' => 750, 'spend_cap_minor' => Money::parse('1500'), 'collector' => 'manager', 'starts_on' => '2026-01-01']);
        $w->generate($p, ['name_ar' => 'المبنى A', 'numbering' => 'floor_seq', 'floors' => [
            ['number' => 0, 'name_ar' => 'الأرضي', 'units' => [['type' => 'shop', 'count' => 4, 'label_ar' => 'محل']]],
            ['number' => 1, 'name_ar' => 'الأول', 'units' => [['type' => 'apartment', 'count' => 4, 'label_ar' => 'شقة']]],
            ['number' => 2, 'name_ar' => 'الثاني', 'units' => [['type' => 'apartment', 'count' => 4, 'label_ar' => 'شقة']]],
            ['number' => 3, 'name_ar' => 'الثالث', 'units' => [['type' => 'apartment', 'count' => 4, 'label_ar' => 'شقة']]],
        ], 'common_areas' => [['name_ar' => 'المصعد'], ['name_ar' => 'السطح']]]);
        foreach (LeasableUnit::where('property_id', $p->id)->get() as $u) {
            $w->setPrice($u, 'year', Money::parse($u->unit_type === 'shop' ? '72000' : '48000'));
        }

        $ls = app(LeasingService::class);
        $today = CarbonImmutable::today();
        $names = ['محمد السبيعي', 'تركي المطيري', 'نورة الدوسري', 'ريم الغامدي', 'تركي المطيري', 'هند الزهراني', 'سلطان العنزي', 'منى الشمري', 'بندر الرشيدي', 'لمى القرني', 'ماجد البقمي'];
        $codes = ['1', '2', '3', '101', '102', '103', '201', '202', '204', '301', '303'];
        $leases = [];
        foreach ($codes as $i => $code) {
            $unit = LeasableUnit::where('code', $code)->first();
            $t = Tenant::create(['kind' => $unit->unit_type === 'shop' ? 'company' : 'individual', 'name_ar' => $names[$i], 'phone' => '9665' . str_pad((string) (50000000 + $i), 8, '0')]);
            $start = $today->subMonths(3 + ($i * 2) % 11)->startOfMonth();
            $o = $ls->createOffer($manager, $unit, $t, ['usage' => $unit->unit_type === 'shop' ? 'commercial' : 'residential', 'starts_on' => $start->toDateString(), 'duration_unit' => 'year', 'duration_count' => 1,
                'annual_rent_minor' => Money::parse($unit->unit_type === 'shop' ? '72000' : '48000'), 'payment_frequency' => $i % 3 === 0 ? 'annual' : 'quarterly', 'deposit_minor' => Money::parse('3000')]);
            $lease = $ls->acceptOffer($ls->sendOffer($ls->submitOffer($o)));
            if ($i < 9) {
                $ls->sendForRegistration($lease);
                $ls->markAwaitingParties($lease);
                $ls->register($lease, 'EJ-' . (20260100 + $i));
                $ls->activate($lease);
            }
            $leases[] = $lease->fresh();
        }
        // Invoices for every due line, most paid, some overdue.
        $inv = app(InvoiceService::class);
        $pay = app(PaymentService::class);
        foreach ($leases as $i => $lease) {
            if (! $ls->canInvoice($lease)) {
                continue;
            }
            foreach ($lease->scheduleLines()->where('due_on', '<=', $today->toDateString())->get() as $line) {
                $invoice = $inv->issueRent($line, $today);   // VAT rate row is effective from 2026-11-22 (Q18)
                if ($i % 4 !== 1) {
                    $pay->record($lease->tenant, $invoice->total_minor, 'mada', CarbonImmutable::parse($line->due_on)->addDays(3), 'manager', $accountant);
                }
            }
        }
        // A tenant who gave notice (unit vacating), and maintenance activity.
        $ls->giveNotice($leases[3], 'tenant', $leases[3]->ends_on->subDays(70)->toImmutable());
        $m = app(MaintenanceService::class);
        $space = Space::where('code', '102')->first();
        $ac = Asset::create(['space_id' => $space->id, 'qr_code' => 'A-DEMO-AC1', 'category' => 'hvac', 'type' => 'split_ac', 'name_ar' => 'مكيف سبليت — الصالة', 'condition' => 3]);
        $wo1 = $m->report('corrective', $p->id, 'المكيف لا يبرد في الصالة', 'tenant', null, $ac);
        $wo2 = $m->report('emergency', $p->id, 'تسريب مياه في سقف المحل 2', 'tenant');
        $m->triage($wo2, 'emergency');
        $m->estimate($wo2, Money::parse('2400'));   // above the cap → owner approval
        $wo3 = $m->report('corrective', $p->id, 'إنارة الدرج متعطلة', 'supervisor', $manager);
        $m->triage($wo3, 'normal');
        $m->estimate($wo3, Money::parse('180'));
        $m->assign($wo3, $tech);
        // A tenant account linked to the lease on unit 102 (which has the AC asset), to try the tenant interface.
        self::linkDemoTenant($s);
        $this->command?->info('Demo office ready: manager@withaq.test / demo-password-123');
    }

    /** Local demo only: tenant@withaq.test sees the lease on unit 102. Safe to run again on an existing demo office. */
    public static function linkDemoTenant(\App\Models\Subscriber $s): void
    {
        $user = User::where('email', 'tenant@withaq.test')->first()
            ?? User::create(['subscriber_id' => $s->id, 'name' => 'تركي المطيري', 'email' => 'tenant@withaq.test', 'password' => 'demo-password-123', 'role' => 'tenant']);
        $lease = \App\Models\Lease::whereHas('unit', fn ($q) => $q->where('code', '102'))->whereIn('status', ['active', 'notice_given'])->first()
            ?? \App\Models\Lease::whereIn('status', ['active'])->orderBy('id')->first();
        $lease?->tenant->forceFill(['user_id' => $user->id])->save();
    }
}