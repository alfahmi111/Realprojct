<?php

namespace App\Filament\Manager\Resources\Tenants\Pages;

use App\Dashboards\HomeOverview;
use App\Dashboards\PortfolioTree;
use App\Dashboards\TenantProfiles;
use App\Filament\Manager\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\ListRecords;

/**
 * Tenants (owner request 2026-11-24): by default a tree — property → building → each tenant with the unit he rents and
 * its type, lease end and balance; tenants without a live lease listed apart. «عرض الكل» keeps the full table with
 * search and filters. A summary strip sits above both.
 */
class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;

    protected string $view = 'filament.manager.tenants';

    public string $mode = 'tree';

    public string $q = '';

    public function getHeading(): string
    {
        return '';
    }

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }

    public function tree(): array
    {
        $profiles = app(TenantProfiles::class)->all();
        $tree = app(PortfolioTree::class)->build(app(HomeOverview::class)->propertyIds(auth()->user()), CarbonImmutable::today());
        $q = trim($this->q);
        $placed = [];
        $out = [];
        foreach ($tree as $p) {
            $buildings = [];
            foreach ($p['buildings'] as $b) {
                $rows = [];
                foreach ($b['floors'] as $f) {
                    foreach ($f['units'] as $u) {
                        if (! $u['lease']) {
                            continue;
                        }
                        $tid = $u['lease']['tenant_id'];
                        $placed[$tid] = true;
                        $row = ['tenant_id' => $tid, 'name' => $u['lease']['tenant'], 'phone' => $u['lease']['phone'], 'unit' => $u['code'], 'type' => $u['type'],
                            'status' => $u['status'], 'lease_id' => $u['lease']['id'], 'ends_on' => $u['lease']['ends_on'], 'days_left' => $u['lease']['days_left'],
                            'outstanding' => $profiles[$tid]['outstanding'] ?? 0, 'floor' => $f['name']];
                        if ($q === '' || $this->hit($row, $q)) {
                            $rows[] = $row;
                        }
                    }
                }
                if ($rows) {
                    $buildings[] = ['name' => $b['name'], 'rows' => $rows];
                }
            }
            if ($buildings) {
                $out[] = ['id' => $p['id'], 'name' => $p['name'], 'photo' => $p['photo'], 'buildings' => $buildings, 'count' => array_sum(array_map(fn ($b) => count($b['rows']), $buildings))];
            }
        }
        $loose = Tenant::whereNull('archived_at')->whereNotIn('id', array_keys($placed) ?: [0])->orderBy('name_ar')->get()
            ->map(fn ($t) => ['tenant_id' => $t->id, 'name' => $t->name_ar, 'phone' => $t->phone, 'outstanding' => $profiles[$t->id]['outstanding'] ?? 0])
            ->filter(fn ($r) => $q === '' || $this->hit($r, $q))->values()->all();
        return ['properties' => $out, 'loose' => $loose];
    }

    private function hit(array $row, string $q): bool
    {
        foreach (['name', 'phone', 'unit'] as $k) {
            if (isset($row[$k]) && $row[$k] !== null && mb_stripos((string) $row[$k], $q) !== false) {
                return true;
            }
        }
        return false;
    }
}
