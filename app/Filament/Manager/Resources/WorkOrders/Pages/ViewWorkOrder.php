<?php

namespace App\Filament\Manager\Resources\WorkOrders\Pages;

use App\Filament\Manager\Resources\WorkOrders\WorkOrderResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One work order (redesign 2026-11-23): its stages on a line from report to close (REQ-0227), where and what, who does
 * it, estimate against actual cost lines, owner approval when above the spend cap, tenant rating, photos.
 */
class ViewWorkOrder extends ViewRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected string $view = 'filament.manager.work-order-view';

    public const FLOW = ['reported', 'triaged', 'estimated', 'assigned', 'in_progress', 'completed', 'confirmed', 'closed'];

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getHeading(): string
    {
        return '';
    }

    public function data(): array
    {
        $w = $this->record->load(['property', 'space', 'asset', 'vendor', 'assignee', 'costLines']);
        $status = $w->status === 'awaiting_owner_approval' ? 'estimated' : $w->status;
        $at = array_search($status, self::FLOW, true);
        $stamps = ['reported' => $w->reported_at, 'triaged' => $w->responded_at, 'completed' => $w->completed_at, 'closed' => $w->closed_at];
        return [
            'w' => $w,
            'flow' => array_map(fn ($s, $i) => ['key' => $s, 'label' => WorkOrderResource::STATUS_AR[$s], 'state' => $w->status === 'rejected' ? 'off' : ($at === false ? 'off' : ($i < $at ? 'done' : ($i === $at ? 'now' : 'next'))), 'at' => $stamps[$s] ?? null], self::FLOW, array_keys(self::FLOW)),
            'actual' => $w->costLines->sum(fn ($c) => $c->total_minor->minor()),
            'photos' => array_values(array_filter(array_merge((array) $w->photos_report, (array) $w->photos_before, (array) $w->photos_after))),
        ];
    }
}
