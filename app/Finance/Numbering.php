<?php

namespace App\Finance;

use App\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free document numbers per subscriber, kind and year (REQ-0366 numbering patterns). The pattern comes from
 * the subscriber setting finance.numbering[kind] with {YYYY} and {SEQ:n}; until the subscriber sets one, a plain
 * "{KIND}-{YYYY}-{SEQ:6}" form is used (QUESTIONS.md Q10 lists defaults not given by the spec).
 */
class Numbering
{
    public function __construct(private Settings $settings)
    {
    }

    public function next(string $kind, int $year): string
    {
        $sid = app(\App\Tenancy\TenantContext::class)->subscriberId() ?? throw new FinanceException('No subscriber in context.');
        $n = DB::transaction(function () use ($sid, $kind, $year) {
            DB::statement('INSERT INTO document_sequences (subscriber_id, kind, year, last_number) VALUES (?, ?, ?, 0) ON CONFLICT (subscriber_id, kind, year) DO NOTHING', [$sid, $kind, $year]);
            return (int) DB::selectOne('UPDATE document_sequences SET last_number = last_number + 1 WHERE subscriber_id = ? AND kind = ? AND year = ? RETURNING last_number', [$sid, $kind, $year])->last_number;
        });
        $pattern = ($this->settings->get('finance.numbering') ?? [])[$kind] ?? strtoupper($kind) . '-{YYYY}-{SEQ:6}';
        return preg_replace_callback('/\{SEQ:(\d+)\}/', fn ($m) => str_pad((string) $n, (int) $m[1], '0', STR_PAD_LEFT), str_replace('{YYYY}', (string) $year, $pattern));
    }
}
