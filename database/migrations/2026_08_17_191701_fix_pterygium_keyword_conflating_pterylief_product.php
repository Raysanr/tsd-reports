<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;

/**
 * Same bug class as the SINUXYL fix (see match_exclude_keyword migration's
 * doc comment), pre-existing rather than introduced today: PTERYGIUM's
 * match_keyword already included "PTERYLIEF" as a second alias — but
 * confirmed live via GET /shops/{shopId}/products/variations?search=Pterylief
 * that "Pterylief Eye Drops" (display_id "101") is a genuinely SEPARATE real
 * Pancake product, its own product_id, not a variant/rebrand of Pterygium
 * (display_id "0-9"). Surfaced during today's per-product text-fallback
 * audit: 1 of today's PTERYGIUM matches was a "Pterylief Eye Drops" order
 * with no real Pterygium item on it at all.
 *
 * Removes PTERYLIEF from the positive keyword (so a bare "PTERYLIEF" mention
 * stops matching PTERYGIUM) and adds it to match_exclude_keyword as a
 * defense-in-depth guard, same as SINUXYL's sibling exclude list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Product::where('display_name', 'PTERYGIUM')->update([
            'match_keyword'          => 'PTERYGIUM',
            'match_exclude_keyword'  => 'PTERYLIEF',
        ]);
    }

    public function down(): void
    {
        Product::where('display_name', 'PTERYGIUM')->update([
            'match_keyword'          => 'PTERYGIUM,PTERYLIEF',
            'match_exclude_keyword'  => null,
        ]);
    }
};
