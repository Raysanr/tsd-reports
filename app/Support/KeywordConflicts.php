<?php

namespace App\Support;

use App\Models\Product;
use App\Models\TsaShift;

/**
 * Detects silently-ambiguous keyword configuration before it silently
 * misattributes an order — see SyncTodayOrders::loadTsaMaps()/extractTsaInfo()
 * and Product::matchesText() for the real matching mechanics this mirrors.
 *
 * The three matching mechanisms are NOT uniform, so conflict detection isn't
 * either:
 *   - TSA tag keywords: EXACT match (an uppercased array-key lookup in
 *     $tsaMap) — a conflict is two TSAs sharing the identical keyword.
 *   - TSA seller keywords: substring match (str_contains) — a conflict is
 *     any pairwise substring containment between two different TSAs' lists.
 *   - Product keywords: substring match on Product::normalizeForMatch()
 *     output — same pairwise-containment reasoning as seller keywords, but
 *     using Product's own alphanumeric-only normalization, not plain
 *     lowercase/trim.
 */
class KeywordConflicts
{
    /**
     * TSA tag keywords must be checked for EXACT duplicates across different
     * TSAs (see SyncTodayOrders::loadTsaMaps — matching is an exact uppercase
     * lookup, not substring), never substring overlap.
     *
     * @return list<array{keyword: string, tsas: list<TsaShift>}>
     */
    public static function tsaTagDuplicates(): array
    {
        $shifts = TsaShift::orderBy('sort_order')->get();
        $byKeyword = [];

        foreach ($shifts as $shift) {
            foreach ($shift->tag_keywords_array as $kw) {
                $normalized = strtoupper(trim($kw));
                if ($normalized === '') continue;
                $byKeyword[$normalized][] = $shift;
            }
        }

        return collect($byKeyword)
            ->filter(fn ($shifts) => count($shifts) > 1)
            ->map(fn ($shifts, $keyword) => ['keyword' => $keyword, 'tsas' => $shifts])
            ->values()
            ->all();
    }

    /**
     * TSA seller keywords ARE substring-matched (SyncTodayOrders::extractTsaInfo,
     * the sellerMap fallback branch) — pairwise substring containment between
     * every two different TSAs' seller keyword lists is a real conflict.
     *
     * @return list<array{tsaA: TsaShift, keywordA: string, tsaB: TsaShift, keywordB: string}>
     */
    public static function tsaSellerOverlaps(): array
    {
        $shifts = TsaShift::orderBy('sort_order')->get()->values();
        $conflicts = [];

        foreach ($shifts as $i => $shiftA) {
            foreach ($shifts as $j => $shiftB) {
                if ($j <= $i) continue; // unordered pairs, each pair once

                foreach ($shiftA->seller_keywords_array as $kwA) {
                    $normA = strtolower(trim($kwA));
                    if ($normA === '') continue;

                    foreach ($shiftB->seller_keywords_array as $kwB) {
                        $normB = strtolower(trim($kwB));
                        if ($normB === '') continue;

                        if (str_contains($normA, $normB) || str_contains($normB, $normA)) {
                            $conflicts[] = [
                                'tsaA' => $shiftA, 'keywordA' => $kwA,
                                'tsaB' => $shiftB, 'keywordB' => $kwB,
                            ];
                        }
                    }
                }
            }
        }

        return $conflicts;
    }

    /**
     * Product keywords are substring-matched on NORMALIZED text
     * (Product::matchesText/normalizeForMatch) — same pairwise-overlap
     * reasoning as seller keywords, but using Product's own normalization
     * (strip everything but A-Z/0-9), not plain strtolower.
     *
     * @return list<array{productA: Product, keywordA: string, productB: Product, keywordB: string}>
     */
    public static function productKeywordOverlaps(): array
    {
        $products = Product::orderBy('sort_order')->get()->values();
        $conflicts = [];

        foreach ($products as $i => $productA) {
            foreach ($products as $j => $productB) {
                if ($j <= $i) continue;

                foreach ($productA->keywords_array as $kwA) {
                    $normA = Product::normalizeForMatch($kwA);
                    if ($normA === '') continue;

                    foreach ($productB->keywords_array as $kwB) {
                        $normB = Product::normalizeForMatch($kwB);
                        if ($normB === '') continue;

                        if (str_contains($normA, $normB) || str_contains($normB, $normA)) {
                            $conflicts[] = [
                                'productA' => $productA, 'keywordA' => $kwA,
                                'productB' => $productB, 'keywordB' => $kwB,
                            ];
                        }
                    }
                }
            }
        }

        return $conflicts;
    }

    /**
     * Live-tests a sample tag/cart-name string against ALL THREE matching
     * mechanisms simultaneously, exactly as SyncTodayOrders would — for the
     * "test this keyword" tool. Returns every match found (not just the first/
     * winning one), so an admin can see ambiguity directly, not just the
     * silent winner.
     *
     * @return array{tsaByTag: ?TsaShift, tsaBySeller: list<TsaShift>, products: list<Product>}
     */
    public static function testKeyword(string $sample): array
    {
        $sample = trim($sample);
        if ($sample === '') {
            return ['tsaByTag' => null, 'tsaBySeller' => [], 'products' => []];
        }

        $shifts   = TsaShift::orderBy('sort_order')->get();
        $products = Product::orderBy('sort_order')->get();

        // Exact match, mirroring $tsaMap's uppercased-key lookup.
        $normalizedTag = strtoupper($sample);
        $tsaByTag = $shifts->first(function ($shift) use ($normalizedTag) {
            foreach ($shift->tag_keywords_array as $kw) {
                if (strtoupper(trim($kw)) === $normalizedTag) return true;
            }
            return false;
        });

        // Substring match, mirroring the sellerMap fallback branch.
        $normalizedSeller = strtolower($sample);
        $tsaBySeller = $shifts->filter(function ($shift) use ($normalizedSeller) {
            foreach ($shift->seller_keywords_array as $kw) {
                $normKw = strtolower(trim($kw));
                if ($normKw !== '' && str_contains($normalizedSeller, $normKw)) return true;
            }
            return false;
        })->values()->all();

        // Real Product::matchesText(), so this uses the exact same
        // normalization/substring logic inferTeamFromProduct() relies on.
        $matchedProducts = $products->filter(fn ($product) => $product->matchesText($sample))->values()->all();

        return ['tsaByTag' => $tsaByTag, 'tsaBySeller' => $tsaBySeller, 'products' => $matchedProducts];
    }
}
