<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Root cause of the Leads Report / Dashboard "Total Leads" not tallying with
 * Pancake POS's own exact-product-ID filter (confirmed live, 2026-08-17: 111
 * here vs 103 in POS for the same day/team/products): ProductPerformance
 * matches an order to a product via bare substring text matching against
 * order.product/base_product/bundle_description/raw_tags — e.g. Product
 * "SINUXYL" (keyword "SINUXYL") swept in real, separate Pancake products
 * "Sinuxyl Nasal Spray" and "Sinuxyl Steam Pack" just because their names
 * happen to contain the word "Sinuxyl", even though Pancake's own catalog
 * (confirmed via GET /shops/{shopId}/products/variations) treats them as
 * entirely distinct products with their own real UUID product_id — the same
 * ID POS's Products filter panel lets you pick by (its numeric badges, e.g.
 * "643", are that real product's display_id).
 *
 * orders.pancake_product_ids: every line item's real product_id on that
 * order (JSON array of UUID strings) — captured going forward by
 * SyncTodayOrders, backfilled for already-synced rows by
 * BackfillOrderProductIds. A combo order can genuinely contain 2+ real
 * products, so this is an array, not a single value.
 *
 * products.pancake_product_ids: the real Pancake product_id(s) this report
 * row represents — normally one, but kept as an array (not a single column)
 * for symmetry and in case a report row is ever meant to deliberately merge
 * 2+ real Pancake products later. Null until seeded — ProductPerformance
 * falls back to today's text matching for any product/order that doesn't
 * have IDs yet, so historical date ranges synced before this migration
 * don't go blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('pancake_product_ids')->nullable()->after('bundle_description');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->json('pancake_product_ids')->nullable()->after('match_keyword');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('pancake_product_ids');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('pancake_product_ids');
        });
    }
};
