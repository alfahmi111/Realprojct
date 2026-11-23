<?php

namespace App\Filament\Manager\Pages;

use App\Dashboards\HomeOverview;
use Carbon\CarbonImmutable;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;

/**
 * Manager home: the office's morning view. Replaces Filament's empty default dashboard; every number and link comes
 * from the tested services and respects role + scope (REQ-0494) and RLS (REQ-0169).
 */
class HomeDashboard extends Dashboard
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $navigationLabel = 'الرئيسية';

    protected static ?string $title = 'الرئيسية';

    protected string $view = 'filament.manager.home';

    public ?int $building = null;

    public function getHeading(): string
    {
        return '';   // the greeting band is the heading
    }

    /** Quick actions, each shown only when the user's role allows it. */
    public static function quickActions(): array
    {
        return [
            ['label' => 'إضافة مبنى', 'hint' => 'المعالج: الشجرة والوحدات والأسعار', 'icon' => 'heroicon-o-building-office-2', 'url' => 'add-building', 'perm' => 'properties.create'],
            ['label' => 'عرض تأجير', 'hint' => 'عرض يمر بالاعتمادات ثم رابط قبول', 'icon' => 'heroicon-o-document-plus', 'url' => 'lease-offers/create', 'perm' => 'leasing.offer.create'],
            ['label' => 'تسجيل دفعة', 'hint' => 'سند قبض وتوزيع على الأقدم', 'icon' => 'heroicon-o-banknotes', 'url' => 'payments/create', 'perm' => 'finance.payments'],
            ['label' => 'بلاغ صيانة', 'hint' => 'أمر عمل جديد بالصور', 'icon' => 'heroicon-o-wrench-screwdriver', 'url' => 'work-orders/create', 'perm' => 'maintenance.create'],
            ['label' => 'استيراد عقد', 'hint' => 'من ملف إيجار أو صورة', 'icon' => 'heroicon-o-arrow-up-tray', 'url' => 'quick-migration', 'perm' => 'properties.import'],
            ['label' => 'كشف المالك', 'hint' => 'المحصّل والأتعاب والصافي', 'icon' => 'heroicon-o-document-chart-bar', 'url' => 'owner-statement', 'perm' => 'finance.statements'],
        ];
    }

    protected function getViewData(): array
    {
        $user = auth()->user();
        $svc = app(HomeOverview::class);
        $today = CarbonImmutable::today();
        $ids = $svc->propertyIds($user);
        $buildings = $svc->buildings($ids);
        $buildingId = collect($buildings)->firstWhere('id', $this->building)['id'] ?? ($buildings[0]['id'] ?? null);
        return [
            'header' => $svc->header($user, CarbonImmutable::now()),
            'setup' => $svc->setupSteps($user),
            'vitals' => $svc->vitals($ids, $today),
            'buildings' => $buildings,
            'buildingId' => $buildingId,
            'facade' => $buildingId ? $svc->facade($buildingId, $today) : null,
            'attention' => $svc->attention($user, $ids, $today),
            'actions' => array_values(array_filter(self::quickActions(), fn ($a) => $user->hasPermission($a['perm']))),
            'renewals' => $user->hasPermission('leasing.view') ? $svc->renewals($ids, $today) : [],
            'base' => filament()->getCurrentPanel()->getUrl(),
        ];
    }
}
