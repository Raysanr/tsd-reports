<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // The customer's originally-ordered item, independent of `product` (which
            // holds the UPSOLD item's name once an order carries an upsell — the
            // TSA-credit convention SyncTodayOrders::extractUpsellProduct() already
            // uses). Without this, an order that started as e.g. AudiCure but was
            // upsold to Ear Relief Balm has no remaining signal anywhere pointing back
            // to AudiCure (confirmed in production: neither `product`,
            // `bundle_description`, nor `raw_tags` mention it for this team/combo),
            // so Leads Report's per-product count silently loses it — even though
            // Pancake's own product search still finds it (it matches cart contents,
            // not upsell attribution). See ProductPerformance::matchingOrders(),
            // which now matches against this column too, alongside `product`.
            $table->string('base_product')->nullable()->after('product');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('base_product');
        });
    }
};
