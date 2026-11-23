<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Database-level guarantees (spec §4.7, §11.2):
 *  - Row-Level Security per subscriber (REQ-0169, REQ-0487) — enforced by PostgreSQL even for the table owner.
 *  - Append-only tables (REQ-0068, REQ-0497) and archive-only tables (REQ-0168).
 */
final class Rls
{
    /** SQL expression for the subscriber of the current request/job, or NULL when none is set. */
    public const CURRENT = "nullif(current_setting('app.subscriber_id', true), '')::bigint";

    /** Strict tenant isolation: rows are visible and writable only inside their subscriber's context. */
    public static function enable(string $table, string $column = 'subscriber_id'): void
    {
        DB::statement("ALTER TABLE \"$table\" ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE \"$table\" FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON \"$table\"");
        DB::statement("CREATE POLICY tenant_isolation ON \"$table\" USING (\"$column\" = " . self::CURRENT . ') WITH CHECK ("' . $column . '" = ' . self::CURRENT . ')');
    }

    /** Tenant isolation where NULL-subscriber rows belong to the platform and are visible only outside any subscriber context. */
    public static function enableWithPlatformRows(string $table, string $column = 'subscriber_id'): void
    {
        DB::statement("ALTER TABLE \"$table\" ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE \"$table\" FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON \"$table\"");
        $expr = "\"$column\" IS NOT DISTINCT FROM " . self::CURRENT;
        DB::statement("CREATE POLICY tenant_isolation ON \"$table\" USING ($expr) WITH CHECK ($expr)");
    }

    /** Rows can be inserted and read, never updated, deleted or truncated. */
    public static function appendOnly(string $table): void
    {
        self::guardFunction();
        DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON \"$table\" FOR EACH ROW EXECUTE FUNCTION app_reject_change('append-only')");
        DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON \"$table\" FOR EACH STATEMENT EXECUTE FUNCTION app_reject_change('append-only')");
    }

    /** Rows can never be physically deleted — archive instead (set archived_at). */
    public static function archiveOnly(string $table): void
    {
        self::guardFunction();
        DB::statement("CREATE TRIGGER {$table}_archive_only BEFORE DELETE ON \"$table\" FOR EACH ROW EXECUTE FUNCTION app_reject_change('archive-only')");
        DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON \"$table\" FOR EACH STATEMENT EXECUTE FUNCTION app_reject_change('archive-only')");
    }

    private static function guardFunction(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_reject_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'table % is %: % is not allowed', TG_TABLE_NAME, TG_ARGV[0], TG_OP
                    USING ERRCODE = 'P0001';
            END $$
        SQL);
    }
}
