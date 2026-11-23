<?php

namespace App\Plans;

use App\Models\Subscriber;
use App\Settings\Settings;
use App\Support\Dates\DateService;
use App\Support\Money\Money;
use App\Tenancy\TenantContext;
use App\Theming\ThemeService;
use Carbon\CarbonImmutable;

/**
 * The platform admin's home: subscriber states, what needs attention, plans, expected monthly subscription revenue
 * (from the prices agreed per subscriber — the spec's prices are "for discussion"), and the launch-readiness checks.
 * Counts only: the platform never reads an office's business records (REQ-0453).
 */
final class PlatformOverview
{
    /** Most urgent first. */
    private const URGENCY = ['read_only' => 0, 'suspended' => 1, 'grace' => 2, 'expiring' => 3, 'no_period' => 4];

    public function __construct(private SubscriberAdmin $admin, private TenantContext $ctx, private Settings $settings)
    {
    }

    public function build(CarbonImmutable $today): array
    {
        $subs = $this->ctx->run(null, fn () => Subscriber::whereNull('archived_at')->orderByDesc('created_at')->get());
        $states = array_fill_keys(array_keys(SubscriberAdmin::STATE_AR), 0);
        $plans = array_fill_keys(array_keys(PlanEntitlements::PLANS), 0);
        $attention = [];
        $units = $users = 0;
        $monthly = Money::zero();
        $priced = 0;
        foreach ($subs as $s) {
            $state = $this->admin->state($s, $today);
            $states[$state]++;
            $plans[$s->plan] = ($plans[$s->plan] ?? 0) + 1;
            $u = $this->admin->usage($s);
            $units += $u['units'];
            $users += $u['users'];
            if ($s->price_minor && $s->billing_cycle && $state !== 'suspended') {
                $monthly = $monthly->plus($s->billing_cycle === 'annual' ? $s->price_minor->multiplyRatio(1, 12) : $s->price_minor);
                $priced++;
            }
            if (isset(self::URGENCY[$state])) {
                $days = $this->admin->daysRemaining($s, $today);
                $attention[] = ['subscriber' => $s, 'state' => $state, 'detail' => match ($state) {
                    'read_only' => 'للقراءة فقط منذ ' . (-$days - $this->admin->graceDays($s)) . ' يوماً',
                    'suspended' => 'موقوف: ' . ($s->suspended_reason ?? '—'),
                    'grace' => 'متبقٍ ' . $this->admin->graceLeft($s, $today) . ' يوماً من مهلة السماح',
                    'expiring' => $days === 0 ? 'ينتهي اليوم' : 'ينتهي خلال ' . $days . ' يوماً',
                    'no_period' => 'لم تُسجَّل مدة اشتراك',
                }, 'sort' => [self::URGENCY[$state], $days ?? PHP_INT_MAX]];
            }
        }
        usort($attention, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        return [
            'total' => $subs->count(), 'states' => $states, 'plans' => $plans, 'units' => $units, 'users' => $users,
            'monthly' => $monthly, 'priced' => $priced, 'attention' => $attention, 'latest' => $subs->take(5)->all(),
            'health' => $this->health(),
        ];
    }

    /** The same gates as `platform:launch-check`, shown where the admin will see them. */
    private function health(): array
    {
        $hijriOk = app(DateService::class)->hijriTrusted($why);
        $themeProblems = app(ThemeService::class)->launchProblems();
        return [
            ['label' => 'التقويم الهجري (أم القرى) موثوق على الخادم', 'ok' => $hijriOk, 'hint' => $hijriOk ? null : $why],
            ['label' => 'السمة الافتراضية للمنصة محددة', 'ok' => $themeProblems === [], 'hint' => $themeProblems[0] ?? null],
            ['label' => 'اجتاز النظام فحص الاختراق', 'ok' => $this->settings->get('launch.penetration_test_passed') === true, 'hint' => 'شرط قبل الإطلاق — يُعلَّم في إعدادات المنصة'],
        ];
    }
}
