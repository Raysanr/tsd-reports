<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Expected Income 2026. Fixes a real production
 * outage (500 on /data/expected-income, root-caused 2026-09-26): the
 * create_expected_income_entries_table migration originally created a
 * `month` column; a later same-session rebuild edited that SAME migration
 * FILE in place to rename it to `entry_date` for per-day storage instead
 * of per-month. That edit was pushed and auto-deployed to Railway AFTER
 * the original version had already run there — Laravel tracks migrations
 * by filename in its own `migrations` table, so the edited file was never
 * re-executed on production, leaving its real database with a `month`
 * column while every deployed line of code (model, controller, view)
 * expects `entry_date`. Every query against the mismatched column threw,
 * which is what actually produced the 500.
 *
 * This migration is the real, separate fix: a proper rename that runs
 * fresh on any environment (idempotent both ways — a no-op on a database
 * that already has `entry_date` from running the edited file directly,
 * e.g. this session's own local dev DB). Never edit an already-migrated
 * migration file again for exactly this reason — always add a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('expected_income_entries', 'month') && ! Schema::hasColumn('expected_income_entries', 'entry_date')) {
            Schema::table('expected_income_entries', function (Blueprint $table) {
                $table->renameColumn('month', 'entry_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('expected_income_entries', 'entry_date') && ! Schema::hasColumn('expected_income_entries', 'month')) {
            Schema::table('expected_income_entries', function (Blueprint $table) {
                $table->renameColumn('entry_date', 'month');
            });
        }
    }
};
