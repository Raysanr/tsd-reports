<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Projections (explicit request, 2026-10-02: "add
 * (add projection) button and then when user click that it will pop up
 * modal that can select month"). Projections had NO per-month concept at
 * all before this — one single live snapshot of rates/targets shared by
 * every visit, matching the month-picker's own earlier "no functions for
 * now" placeholder (explicit follow-up, same day: now wired up for real).
 *
 * Each real month gets its own independently-saved set of the 7 columns'
 * own inputs (Net Income Target, AOV, overrides, is_locked, ...) — `month`
 * is a plain 'Y-m' string (e.g. "2026-10"), not a real DATE column, since
 * a month here is always a whole calendar month, never a specific day.
 * The unique constraint moves from `key` alone to `(key, month)` so the
 * SAME 7 keys can exist once per month without colliding.
 *
 * Deliberately NOT per-month (explicit scope decision, 2026-10-02, to keep
 * this change contained): the shared %-rate Settings (Tax Allocation,
 * Cancelled, Returns, ...) stay ONE global set used by every month, same
 * as today — only each column's OWN stored inputs become per-month.
 * Custom row DEFINITIONS (ProjectionCustomRow — name/section/fixed) stay
 * shared too; only their typed-in %-rate VALUES already live in the same
 * global Settings table as the built-in rates, so no separate decision
 * was needed there.
 *
 * Every EXISTING row is backfilled to the current real month (not some
 * placeholder) so a deploy never loses today's already-live numbers —
 * whoever is viewing "this month" right after this migration runs keeps
 * seeing exactly what was there before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projection_columns', function (Blueprint $table) {
            $table->string('month', 7)->nullable()->after('key');
        });

        DB::table('projection_columns')->whereNull('month')->update(['month' => now()->format('Y-m')]);

        Schema::table('projection_columns', function (Blueprint $table) {
            $table->string('month', 7)->nullable(false)->change();
            $table->dropUnique(['key']);
            $table->unique(['key', 'month']);
        });
    }

    public function down(): void
    {
        Schema::table('projection_columns', function (Blueprint $table) {
            $table->dropUnique(['key', 'month']);
            $table->dropColumn('month');
            $table->unique(['key']);
        });
    }
};
