<?php

namespace App\Plans;

use App\Audit\AuditLogger;
use App\Models\LeasableUnit;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Subscriber;
use App\Models\User;
use App\Settings\Settings;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

/**
 * What the platform admin does for a subscriber (REQ-0065, REQ-0323): subscription period and renewal, plan change
 * within the plan's unit limit (REQ-0634…REQ-0637), suspension that really stops sign-in, usage against the plan, and
 * account support for the office's users (temporary password, reset two-factor, disable). The platform sees counts,
 * never the office's business records (processor role, REQ-0453). Every action is audited in the office's own log.
 */
final class SubscriberAdmin
{
    public function __construct(private TenantContext $ctx, private Settings $settings)
    {
    }

    /** Days until the subscription ends (negative = ended), or null when no end date is recorded. */
    public function daysRemaining(Subscriber $s, CarbonImmutable $today): ?int
    {
        return $s->subscription_ends_on ? (int) $today->startOfDay()->diffInDays(CarbonImmutable::parse($s->subscription_ends_on)->startOfDay(), false) : null;
    }

    /**
     * suspended | read_only | grace | expiring | active | no_period (Q24, owner 2026-11-23): after the end date the office
     * keeps full use for the grace period with a daily notice, then becomes read-only on its own — no job has to run for
     * the state to change, so it can never be missed. Manual suspension (no sign-in at all) stays a separate decision.
     */
    public function state(Subscriber $s, CarbonImmutable $today): string
    {
        if ($s->isSuspended()) {
            return 'suspended';
        }
        $days = $this->daysRemaining($s, $today);
        if ($days === null) {
            return 'no_period';
        }
        if ($days < 0) {
            return -$days > $this->graceDays($s) ? 'read_only' : 'grace';
        }
        return $days <= (int) ($this->settings->get('subscription.warning_days') ?? 30) ? 'expiring' : 'active';
    }

    public const STATE_AR = ['active' => 'نشط', 'expiring' => 'ينتهي قريباً', 'grace' => 'منتهٍ — في مهلة السماح', 'read_only' => 'منتهٍ — قراءة فقط', 'suspended' => 'موقوف', 'no_period' => 'بلا مدة'];

    public const STATE_COLOR = ['active' => 'success', 'expiring' => 'warning', 'grace' => 'warning', 'read_only' => 'danger', 'suspended' => 'danger', 'no_period' => 'gray'];

    /** The subscriber's own grace period if the admin extended it, otherwise the platform default (7 days, Q24). */
    public function graceDays(Subscriber $s): int
    {
        return $s->grace_days ?? (int) ($this->settings->get('subscription.grace_days') ?? 7);
    }

    /** Days left in the grace period (0 = last day), or null outside it. */
    public function graceLeft(Subscriber $s, CarbonImmutable $today): ?int
    {
        $days = $this->daysRemaining($s, $today);
        return $days !== null && $days < 0 && -$days <= $this->graceDays($s) ? $this->graceDays($s) + $days : null;
    }

    /** Read-only offices can sign in, read and export (ر-20), but nothing their users do is saved. */
    public function isReadOnly(Subscriber $s, CarbonImmutable $today): bool
    {
        return $this->state($s, $today) === 'read_only';
    }

    /** Extend this subscriber's grace period by whole days (it may bring a read-only office back to full use). */
    public function extendGrace(Subscriber $s, int $days): Subscriber
    {
        if ($days < 1 || $days > 90) {
            throw new InvalidArgumentException('تمديد المهلة بالأيام بين 1 و90.');
        }
        $before = $this->graceDays($s);
        $s->forceFill(['grace_days' => $before + $days])->save();
        $this->audit($s, 'subscription.grace_extended', ['grace_days' => $before], ['grace_days' => $before + $days]);
        return $s;
    }

    /** Extend the subscription by whole cycles, from its end date or from today if it already ended. */
    public function renew(Subscriber $s, int $months, CarbonImmutable $today): Subscriber
    {
        if ($months < 1 || $months > 60) {
            throw new InvalidArgumentException('مدة التجديد بالأشهر بين 1 و60.');
        }
        $before = ['starts_on' => $s->subscription_starts_on?->toDateString(), 'ends_on' => $s->subscription_ends_on?->toDateString()];
        $end = $s->subscription_ends_on ? CarbonImmutable::parse($s->subscription_ends_on) : null;
        $from = $end && $end->gte($today) ? $end : $today->subDay();
        $s->forceFill([
            'subscription_starts_on' => $s->subscription_starts_on ?? $today->toDateString(),
            'subscription_ends_on' => $from->addMonthsNoOverflow($months)->toDateString(),
            'grace_days' => null,   // an extended grace period belonged to the period that ended
        ])->save();
        $this->audit($s, 'subscription.renewed', $before, ['months' => $months, 'ends_on' => $s->subscription_ends_on->toDateString()]);
        return $s;
    }

    /** A smaller plan is refused while the office has more units than it allows. */
    public function changePlan(Subscriber $s, string $plan): Subscriber
    {
        if (! isset(PlanEntitlements::PLANS[$plan])) {
            throw new InvalidArgumentException('باقة غير معروفة.');
        }
        $max = PlanEntitlements::PLANS[$plan]['max_units'];
        $units = $this->usage($s)['units'];
        if ($max !== null && $units > $max) {
            throw new InvalidArgumentException(sprintf('لدى المشترك %d وحدة، وباقة «%s» تتسع لـ %d فقط. تُختار باقة أكبر أو تُؤرشف وحدات أولاً.', $units, PlanEntitlements::PLANS[$plan]['ar'], $max));
        }
        $before = $s->plan;
        // A smaller plan takes back what it does not include (custom colour, app name, domain…): otherwise the office
        // would keep using them, since they are applied when the page is drawn (REQ-0333…REQ-0337).
        $dropped = array_values(array_diff(\App\Theming\Customization::FEATURES[$before] ?? [], \App\Theming\Customization::FEATURES[$plan] ?? []));
        $cleared = DB::transaction(function () use ($s, $plan, $dropped) {
            $s->forceFill(['plan' => $plan])->save();
            return $this->ctx->run($s->id, function () use ($dropped) {
                $cleared = [];
                foreach ($dropped as $key) {
                    try {
                        if ($this->settings->get($key) !== null) {
                            $this->settings->setSubscriber($key, null);
                            $cleared[] = $key;
                        }
                    } catch (\InvalidArgumentException) {
                        // not a stored setting (feature flag only): nothing to clear
                    }
                }
                return $cleared;
            });
        });
        $this->audit($s, 'subscription.plan_changed', ['plan' => $before], ['plan' => $plan, 'cleared' => $cleared]);
        return $s;
    }

    /** Suspension stops every user of the office from signing in (checked on every request) until reactivated. */
    public function suspend(Subscriber $s, string $reason): Subscriber
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new InvalidArgumentException('اكتب سبب الإيقاف (5 أحرف على الأقل). يظهر في سجل المشترك.');
        }
        $s->forceFill(['status' => 'suspended', 'suspended_reason' => trim($reason), 'suspended_at' => now()])->save();
        $this->audit($s, 'subscription.suspended', null, ['reason' => trim($reason)]);
        return $s;
    }

    public function activate(Subscriber $s): Subscriber
    {
        $reason = $s->suspended_reason;
        $s->forceFill(['status' => 'active', 'suspended_reason' => null, 'suspended_at' => null])->save();
        $this->audit($s, 'subscription.activated', ['reason' => $reason], null);
        return $s;
    }

    /** Counts only — never the office's records. */
    public function usage(Subscriber $s): array
    {
        return $this->ctx->run($s->id, function () use ($s) {
            $users = User::where('subscriber_id', $s->id)->whereNull('archived_at')->get(['role']);
            return [
                'units' => LeasableUnit::whereNull('archived_at')->where('is_composite', false)->count(),
                'max_units' => PlanEntitlements::PLANS[$s->plan]['max_units'] ?? null,
                'properties' => Property::whereNull('archived_at')->count(),
                'active_leases' => Lease::whereIn('status', ['registered', 'active', 'notice_given'])->count(),
                'users' => $users->count(),
                'users_by_role' => $users->groupBy(fn ($u) => $u->role->value)->map->count()->all(),
                'messages_this_month' => DB::table('message_usage')->where('month', now()->format('Y-m'))->pluck('count', 'channel')->map(fn ($v) => (int) $v)->all(),
            ];
        });
    }

    /** The office's users for account support: who, role, last sign-in, two-factor, active. */
    public function users(Subscriber $s): array
    {
        return $this->ctx->run($s->id, fn () => User::where('subscriber_id', $s->id)->orderBy('archived_at')->orderBy('role')->orderBy('name')->get()
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role->labelAr(), 'last_login_at' => $u->last_login_at,
                'two_factor' => filled($u->app_authentication_secret), 'active' => $u->archived_at === null])->all());
    }

    public function setTemporaryPassword(Subscriber $s, int $userId, string $password): void
    {
        Validator::make(['password' => $password], ['password' => ['required', Password::default()]], [], ['password' => 'كلمة المرور'])->validate();
        $this->ctx->run($s->id, function () use ($s, $userId, $password) {
            $u = $this->officeUser($s, $userId);
            $u->forceFill(['password' => $password, 'remember_token' => null])->save();
            DB::table('sessions')->where('user_id', $u->id)->delete();   // signed-in devices must sign in again
            $this->audit($s, 'support.password_set', null, ['user_id' => $u->id, 'email' => $u->email]);
        });
    }

    public function resetTwoFactor(Subscriber $s, int $userId): void
    {
        $this->ctx->run($s->id, function () use ($s, $userId) {
            $u = $this->officeUser($s, $userId);
            $u->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
            $this->audit($s, 'support.two_factor_reset', null, ['user_id' => $u->id, 'email' => $u->email]);
        });
    }

    public function setUserActive(Subscriber $s, int $userId, bool $active): void
    {
        $this->ctx->run($s->id, function () use ($s, $userId, $active) {
            $u = $this->officeUser($s, $userId);
            $u->forceFill(['archived_at' => $active ? null : now()])->save();
            if (! $active) {
                DB::table('sessions')->where('user_id', $u->id)->delete();
            }
            $this->audit($s, $active ? 'support.user_enabled' : 'support.user_disabled', null, ['user_id' => $u->id, 'email' => $u->email]);
        });
    }

    /**
     * Only this office's own users. Inside a subscriber context the platform flag can still expose platform rows
     * (subscriber_id NULL), so the office is named explicitly — support actions never reach a platform admin.
     */
    private function officeUser(Subscriber $s, int $userId): User
    {
        return User::where('subscriber_id', $s->id)->findOrFail($userId);
    }

    /** Platform actions on an office are written to that office's own audit log, so the office can see them. */
    private function audit(Subscriber $s, string $action, ?array $before, ?array $after): void
    {
        $this->ctx->run($s->id, fn () => AuditLogger::record($action, 'subscriber', $before, $after, (string) $s->id));
    }
}
