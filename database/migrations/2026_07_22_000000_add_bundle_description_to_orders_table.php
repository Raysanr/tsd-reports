<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Pancake's variation_info.display_id for the item stored in `product`
            // (e.g. "1 Ginseng Serum + 5 Scar Cream") — the full bundle/combo
            // description, as opposed to `product` which only ever holds the
            // catalog entry's generic variation_info.name ("GINSENG SERUM"). A combo
            // SKU's name reflects just its primary component; the other products
            // bundled inside it were completely invisible to product-matching until
            // now (confirmed in production: a Ginseng Serum + Scar Cream combo order
            // never counted toward Scar Cream at all). See ProductPerformance::
            // buildRow(), which now matches against this column too.
            $table->string('bundle_description')->nullable()->after('product');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('bundle_description');
        });
    }
};
