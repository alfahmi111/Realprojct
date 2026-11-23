<?php

namespace App\Filament\Manager\Pages;

use App\Dashboards\ManagerInbox;
use App\Models\ApprovalRequest;
use App\Models\LeaseOffer;
use App\Models\User;
use App\Models\WorkOrder;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * One task inbox for the phone (REQ-0315): pending approvals, new reports, escalated arrears. Redesigned 2026-11-23 as
 * three lanes on a sorting desk: what is waiting, since when, and the one action that clears it.
 */
class InboxPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?string $navigationLabel = 'صندوق المهام';

    protected static ?string $title = 'صندوق المهام';

    protected static ?string $slug = 'inbox';

    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.pages.inbox';

    public const TYPE_AR = ['corrective' => 'تصحيحية', 'emergency' => 'طارئة', 'unit_prep' => 'تجهيز وحدة', 'preventive' => 'وقائية'];

    public const PRIORITY_AR = ['emergency' => 'طارئ', 'urgent' => 'عاجل', 'normal' => 'عادي', 'scheduled' => 'مجدول'];

    public function getHeading(): string
    {
        return '';
    }

    protected function getViewData(): array
    {
        $box = app(ManagerInbox::class)->for(auth()->user());
        $now = CarbonImmutable::now();
        $ago = function ($at) use ($now) {
            if (! $at) {
                return '';
            }
            $d = (int) CarbonImmutable::parse($at)->diffInDays($now);
            $h = (int) CarbonImmutable::parse($at)->diffInHours($now);
            return $d >= 1 ? 'منذ ' . ($d === 1 ? 'يوم' : ($d === 2 ? 'يومين' : $d . ($d <= 10 ? ' أيام' : ' يوماً'))) : ($h >= 1 ? 'منذ ' . ($h === 1 ? 'ساعة' : ($h === 2 ? 'ساعتين' : $h . ($h <= 10 ? ' ساعات' : ' ساعة'))) : 'الآن');
        };
        $offers = LeaseOffer::whereIn('id', collect($box['approvals']['offers'])->pluck('offer_id'))->with(['unit', 'tenant'])->get()->keyBy('id');
        $entries = ApprovalRequest::whereIn('id', collect($box['approvals']['entries'])->pluck('id'))->with('maker')->get()->keyBy('id');
        $reports = WorkOrder::whereIn('id', collect($box['new_reports'])->pluck('id'))->with('property')->get()->keyBy('id');
        $base = filament()->getCurrentPanel()->getUrl();

        $approvals = [];
        foreach ($box['approvals']['offers'] as $o) {
            $offer = $offers[$o['offer_id']] ?? null;
            $approvals[] = ['kind' => 'عرض تأجير', 'title' => $offer ? 'الوحدة ' . $offer->unit?->code . ' لـ' . $offer->tenant?->name_ar : 'عرض #' . $o['offer_id'],
                'meta' => 'خطوة الاعتماد ' . $o['step'], 'ago' => $ago($offer?->created_at), 'url' => $base . '/lease-offers', 'cta' => 'راجع العرض'];
        }
        foreach ($box['approvals']['entries'] as $e) {
            $r = $entries[$e['id']] ?? null;
            $approvals[] = ['kind' => 'قيد حساس', 'title' => \App\Filament\Manager\Resources\ApprovalRequests\ApprovalRequestResource::ACTIONS_AR[$e['action']] ?? $e['action'],
                'meta' => 'أدخله ' . ($r?->maker?->name ?? '—'), 'ago' => $ago($r?->created_at), 'url' => $base . '/approval-requests', 'cta' => 'اعتمد أو ارفض'];
        }
        $repRows = [];
        foreach ($box['new_reports'] as $w) {
            $wo = $reports[$w['id']] ?? null;
            $repRows[] = ['title' => $wo?->description ?: $w['number'], 'number' => $w['number'], 'type' => self::TYPE_AR[$w['type']] ?? $w['type'],
                'priority' => $w['priority'], 'priority_ar' => $w['priority'] ? (self::PRIORITY_AR[$w['priority']] ?? $w['priority']) : 'لم تُحدَّد أولويته',
                'awaiting_owner' => $w['status'] === 'awaiting_owner_approval', 'property' => $wo?->property?->name_ar, 'ago' => $ago($wo?->reported_at), 'url' => $base . '/work-orders/' . $w['id']];
        }
        usort($repRows, fn ($a, $b) => ($b['priority'] === 'emergency') <=> ($a['priority'] === 'emergency'));
        return [
            'counts' => $box['counts'],
            'approvals' => $approvals,
            'reports' => $repRows,
            'arrears' => array_map(fn ($a) => ['body' => $a['body']], $box['escalated_arrears']),
            'base' => $base,
            'user' => auth()->user(),
        ];
    }
}
