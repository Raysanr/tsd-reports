<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perf fix, 2026-10-11 — root-caused live via EXPLAIN ANALYZE after 2 prior
 * fix attempts (hoisting leadCountsByProductAndDate()/grossSalesByProductAndDate()
 * out of per-date/per-TSA loops in ExpectedIncomeController) still didn't
 * resolve a "Maximum execution time of 30 seconds exceeded" 500 on Summary
 * Sales Report. The REAL bottleneck: every one of these methods' own
 * `Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at)
 * BETWEEN ? AND ?')` queries is non-sargable — orders already has plain
 * btree indexes on pancake_inserted_at, pancake_created_at, and team
 * individually, but wrapping two of them in COALESCE() makes Postgres
 * unable to use ANY of those indexes, forcing a full sequential scan of
 * every row in the table (59,311+ in production) on every single call.
 * Confirmed via `EXPLAIN ANALYZE`: "Seq Scan on orders ... Rows Removed by
 * Filter: 59033" for a single team/day query. This same COALESCE(...)
 * pattern is used across 10 files (Dashboard, Leads Report, TSA
 * Performance, DSPPR, Expected Income, Summary Sales Report, and 3 console
 * commands) — an expression index fixes the bottleneck for all of them at
 * once, with zero application code changes, rather than rewriting every
 * call site's WHERE clause individually (higher risk of missing one).
 *
 * Postgres-only (functional/expression indexes aren't supported the same
 * way in SQLite, which local dev/tests use per CLAUDE.md, and the tiny
 * local dataset never needed this optimization in the first place) —
 * guarded behind a driver check so this migration is a safe no-op
 * everywhere except production.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS orders_coalesce_date_index ON orders (COALESCE(pancake_inserted_at, pancake_created_at))'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS orders_coalesce_date_index');
    }
};
