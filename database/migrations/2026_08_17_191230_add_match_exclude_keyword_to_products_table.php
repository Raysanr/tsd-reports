<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Root-caused live (2026-08-17, today's SINUXYL total: 72 here vs Pancake
 * POS's exact "643 Sinuxyl" filter showing 58): even with real
 * pancake_product_ids matching in place, the text-matching FALLBACK path
 * (used whenever an order's product_id wasn't captured — an ad-hoc,
 * not-in-catalog line item like a manually-typed "Sinuxyl Nasal Spray" can
 * never get one, since Pancake itself has no catalog entry to point to)
 * still substring-matched SINUXYL's bare "SINUXYL" keyword against real,
 * separate sibling products' names — "Sinuxyl Steam Pack", "Sinuxyl Roll On
 * Oil Relief", "Sinuxyl Nasal Inhaler", "Sinuxyl Nasal Spray" — 7 of today's
 * 72 matched orders this way with no real Sinuxyl (643) item anywhere on the
 * order at all.
 *
 * match_exclude_keyword (comma-separated, same convention as match_keyword)
 * is checked in the text-fallback path only: a match_keyword hit is
 * discarded if the same text ALSO contains one of these. Only SINUXYL needs
 * it — confirmed via Pancake's own product catalog search that none of the
 * other 8 mapped products (AUDICURE, GINSENG SERUM, CANPRO JUICE DRINK,
 * SCAR CREAM, CLEAR SIGHT, PTERYGIUM, GLAUCO FREE, LUMIEYES) have a real,
 * separate sibling product sharing their keyword as a substring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('match_exclude_keyword')->nullable()->after('match_keyword');
        });

        Product::where('display_name', 'SINUXYL')->update([
            'match_exclude_keyword' => 'STEAM PACK,ROLL ON,NASAL INHALER,NASAL SPRAY',
        ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('match_exclude_keyword');
        });
    }
};
