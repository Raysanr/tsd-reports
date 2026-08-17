<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;

/**
 * Maps every existing Product row to its real Pancake catalog product_id
 * (confirmed one-by-one via GET /shops/{shopId}/products/variations, matched
 * on exact catalog name — see ProductPerformance::matchingOrders()'s doc
 * comment on the pancake_product_ids migration for why this exists) and adds
 * 3 new rows for real, separate Sinuxyl-family products that were previously
 * invisible as their own line — silently swept into the SINUXYL row by its
 * old bare-keyword text match instead.
 *
 * "CANPRO JUICE DRINK" confirmed as Pancake's real "CanPro Guyabano Herbal
 * Drink" (id 648) specifically, not "CanPro Bath Packs" (668) or "CanPro
 * Guyabano Oil" (666) — those two share no order data with our synced
 * "CANPRO JUICE DRINK" rows today, but the old "CANPRO" bare-keyword match
 * would have silently swept them in too on any day they did have orders.
 *
 * "CLEAR SIGHT" confirmed (both from synced order data — 19 of 20 recent
 * matches say "Clear Sight 3.0" verbatim, only 1 says plain "Clear Sight" —
 * and directly by the user) to mean Pancake's real "Clear Sight 3.0" (id 16),
 * not the separate plain "Clear Sight" (id 0-1) or "Clear Sight 3.0 +
 * Haplunas" bundle (id 626) products.
 */
return new class extends Migration
{
    private const MAPPINGS = [
        // display_name => real Pancake product_id (UUID)
        'SINUXYL'             => '30150c6f-6775-4b2d-a147-c0f5cfc7b2d1', // 643 Sinuxyl
        'AUDICURE'            => 'a2fe850e-dc13-4a7f-9623-c706d0fb4ad9', // 0-3 AudiCure
        'GINSENG SERUM'       => '772458fd-ebd7-492a-96f4-fdc1865d9db4', // 651 GINSENG SERUM
        'CANPRO JUICE DRINK'  => 'c0f358f8-1b1e-4215-bf3d-1772c103a58e', // 648 CanPro Guyabano Herbal Drink
        'SCAR CREAM'          => '36b64351-ff2f-46fb-b330-095f7a7711eb', // 700 Scar Cream
        'CLEAR SIGHT'         => '3c1e5840-04f1-42d7-a32d-69e3fea98551', // 16 Clear Sight 3.0
        'PTERYGIUM'           => 'a78d0ada-fc34-4784-917e-99ed37d68f20', // 0-9 Pterygium
        'GLAUCO FREE'         => 'b339a10b-3512-4e6d-bc3c-6a94f89194b7', // 667 GlaucoFree
        'LUMIEYES'            => '45fdaa3e-1d73-404e-8724-01edc0f40c48', // 694 LumiEyes
    ];

    /** New rows for real Sinuxyl-family products the old "SINUXYL" bare
     *  keyword was silently absorbing — see this migration's own doc comment
     *  above. Sort order 1 (SINUXYL is 0, AUDICURE is 3) keeps them grouped
     *  right after their sibling in the report. match_keyword left null so
     *  Product::getKeywordsArrayAttribute()'s fallback (the exact
     *  display_name) is what the text-matching path uses for any order not
     *  yet backfilled with a real ID — an exact "SINUXYL STEAM PACK" match
     *  can't accidentally re-absorb a plain "Sinuxyl" order the way the old
     *  bare "SINUXYL" keyword did. */
    private const NEW_SINUXYL_FAMILY_PRODUCTS = [
        ['display_name' => 'SINUXYL STEAM PACK',        'pancake_product_ids' => ['7df7ca90-2e2a-4784-a940-249c2a56b734']], // 698
        ['display_name' => 'SINUXYL ROLL ON OIL RELIEF', 'pancake_product_ids' => ['c6af3775-75d4-4e27-ad0f-2b5734932cbb']], // 301
        ['display_name' => 'SINUXYL NASAL INHALER',      'pancake_product_ids' => ['a415821d-ccbc-49fe-a230-b5cae150d054']], // 647
    ];

    public function up(): void
    {
        foreach (self::MAPPINGS as $displayName => $productId) {
            Product::where('display_name', $displayName)->update([
                'pancake_product_ids' => json_encode([$productId]),
            ]);
        }

        $sinuxyl = Product::where('display_name', 'SINUXYL')->first();
        if (!$sinuxyl) {
            return;
        }

        foreach (self::NEW_SINUXYL_FAMILY_PRODUCTS as $product) {
            // withTrashed() + restore(), not firstOrCreate() — Product uses
            // SoftDeletes, and firstOrCreate()'s "first" lookup excludes
            // trashed rows by default, so a rollback (down() below, a plain
            // ->delete(), i.e. soft) followed by re-running up() would create
            // a SECOND duplicate row instead of finding/restoring the
            // original (confirmed live during this migration's own
            // development — exactly this happened, leaving orphaned trashed
            // duplicates behind). This is safe to run repeatedly either way.
            //
            // firstOrCreate() goes through Eloquent (unlike the query-builder
            // update() calls above), so its 'array' cast on pancake_product_ids
            // already serializes a raw PHP array to JSON on save — passing an
            // already-json_encode()'d string here would double-encode it.
            $existing = Product::withTrashed()->where('display_name', $product['display_name'])->first();
            if ($existing) {
                $existing->restore();
                $existing->update([
                    'pancake_product_ids' => $product['pancake_product_ids'],
                    'team'                 => $sinuxyl->team,
                ]);
                continue;
            }

            Product::create([
                'display_name'         => $product['display_name'],
                'match_keyword'        => null,
                'pancake_product_ids'  => $product['pancake_product_ids'],
                'team'                 => $sinuxyl->team,
                'sort_order'           => 1,
            ]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::MAPPINGS) as $displayName) {
            Product::where('display_name', $displayName)->update(['pancake_product_ids' => null]);
        }

        Product::whereIn('display_name', array_column(self::NEW_SINUXYL_FAMILY_PRODUCTS, 'display_name'))->delete();
    }
};
