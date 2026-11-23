<?php

namespace App\Organization;

use App\Audit\AuditLogger;
use App\Authorization\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * The office's organisation chart (owner's request 2026-11-23). Staff only — owners and tenants use their own portals
 * and are listed apart. Departments follow the role; the reporting line (users.reports_to_id) is what is moved.
 * Rules: same office, staff only, no one reports to someone of lower authority, no loops. Every move is audited.
 */
final class TeamStructure
{
    public const STAFF = [Role::Manager, Role::Accountant, Role::LeasingOfficer, Role::MaintenanceSupervisor, Role::Technician];

    public const DEPARTMENT = [
        'manager' => ['ar' => 'الإدارة', 'tone' => 'deep'],
        'accountant' => ['ar' => 'المالية', 'tone' => 'paid'],
        'leasing_officer' => ['ar' => 'التأجير', 'tone' => 'vacant'],
        'maintenance_supervisor' => ['ar' => 'الصيانة', 'tone' => 'maint'],
        'technician' => ['ar' => 'الصيانة', 'tone' => 'maint'],
    ];

    public function __construct(private TenantContext $ctx)
    {
    }

    /**
     * @return array{roots: list<array>, external: list<array>, count: int}
     *   Each node: id, name, email, role, role_ar, department, tone, active, two_factor, last_login_at, reports_to, implied, children.
     *   "implied" = no reporting line saved yet; shown under the first manager until someone places them.
     */
    public function tree(int $subscriberId): array
    {
        return $this->ctx->run($subscriberId, function () use ($subscriberId) {
            $all = User::where('subscriber_id', $subscriberId)->orderBy('name')->get();
            $staffValues = array_map(fn ($r) => $r->value, self::STAFF);
            $staff = $all->filter(fn (User $u) => in_array($u->role->value, $staffValues, true));
            $firstManager = $staff->first(fn (User $u) => $u->role === Role::Manager && $u->archived_at === null);

            $staffIds = $staff->pluck('id')->flip();
            $nodes = [];
            foreach ($staff as $u) {
                $parent = $u->reports_to_id !== null && isset($staffIds[$u->reports_to_id]) ? $u->reports_to_id : null;
                $implied = $parent === null && $u->role !== Role::Manager && $firstManager !== null;
                $nodes[$u->id] = $this->node($u) + ['reports_to' => $parent ?? ($implied ? $firstManager->id : null), 'implied' => $implied, 'children' => []];
            }
            // Order: managers first, then by department, then name.
            $order = array_flip($staffValues);
            uasort($nodes, fn ($a, $b) => [$order[$a['role']], $a['name']] <=> [$order[$b['role']], $b['name']]);
            $roots = [];
            $refs = [];
            foreach ($nodes as $id => $n) {
                $refs[$id] = $n;
            }
            $build = function (int $id) use (&$build, &$refs) {
                $n = $refs[$id];
                $n['children'] = array_values(array_map($build, array_keys(array_filter($refs, fn ($c) => $c['reports_to'] === $id))));
                return $n;
            };
            foreach ($refs as $id => $n) {
                if ($n['reports_to'] === null || ! isset($refs[$n['reports_to']])) {
                    $roots[] = $build($id);
                }
            }
            $external = $all->reject(fn (User $u) => in_array($u->role->value, $staffValues, true))->map(fn (User $u) => $this->node($u))->values()->all();
            return ['roots' => $roots, 'external' => $external, 'count' => $staff->count()];
        });
    }

    /** Who this person may report to (for the keyboard-friendly "move" form). */
    public function possibleManagers(int $subscriberId, int $userId): array
    {
        return $this->ctx->run($subscriberId, function () use ($subscriberId, $userId) {
            $u = User::where('subscriber_id', $subscriberId)->findOrFail($userId);
            return User::where('subscriber_id', $subscriberId)->whereNull('archived_at')->where('id', '!=', $userId)->get()
                ->filter(fn (User $p) => in_array($p->role, self::STAFF, true) && $p->role->authority() >= $u->role->authority() && $p->role->authority() > 0 && ! $this->isBelow($subscriberId, $p->id, $userId))
                ->mapWithKeys(fn (User $p) => [$p->id => $p->name . ' — ' . $p->role->labelAr()])->all();
        });
    }

    public function move(int $subscriberId, int $userId, ?int $newManagerId): void
    {
        $this->ctx->run($subscriberId, function () use ($subscriberId, $userId, $newManagerId) {
            $u = User::where('subscriber_id', $subscriberId)->findOrFail($userId);
            if (! in_array($u->role, self::STAFF, true)) {
                throw new InvalidArgumentException('الملاك والمستأجرون ليسوا ضمن الهيكل التنظيمي.');
            }
            if ($newManagerId !== null) {
                if ($newManagerId === $userId) {
                    throw new InvalidArgumentException('لا يتبع الموظف لنفسه.');
                }
                $p = User::where('subscriber_id', $subscriberId)->find($newManagerId)
                    ?? throw new InvalidArgumentException('الموظف المختار ليس من هذا المكتب.');
                if (! in_array($p->role, self::STAFF, true) || $p->archived_at !== null) {
                    throw new InvalidArgumentException('يتبع الموظف لموظف نشط في المكتب فقط.');
                }
                if ($p->role->authority() < $u->role->authority() || $p->role->authority() === 0) {
                    throw new InvalidArgumentException(sprintf('لا يتبع «%s» إلى «%s»: الصلاحية أدنى.', $u->role->labelAr(), $p->role->labelAr()));
                }
                if ($this->isBelow($subscriberId, $newManagerId, $userId)) {
                    throw new InvalidArgumentException(sprintf('«%s» يتبع أصلاً لـ «%s»، فلا يصح العكس.', $p->name, $u->name));
                }
            } elseif ($u->role !== Role::Manager) {
                throw new InvalidArgumentException('القمة للمديرين فقط؛ اختر من يتبع له هذا الموظف.');
            }
            $before = $u->reports_to_id;
            $u->forceFill(['reports_to_id' => $newManagerId])->save();
            AuditLogger::record('team.moved', $u, ['reports_to_id' => $before], ['reports_to_id' => $newManagerId]);
        });
    }

    /** Is $candidate somewhere below $userId in the saved reporting lines (would make a loop)? */
    private function isBelow(int $subscriberId, int $candidate, int $userId): bool
    {
        $lines = User::where('subscriber_id', $subscriberId)->pluck('reports_to_id', 'id')->all();
        $seen = [];
        for ($cur = $lines[$candidate] ?? null; $cur !== null && ! isset($seen[$cur]); $cur = $lines[$cur] ?? null) {
            if ($cur === $userId) {
                return true;
            }
            $seen[$cur] = true;
        }
        return false;
    }

    private function node(User $u): array
    {
        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role->value, 'role_ar' => $u->role->labelAr(),
            'department' => self::DEPARTMENT[$u->role->value]['ar'] ?? null, 'tone' => self::DEPARTMENT[$u->role->value]['tone'] ?? 'deep',
            'active' => $u->archived_at === null, 'two_factor' => filled($u->app_authentication_secret), 'last_login_at' => $u->last_login_at,
        ];
    }
}
