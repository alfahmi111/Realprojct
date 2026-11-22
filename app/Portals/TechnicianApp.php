<?php

namespace App\Portals;

use App\Maintenance\FoundingInventory;
use App\Maintenance\MaintenanceException;
use App\Maintenance\MaintenanceService;
use App\Models\Asset;
use App\Models\InventoryCount;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Technician interface (spec §7.4, REQ-0313; §6.4 REQ-0236; §11.2 REQ-0495): assigned work orders only, QR scan opens
 * the asset record, parts and time, before/after photos, tenant signature, founding-inventory tasks — and works offline:
 * the browser queues operations in IndexedDB and replays them here; each operation carries a client id so a replay is
 * applied exactly once.
 */
class TechnicianApp
{
    public function __construct(private MaintenanceService $maintenance, private FoundingInventory $inventory)
    {
    }

    public function assigned(User $tech): array
    {
        return WorkOrder::where('assignee_user_id', $tech->id)->whereIn('status', ['assigned', 'in_progress', 'completed'])
            ->orderByRaw("case priority when 'emergency' then 0 when 'urgent' then 1 when 'normal' then 2 else 3 end")->get()
            ->map(fn ($w) => ['id' => $w->id, 'number' => $w->number, 'status' => $w->status, 'priority' => $w->priority, 'description' => $w->description, 'asset_id' => $w->asset_id, 'space_id' => $w->space_id])->all();
    }

    /** Scanning an asset's QR opens its record and history — only for assets of the technician's work or count. */
    public function scan(User $tech, string $qr): array
    {
        $asset = Asset::where('qr_code', $qr)->firstOrFail();
        $allowed = WorkOrder::where('assignee_user_id', $tech->id)->where(fn ($q) => $q->where('asset_id', $asset->id)->orWhere('space_id', $asset->space_id))->exists()
            || InventoryCount::where('performed_by', $tech->id)->where('space_id', $asset->space_id)->where('status', 'open')->exists();
        abort_unless($allowed, 403);
        return ['asset' => $asset->only(['id', 'qr_code', 'name_ar', 'category', 'type', 'brand', 'model', 'serial_number', 'condition', 'warranty_ends_on']), 'history' => $this->maintenance->assetHistory($asset)];
    }

    /**
     * Replay queued offline operations. $ops = [['id' => uuid, 'op' => 'start'|'add_cost'|'complete'|'tenant_signature'|'inventory_record'|'inventory_submit', ...], ...]
     * Returns per-op results; an op id already applied returns its stored result (idempotent).
     */
    public function sync(User $tech, array $ops): array
    {
        $results = [];
        foreach ($ops as $op) {
            $done = DB::table('offline_sync_ops')->where('user_id', $tech->id)->where('client_op_id', $op['id'])->value('result');
            if ($done !== null) {
                $results[$op['id']] = json_decode($done, true) + ['replayed' => true];
                continue;
            }
            try {
                $result = DB::transaction(fn () => $this->apply($tech, $op));
            } catch (MaintenanceException $e) {
                $result = ['ok' => false, 'error' => $e->getMessage()];
            }
            DB::table('offline_sync_ops')->insert(['subscriber_id' => $tech->subscriber_id, 'user_id' => $tech->id, 'client_op_id' => $op['id'], 'op' => $op['op'], 'result' => json_encode($result), 'created_at' => now(), 'updated_at' => now()]);
            $results[$op['id']] = $result;
        }
        return $results;
    }

    private function apply(User $tech, array $op): array
    {
        if (str_starts_with($op['op'], 'inventory_')) {
            $count = InventoryCount::findOrFail($op['count_id']);
            if ($count->performed_by !== $tech->id) {
                throw new MaintenanceException('This count is not assigned to you.');
            }
            return match ($op['op']) {
                'inventory_record' => ['ok' => true, 'line_id' => $this->inventory->record($count, $op['qr'], (bool) $op['present'], $op['condition'] ?? null, $op['photo'], $op['note'] ?? null)->id],
                'inventory_submit' => ['ok' => true, 'status' => $this->inventory->submit($count, $op['signature'], $op['tenant_signature'] ?? null)->status],
                default => throw new MaintenanceException('Unknown operation.'),
            };
        }
        $wo = WorkOrder::findOrFail($op['work_order_id']);
        if ($wo->assignee_user_id !== $tech->id) {
            throw new MaintenanceException('This work order is not assigned to you.');
        }
        return match ($op['op']) {
            'start' => ['ok' => true, 'status' => $this->maintenance->start($wo, $op['photos_before'] ?? [])->status],
            'add_cost' => ['ok' => true, 'cost_line_id' => $this->maintenance->addCost($wo, $op['kind'], $op['description'], (int) $op['quantity'], Money::parse((string) $op['unit_cost']))->id],
            'complete' => ['ok' => true, 'status' => $this->maintenance->complete($wo, $op['photos_after'] ?? [])->status],
            'tenant_signature' => ['ok' => true, 'status' => tap($wo)->forceFill(['tenant_feedback' => trim(($wo->tenant_feedback ?? '') . ' [signature:' . $op['signature'] . ']')])->save() ? $wo->status : $wo->status],
            default => throw new MaintenanceException('Unknown operation.'),
        };
    }
}
