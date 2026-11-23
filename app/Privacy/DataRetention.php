<?php

namespace App\Privacy;

use App\Audit\AuditLogger;
use App\Models\ImportBatch;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Retention periods per data type (REQ-0371, REQ-0444 PDPL "سياسة احتفاظ"). The office (data controller) sets a period
 * in months for each type; an empty period keeps the data. The platform suggests no legal duration (not in the spec).
 *
 * Only operational personal data is covered. Financial records, contracts and the audit log are never deleted
 * (absolute rule), and interested people on the building waitlist already leave the list when they stop confirming,
 * with their data erased (REQ-0405).
 */
final class DataRetention
{
    /** type => [Arabic label, what is removed] */
    public const TYPES = [
        'import_files' => ['ملفات الانتقال السريع', 'الملف المرفوع (عقد أو صورة) والقيم المستخرجة منه، بعد تأكيد الدفعة أو تجاهلها. العقد المنشأ منها يبقى.'],
        'notifications' => ['سجل الإشعارات المرسلة', 'نصوص الرسائل المرسلة أو المقروءة أو الفاشلة. عدّاد الاستهلاك يبقى.'],
        'qr_scans' => ['سجل مسح باركود المبنى', 'تفاصيل كل مسح (المصدر والصفحة المحيلة).'],
        'offline_sync' => ['سجل مزامنة الفني', 'سجل عمليات المزامنة دون اتصال بعد تطبيقها.'],
    ];

    public function __construct(private Settings $settings)
    {
    }

    /** @return array<string, ?int> months per type, null = keep */
    public function periods(): array
    {
        $set = (array) ($this->settings->get('data.retention') ?? []);
        $out = [];
        foreach (array_keys(self::TYPES) as $type) {
            $v = $set[$type] ?? null;
            $out[$type] = is_int($v) && $v > 0 ? $v : null;
        }
        return $out;
    }

    /** @param array<string, ?int> $periods */
    public function save(array $periods): void
    {
        $clean = [];
        foreach (array_keys(self::TYPES) as $type) {
            $v = $periods[$type] ?? null;
            if ($v !== null && $v !== '') {
                $v = (int) $v;
                if ($v < 1 || $v > 600) {
                    throw new \InvalidArgumentException('مدة الاحتفاظ بالأشهر بين 1 و600، أو اتركها فارغة للاحتفاظ دون حذف.');
                }
                $clean[$type] = $v;
            }
        }
        if ($clean === array_filter($this->periods(), fn ($v) => $v !== null)) {
            return; // unchanged: no audit entry
        }
        $this->settings->setSubscriber('data.retention', $clean);
    }

    /** Apply the periods for the current subscriber. Returns the number of records removed per type. */
    public function apply(CarbonImmutable $now): array
    {
        $done = [];
        foreach ($this->periods() as $type => $months) {
            if ($months === null) {
                continue;
            }
            $before = $now->subMonthsNoOverflow($months);
            $count = match ($type) {
                'import_files' => $this->purgeImports($before),
                'notifications' => DB::table('notifications_outbox')->whereIn('status', ['sent', 'read', 'failed', 'blocked_provider', 'blocked_template'])->where('created_at', '<', $before)->delete(),
                'qr_scans' => DB::table('qr_scans')->where('scanned_at', '<', $before)->delete(),
                'offline_sync' => DB::table('offline_sync_ops')->where('created_at', '<', $before)->delete(),
            };
            if ($count > 0) {
                $done[$type] = $count;
                AuditLogger::record('data.retention.applied', 'data_retention', null, ['type' => $type, 'months' => $months, 'removed' => $count, 'before' => $before->toDateString()], $type);
            }
        }
        return $done;
    }

    private function purgeImports(CarbonImmutable $before): int
    {
        $n = 0;
        $disk = Storage::disk(config('filesystems.default'));
        ImportBatch::whereIn('status', ['confirmed', 'discarded'])->where('updated_at', '<', $before)->where('file_path', '!=', '')
            ->each(function (ImportBatch $b) use ($disk, &$n) {
                if ($b->file_path && $disk->exists($b->file_path)) {
                    $disk->delete($b->file_path);
                }
                $b->forceFill(['file_path' => '', 'extracted' => [], 'reviewed' => null])->save();
                $n++;
            });
        return $n;
    }
}
