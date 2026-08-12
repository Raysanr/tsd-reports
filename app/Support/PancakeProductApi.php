<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ported from call-tracker (merged into one app 2026-08-12), unmodified.
 *
 * Searches Pancake POS's real product/variation catalog — GET
 * /shops/{SHOP_ID}/products/variations?search=... (confirmed against the
 * real OpenAPI spec at docs.pancake.biz/pos/api/openapi.json, and against a
 * real live call: each result IS a sellable variation directly — e.g. "3
 * Sinuxyl" at ₱999, "4 Sinuxyl" at ₱1000 — not nested under a parent
 * product), the exact endpoint behind Pancake POS's own "quick add
 * products" search when building an order. Same host/auth convention as
 * PancakeOrderTagApi.
 */
class PancakeProductApi
{
    private const BASE_URL = 'https://pos.pages.fm/api/v1';

    /**
     * Normalized [{variation_id, product_id, name, retail_price}], Pancake's
     * own relevance order. Empty on no credentials, a blank query, or an
     * unreachable/erroring API — same "feature unavailable, not fatal"
     * convention as PancakeOrderTagApi::listTags().
     */
    public function search(string $query): array
    {
        $apiKey = Setting::get('pancake_api_key', '');
        $shopId = Setting::get('shop_id', '');
        if (empty($apiKey) || empty($shopId) || trim($query) === '') {
            return [];
        }

        try {
            $response = Http::timeout(10)->get(self::BASE_URL . "/shops/{$shopId}/products/variations", [
                'api_key'   => $apiKey,
                'search'    => $query,
                'page_size' => 20,
            ]);

            if (!$response->successful()) {
                return [];
            }

            // display_id (e.g. "3 Sinuxyl") is what a TSA actually sees in
            // Pancake POS's own quick-add row — the quantity tier is part of
            // the name, not a separate field to combine later — so it's used
            // here as-is rather than product.name alone, which would show
            // every quantity tier of the same product as identical rows.
            return collect($response->json('data') ?? [])
                ->filter(fn ($v) => !empty($v['id']))
                ->map(fn ($v) => [
                    'variation_id' => $v['id'],
                    'product_id'   => $v['product_id'] ?? null,
                    'name'         => $v['display_id'] ?? ($v['product']['name'] ?? ''),
                    'retail_price' => (float) ($v['retail_price'] ?? 0),
                ])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('PancakeProductApi: search threw', ['message' => $e->getMessage()]);
            return [];
        }
    }
}
