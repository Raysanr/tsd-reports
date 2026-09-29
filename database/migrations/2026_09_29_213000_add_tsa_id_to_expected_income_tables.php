<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Expected Income (explicit request, 2026-09-30: "i
 * want you to change the dates into per TSA and per team ... i want to add
 * team filter ... and the date [row] will be now for example TSA NAME and
 * the still the products like that"). Adds a TSA dimension on top of the
 * existing (product, day) grain — confirmed via AskUserQuestion this is a
 * real new data-entry axis, not just a display regrouping: each real TSA
 * now gets her OWN numbers per product per day, editable independently of
 * every other TSA's.
 *
 * tsa_id is NULLABLE (explicit decision) rather than backfilled/required —
 * every row that already exists today keeps meaning exactly what it always
 * has (a product-level total for that day, tsa_id = NULL) and continues to
 * back the page's own "ALL" view (TELESALES EXPECTED PERFORMANCE overall +
 * the flat per-product cards) exactly as before. A NEW row with a real
 * tsa_id is what powers a specific TSA's own card when her team is
 * selected. TEAM OPENING SHIFT / TEAM CLOSING SHIFT's own rollup cards are
 * NOT stored anywhere (explicit decision, "auto-sum from real TSA
 * entries") — always computed fresh by summing that team's own real
 * tsa_id-keyed rows, same "never a second, driftable copy of a number this
 * app can already compute" convention as Cost Breakdown's own TOTAL SALARY
 * OF TSD.
 *
 * The old unique(product_id, entry_date) can't stay as-is once multiple
 * TSAs can each have their own row for the same product+day — replaced
 * with unique(product_id, entry_date, tsa_id). MySQL/MariaDB (confirmed
 * this app's own dev DB, and its production driver) treats NULL as
 * distinct in a unique index, so the existing NULL-tsa_id product-level
 * rows keep colliding correctly with each other (one per product per day,
 * same as before) while still allowing many different real tsa_id rows
 * for that same product+day.
 *
 * The new unique index is always added BEFORE its old replacement is
 * dropped (never the reverse) in both up() and down() — confirmed live on
 * this app's own dev DB that MySQL/MariaDB refuses to drop an index while
 * it's the ONLY one covering a foreign key column ("Cannot drop index ...
 * needed in a foreign key constraint"). And expected_income_custom_values'
 * own replacement index keeps a permanent _new suffix rather than being
 * renamed back to the original name afterward — this dev DB runs MariaDB
 * 10.4, which doesn't support RENAME INDEX (added in MariaDB 10.5.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        // The new unique index is added BEFORE the old one is dropped (not
        // after) — MySQL refuses to drop product_id_entry_date_unique while
        // it's still the only index backing product_id's own foreign key
        // constraint ("Cannot drop index ... needed in a foreign key
        // constraint", confirmed live on this app's own dev DB). Adding the
        // replacement first means product_id always has SOME covering
        // index, so the old one is safe to drop afterward.
        Schema::table('expected_income_entries', function (Blueprint $table) {
            $table->foreignId('tsa_id')->nullable()->after('product_id')->constrained('tsa_shifts')->nullOnDelete();
            $table->unique(['product_id', 'entry_date', 'tsa_id']);
        });
        Schema::table('expected_income_entries', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'entry_date']);
        });

        // Named ei_custom_values_unique_new rather than reusing/renaming
        // the old ei_custom_values_unique — this app's own MariaDB 10.4
        // dev DB doesn't support RENAME INDEX (added in MariaDB 10.5.2),
        // so the new index just keeps its own distinct name permanently
        // instead of one more DDL step that would fail on this database.
        Schema::table('expected_income_custom_values', function (Blueprint $table) {
            $table->foreignId('tsa_id')->nullable()->after('product_id')->constrained('tsa_shifts')->nullOnDelete();
            $table->unique(['product_id', 'entry_date', 'custom_row_key', 'tsa_id'], 'ei_custom_values_unique_new');
        });
        Schema::table('expected_income_custom_values', function (Blueprint $table) {
            $table->dropUnique('ei_custom_values_unique');
        });
    }

    public function down(): void
    {
        // Same "add the covering index before dropping the old one" order
        // as up() — product_id's own FK always needs SOME index covering
        // it, so the (product_id, entry_date) replacement goes in first.
        Schema::table('expected_income_entries', function (Blueprint $table) {
            $table->unique(['product_id', 'entry_date']);
        });
        Schema::table('expected_income_entries', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'entry_date', 'tsa_id']);
            $table->dropConstrainedForeignId('tsa_id');
        });

        Schema::table('expected_income_custom_values', function (Blueprint $table) {
            $table->unique(['product_id', 'entry_date', 'custom_row_key'], 'ei_custom_values_unique');
        });
        Schema::table('expected_income_custom_values', function (Blueprint $table) {
            $table->dropUnique('ei_custom_values_unique_new');
            $table->dropConstrainedForeignId('tsa_id');
        });
    }
};
