<?php

namespace App\Filament\Tenant\Pages;

use App\Filament\Support\Ui;
use App\Models\Asset;
use App\Models\Invoice;
use App\Models\WorkOrder;
use App\Portals\TenantPortal;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\WithFileUploads;

/**
 * Tenant interface (spec §7.1 MVP): my unit and its assets, my lease and notice deadline, my invoices and payment,
 * maintenance reports with photos and rating, announcements, meter-transfer guidance.
 */
class TenantHome extends Page
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = -10;   // the role home opens first (the panel root redirects here)

    protected static ?string $navigationLabel = 'وحدتي';

    protected static ?string $title = 'وحدتي';

    protected static ?string $slug = 'my-unit';

    protected string $view = 'filament.tenant.home';

    public ?int $assetId = null;

    public string $description = '';

    public bool $emergency = false;

    public array $photos = [];

    protected function getViewData(): array
    {
        $p = app(TenantPortal::class);
        $u = auth()->user();
        $header = app(\App\Dashboards\HomeOverview::class)->header($u, \Carbon\CarbonImmutable::now());
        try {
            $lease = $p->myLease($u);
            return ['header' => $header, 'unit' => $p->myUnit($u), 'lease' => $lease, 'timeline' => $this->timeline($lease), 'invoices' => $p->myInvoices($u), 'reports' => $p->myReports($u), 'announcements' => $p->announcements($u), 'guidance' => $p->meterTransferGuidance()];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ['header' => $header, 'unit' => null, 'lease' => null, 'timeline' => null, 'invoices' => [], 'reports' => [], 'announcements' => [], 'guidance' => $p->meterTransferGuidance()];
        }
    }

    /** Positions (0–100 %) of today and the notice deadline on the lease span, plus days left — display only. */
    private function timeline(?array $lease): ?array
    {
        if (! $lease || empty($lease['starts_on']) || empty($lease['ends_on'])) {
            return null;
        }
        $start = \Carbon\CarbonImmutable::parse($lease['starts_on']);
        $end = \Carbon\CarbonImmutable::parse($lease['ends_on']);
        $span = max(1, $start->diffInDays($end));
        $at = fn ($d) => max(0, min(100, round($start->diffInDays(\Carbon\CarbonImmutable::parse($d), false) * 100 / $span, 1)));
        $today = \App\Support\Dates\Today::date();
        return [
            'today' => $at($today),
            'notice' => empty($lease['notice_deadline']) ? null : $at($lease['notice_deadline']),
            'days_to_notice' => empty($lease['notice_deadline']) ? null : (int) $today->diffInDays(\Carbon\CarbonImmutable::parse($lease['notice_deadline']), false),
            'days_to_end' => (int) $today->diffInDays($end, false),
        ];
    }

    public function report(): void
    {
        // photos or a short video, as the form offers (review M18: videos were rejected without a message)
        $this->validate(['description' => 'required|string|max:2000', 'photos.*' => 'file|mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime|max:20480'],
            [], ['description' => 'وصف المشكلة', 'photos.*' => 'المرفق']);
        $paths = array_map(fn ($f) => $f->store('subscribers/' . auth()->user()->subscriber_id . '/reports'), $this->photos);
        Ui::attempt(fn () => app(TenantPortal::class)->openReport(auth()->user(), $this->assetId ? Asset::find($this->assetId) : null, $this->description, $paths, $this->emergency), 'تم إرسال البلاغ');
        $this->reset(['assetId', 'description', 'emergency', 'photos']);
    }

    public function rate(int $workOrderId, int $rating): void
    {
        Ui::attempt(fn () => app(TenantPortal::class)->rate(auth()->user(), WorkOrder::findOrFail($workOrderId), $rating), 'شكراً لتقييمك');
    }

    public function pay(int $invoiceId): void
    {
        $link = Ui::attempt(fn () => app(TenantPortal::class)->payLink(auth()->user(), Invoice::findOrFail($invoiceId)), 'جارٍ تحويلك للدفع');
        if ($link) {
            $this->redirect($link['url']);
        }
    }
}
