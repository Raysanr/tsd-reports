<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;

/**
 * TO-01/TO-02 were missed by the 2026-08-17 seed migration (seed_real_pancake_
 * product_ids_for_leads_report_products) — same fix, same reasoning, just the
 * 2 products that pass left out.
 *
 * Root-caused 2026-08-18 (production: Eyecare's TO-02 row showing 56 leads
 * for a day Pancake's own "Products: TO-02" filter shows only 27): TO-01 and
 * TO-02 are two separate real Pancake catalog products (confirmed via GET
 * /shops/{shopId}/products/variations — display_id 908 and 909, distinct
 * product_id UUIDs, each with its OWN set of price-tier variations), but
 * neither had a real ID captured here, so every order matched them by bare
 * text instead (ProductPerformance::matchingOrders()'s fallback path).
 *
 * TO-01 sells in price-tier variations, several of which are literally
 * named things like "1 TO-01 + 1 TO-02" (a bundle/promo tier — still ONE
 * catalog line item under TO-01's own product_id, not a second real TO-02
 * item). The bare-keyword/bundle_description text match saw "TO-02" inside
 * that label and wrongly counted the order toward TO-02's row too —
 * confirmed live on order #1351808: one line item, product_id = TO-01's own
 * UUID, variation label "1 TO-01 + 1 TO-02", ₱798. No second line item, no
 * real TO-02 product_id anywhere on the order.
 *
 * Seeding both real IDs here makes ID matching (authoritative, skips every
 * text heuristic once both sides have real IDs — see matchingOrders()'s own
 * doc comment) take over for any order whose own pancake_product_ids is
 * already populated. Already-synced orders (yesterday included) only pick
 * this up once re-synced — see Sync Health's "Retry a Date" for 2026-08-17,
 * which re-upserts pancake_product_ids on existing rows; a fresh sync-today
 * run already writes it going forward with no further action needed.
 */
return new class extends Migration
{
    private const MAPPINGS = [
        // display_name => real Pancake product_id (UUID)
        'TO-01' => '6fd93f83-855a-444c-81df-060cd4eed35b', // 908 TO-01
        'TO-02' => 'dbfad3a8-5591-47c7-a62a-a21e5866675c', // 909 TO-02
    ];

    public function up(): void
    {
        foreach (self::MAPPINGS as $displayName => $productId) {
            Product::where('display_name', $displayName)->update([
                'pancake_product_ids' => json_encode([$productId]),
            ]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::MAPPINGS) as $displayName) {
            Product::where('display_name', $displayName)->update(['pancake_product_ids' => null]);
        }
    }
};
