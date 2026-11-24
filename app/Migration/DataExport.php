<?php

namespace App\Migration;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Data ownership (REQ-0498, REQ-0586, rule R-20): the subscriber exports ALL its data — contracts, payments, assets,
 * counts, documents — in one click, in open formats (CSV + document files), any time, free, without support.
 * RLS guarantees only the subscriber's own rows are exported. Encrypted columns are exported decrypted to the
 * data controller (the subscriber) through the Eloquent models.
 */
class DataExport
{
    /** table => model class (null = raw table) */
    public const TABLES = [
        'owners' => \App\Models\Owner::class, 'portfolios' => null, 'properties' => null, 'ownership_shares' => null,
        'management_agreements' => null, 'buildings' => null, 'spaces' => null, 'leasable_units' => null, 'rate_plans' => null,
        'tenants' => \App\Models\Tenant::class, 'lease_offers' => null, 'leases' => null, 'billing_schedule_lines' => null, 'rent_escalations' => null,
        'invoices' => null, 'invoice_lines' => null, 'payments' => null, 'payment_allocations' => null, 'journal_entries' => null, 'journal_lines' => null,
        'assets' => null, 'warranties' => null, 'vendors' => null, 'ppm_plans' => null, 'work_orders' => null, 'cost_lines' => null,
        'inventory_counts' => null, 'inventory_lines' => null, 'prospects' => \App\Models\Prospect::class, 'audit_logs' => null,
        'subscriber_settings' => null, 'reconciliation_issues' => null,
    ];

    public function __construct(private TenantContext $ctx)
    {
    }

    /** Build the archive and return its storage path. */
    public function export(): string
    {
        $sid = $this->ctx->subscriberId() ?? throw new MigrationException('No subscriber in context.');
        $dir = storage_path('app/private/exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . "/subscriber-$sid-" . now()->format('Ymd-His') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new MigrationException('Cannot create the export archive.');
        }
        foreach (self::TABLES as $table => $model) {
            $rows = $model ? $model::query()->orderBy('id')->get()->map(fn ($m) => $m->makeVisible($m->getHidden())->attributesToArray())->all()
                : DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            $zip->addFromString("$table.csv", $this->csv($rows));
        }
        foreach (Storage::disk(config('filesystems.default'))->allFiles("subscribers/$sid") as $file) {   // document files
            $zip->addFromString('documents/' . substr($file, strlen("subscribers/$sid/")), Storage::disk(config('filesystems.default'))->get($file));
        }
        $zip->addFromString('README.txt', "Export of all data of subscriber $sid. CSV files are UTF-8 with a header row; amounts are integer halalas (*_minor).\n");
        $zip->close();
        return $path;
    }

    private function csv(array $rows): string
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel opens Arabic correctly
        if ($rows) {
            fputcsv($h, array_keys($rows[0]), escape: '');
            foreach ($rows as $r) {
                fputcsv($h, array_map(fn ($v) => self::cell(is_array($v) || is_object($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v), $r), escape: '');
            }
        }
        rewind($h);
        return stream_get_contents($h);
    }

    /** A text cell that Excel would run as a formula (=, +, -, @, tab, CR) is prefixed with ' (review M18: the public
     *  waitlist form fills prospects' names, so =HYPERLINK(...) could run when the manager opens the export). */
    private static function cell(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && ! is_numeric($v) && in_array($v[0], ['=', '+', '-', '@', "	", ""], true) ? "'" . $v : $v;
    }
}
