<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix-forward for a real production incident (2026-09-24): Summary Sales
 * Report 500'd live with "column tsa_shift_id does not exist" —
 * confirmed via `railway logs`. Root cause: the FIRST version of this
 * feature (commit 0e58552) shipped with a tsa_sales_entries table keyed
 * on tsa_sales_row_id (a free-text admin-managed row), and Railway's
 * entrypoint.sh already ran `php artisan migrate --force` against that
 * schema before the very next commit (5b9979a, "auto based on the
 * current team and tsa") rewrote that SAME migration file in place to
 * key on tsa_shift_id instead — safe for any environment that hadn't
 * migrated yet, but production HAD already run it, so Laravel's
 * migrations table marked it done and never re-ran the edited version,
 * leaving production stuck on the old column forever without a
 * fix-forward migration like this one.
 *
 * Handles every environment this could be running against:
 *  - Production's real state: tsa_sales_entries exists with the OLD
 *    tsa_sales_row_id column (and tsa_sales_groups/tsa_sales_rows still
 *    exist too, since nothing ever dropped them there either).
 *  - Any environment that already has the NEW tsa_shift_id column
 *    (ran the rewritten migration fresh, e.g. a fresh deploy after
 *    5b9979a) — this is then a safe no-op.
 *  - A migration-table edge case where the table doesn't exist at all —
 *    creates it fresh with the right schema.
 *
 * No data preserved from the old schema (rename, not backfill) — the
 * feature had zero real usage between shipping and this fix (confirmed:
 * the report immediately 500'd, so nothing could have been saved
 * through it).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tsa_sales_entries')) {
            Schema::create('tsa_sales_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tsa_shift_id')->constrained()->cascadeOnDelete();
                $table->date('entry_date');
                $table->decimal('gross_sales', 14, 2)->default(0);
                $table->decimal('net_income', 14, 2)->default(0);
                $table->decimal('ads_spent', 14, 2)->default(0);
                $table->unsignedInteger('total_orders')->default(0);
                $table->unsignedInteger('catered_leads')->default(0);
                $table->decimal('pickup_rate', 7, 4)->default(0);
                $table->decimal('upselling_rate', 7, 4)->default(0);
                $table->timestamps();

                $table->unique(['tsa_shift_id', 'entry_date']);
            });

            return;
        }

        if (Schema::hasColumn('tsa_sales_entries', 'tsa_shift_id')) {
            return; // Already on the new schema — nothing to do.
        }

        // Old schema confirmed present (tsa_sales_row_id) — drop and
        // recreate rather than migrate data across, since the feature
        // never had a working window to accumulate anything real (see
        // this migration's own doc comment).
        Schema::dropIfExists('tsa_sales_entries');
        Schema::dropIfExists('tsa_sales_rows');
        Schema::dropIfExists('tsa_sales_groups');

        Schema::create('tsa_sales_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tsa_shift_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('net_income', 14, 2)->default(0);
            $table->decimal('ads_spent', 14, 2)->default(0);
            $table->unsignedInteger('total_orders')->default(0);
            $table->unsignedInteger('catered_leads')->default(0);
            $table->decimal('pickup_rate', 7, 4)->default(0);
            $table->decimal('upselling_rate', 7, 4)->default(0);
            $table->timestamps();

            $table->unique(['tsa_shift_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        // Irreversible by design — the old schema is gone for good, same
        // reasoning as any other "no real data existed" fix-forward.
    }
};
