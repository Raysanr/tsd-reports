<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;

/**
 * Fixes a wrong Pancake product_id seeded for TO-02 by
 * 2026_08_18_000000_seed_pancake_product_ids_for_to_01_to_02 (that
 * migration's own file has been corrected too, but Laravel never re-runs an
 * already-applied migration — this is what actually corrects it wherever
 * that one already ran, production included).
 *
 * Root-caused 2026-08-20 (user report: Pancake POS's own "Products: TO-02"
 * filter showed 24 orders today, Leads Report's TO-02 row showed nothing at
 * all — not even a 0). The seeded ID, 'dbfad3a8-5591-47c7-a62a-a21e5866675c',
 * is a REAL Pancake catalog ID, just for a different product ("Taguro Oil -
 * Gold"), not TO-02. ProductPerformance::matchingOrders()'s ID-match branch
 * is authoritative once both sides have a real ID — it never falls through
 * to text/tag matching in that case — so every genuine TO-02 order (which
 * correctly carries TO-02's own real ID) failed the intersection check
 * against the wrong seeded ID and was silently excluded, unconditionally.
 * tally() correctly computed a real `total = 0`, which the page's `{{ $value
 * ?: '' }}` convention (used for every zero cell on this page, nothing
 * TO-02-specific) then rendered as a blank cell instead of "0".
 *
 * Confirmed live via tinker against today's real synced orders: exactly 24
 * carry the correct ID below, matching the user's own Pancake POS count
 * exactly; the wrong ID belongs to orders whose product is "Taguro Oil -
 * Gold".
 */
return new class extends Migration
{
    private const CORRECT_TO_02_ID = '07804cf8-a9b0-4900-936d-3dda15f6f324';
    private const WRONG_TO_02_ID   = 'dbfad3a8-5591-47c7-a62a-a21e5866675c';

    public function up(): void
    {
        // Unconditional update by display_name, same pattern the original
        // (proven-working) seed migration already used — deliberately NOT
        // guarded by a ->where('pancake_product_ids', ...) value check
        // (root-caused live on production 2026-08-20: Postgres's plain
        // `json` column type has no `=` operator at all, so that guard
        // crashed the migration outright — "operator does not exist: json =
        // unknown" — which, combined with docker/entrypoint.sh's `set -e`,
        // took the whole web service down instead of just failing to seed.
        // MySQL, this app's local dev driver, is more permissive here and
        // never surfaced it before deploy). 'TO-02' uniquely identifies one
        // row either way, so the guard added no real safety, only risk.
        Product::where('display_name', 'TO-02')
            ->update(['pancake_product_ids' => json_encode([self::CORRECT_TO_02_ID])]);
    }

    public function down(): void
    {
        Product::where('display_name', 'TO-02')
            ->update(['pancake_product_ids' => json_encode([self::WRONG_TO_02_ID])]);
    }
};
