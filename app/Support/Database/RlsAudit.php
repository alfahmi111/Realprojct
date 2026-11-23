<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Every table holding subscriber data must have row-level security enabled and forced (REQ-0169, REQ-0487).
 * The only exceptions are public lookup maps resolved before any subscriber is known, holding no personal data.
 */
final class RlsAudit
{
    public const EXEMPT = [
        'qr_codes' => 'public short code → (subscriber, building) map for the building page; no personal data; append-only',
        'lease_offer_tokens' => 'public offer-link token hash → subscriber map; no personal data; the offer stays under RLS',
    ];

    /** @return list<string> tables with a subscriber_id column but without RLS enabled and forced */
    public static function missing(): array
    {
        $rows = DB::select("select c.relname as name, c.relrowsecurity as rls, c.relforcerowsecurity as force
            from pg_class c join pg_namespace n on n.oid = c.relnamespace
            join information_schema.columns col on col.table_name = c.relname and col.table_schema = n.nspname
            where col.column_name = 'subscriber_id' and c.relkind = 'r' and n.nspname = current_schema()");
        return collect($rows)->reject(fn ($r) => isset(self::EXEMPT[$r->name]) || ($r->rls && $r->force))->pluck('name')->sort()->values()->all();
    }
}
