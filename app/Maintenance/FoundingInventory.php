<?php

namespace App\Maintenance;

use App\Models\Asset;
use App\Models\InventoryCount;
use App\Models\InventoryLine;
use App\Models\Space;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Founding inventory (spec §6.6, the MVP count type): the reference asset register of each unit when the building is
 * added. In the field: open the count, see expected assets, scan or pick each, record present / not present,
 * condition 1–5, a mandatory photo and an optional note, add unregistered assets found, then sign (REQ-0250…REQ-0254).
 * Entry / exit / periodic / surprise / common-area counts and variances are phase 2 (decisions Q2, Q3).
 */
class FoundingInventory
{
    public function start(Space $unit, User $by, CarbonImmutable $on): InventoryCount
    {
        if (InventoryCount::where('space_id', $unit->id)->where('type', 'founding')->exists()) {
            throw new MaintenanceException('This unit already has a founding inventory.');
        }
        return InventoryCount::create(['type' => 'founding', 'space_id' => $unit->id, 'counted_on' => $on->toDateString(), 'performed_by' => $by->id]);
    }

    /** Assets expected in the unit (the list shown to the technician). */
    public function expected(InventoryCount $count): array
    {
        return Asset::where('space_id', $count->space_id)->whereNull('archived_at')->orderBy('id')->get()->all();
    }

    /** Record one asset — by its QR code (scan) or by picking it from the list. */
    public function record(InventoryCount $count, string|Asset $assetOrQr, bool $present, ?int $condition, string $photo, ?string $note = null): InventoryLine
    {
        $this->open($count);
        $asset = $assetOrQr instanceof Asset ? $assetOrQr : Asset::where('qr_code', $assetOrQr)->firstOrFail();
        if ($asset->space_id !== $count->space_id) {
            throw new MaintenanceException('This asset is registered in another unit.');
        }
        if (trim($photo) === '') {
            throw new MaintenanceException('A photo is mandatory for every asset.');
        }
        if ($present && ($condition === null || $condition < 1 || $condition > 5)) {
            throw new MaintenanceException('Condition is 1 to 5 for a present asset.');
        }
        return InventoryLine::create(['inventory_count_id' => $count->id, 'asset_id' => $asset->id, 'present' => $present, 'condition' => $present ? $condition : null, 'photo' => $photo, 'note' => $note]);
    }

    /** Add an asset found in the unit but not registered (REQ-0253). */
    public function addUnregistered(InventoryCount $count, array $assetData, int $condition, string $photo, ?string $note = null): InventoryLine
    {
        $this->open($count);
        if (! AssetTaxonomy::valid($assetData['category'], $assetData['type'])) {
            throw new MaintenanceException('Unknown asset category/type.');
        }
        return DB::transaction(function () use ($count, $assetData, $condition, $photo, $note) {
            $asset = Asset::create($assetData + ['space_id' => $count->space_id, 'qr_code' => 'A-' . Str::upper(Str::random(10)), 'condition' => $condition, 'photos' => [$photo]]);
            $line = $this->record($count, $asset, true, $condition, $photo, $note);
            $line->forceFill(['added_during_count' => true])->save();
            return $line;
        });
    }

    /** Sign and submit: every expected asset must be recorded; conditions become the reference register (REQ-0243). */
    public function submit(InventoryCount $count, string $technicianSignature, ?string $tenantSignature = null): InventoryCount
    {
        $this->open($count);
        $recorded = InventoryLine::where('inventory_count_id', $count->id)->pluck('asset_id')->all();
        $missing = array_filter($this->expected($count), fn ($a) => ! in_array($a->id, $recorded, true));
        if ($missing) {
            throw new MaintenanceException(count($missing) . ' expected asset(s) not recorded yet.');
        }
        DB::transaction(function () use ($count, $technicianSignature, $tenantSignature) {
            foreach (InventoryLine::where('inventory_count_id', $count->id)->get() as $l) {
                Asset::whereKey($l->asset_id)->get()->each(fn ($a) => $a->forceFill(['condition' => $l->condition])->save());
            }
            $count->forceFill(['technician_signature' => $technicianSignature, 'tenant_signature' => $tenantSignature, 'status' => 'submitted'])->save();
        });
        return $count;
    }

    /** Completion of founding inventory per building (report REQ-0262). */
    public function completion(int $buildingId): array
    {
        $units = Space::where('building_id', $buildingId)->where('kind', 'unit')->whereNull('archived_at')->pluck('id');
        $done = InventoryCount::whereIn('space_id', $units)->where('type', 'founding')->where('status', 'submitted')->count();
        return ['units' => $units->count(), 'counted' => $done, 'percent_bp' => $units->count() ? intdiv($done * 10000, $units->count()) : 0];
    }

    private function open(InventoryCount $count): void
    {
        if ($count->status !== 'open') {
            throw new MaintenanceException('This count is already submitted.');
        }
    }
}
