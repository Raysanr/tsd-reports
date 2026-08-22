<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ported from call-tracker (merged into one app 2026-08-12), unmodified.
 *
 * Talks to Pancake's REAL POS order-tags API — confirmed against the real
 * OpenAPI spec at docs.pancake.biz/pos/api/openapi.json (GET/PUT
 * /shops/{SHOP_ID}/orders/{ORDER_ID}, GET /shops/{SHOP_ID}/orders/tags).
 * This is the tag system this app's own sync actually reads. It is NOT the
 * same resource as PancakeConversationApi's Messenger/conversation-scoped
 * tags: confirmed live that a real page's "Add tag" popup in Pancake POS
 * itself (product/disposition/TSA-name tags like SINUXYL, PTERYGIUM,
 * KATHERINE) shares none of those names with that page's conversation tag
 * catalog — they are two entirely separate tag systems on two different
 * hosts. Anything meant to be visible to reporting, or to match what a
 * TSA actually sees in Pancake POS's own order view, has to go through
 * THIS API, not the conversations one.
 *
 *   - Base URL: https://pos.pages.fm/api/v1 (same host SyncPancakeLeads/
 *     SyncTodayOrders already use for pulling orders)
 *   - Auth: api_key query param (reuses the same Setting::get(
 *     'pancake_api_key', ...) already configured for the orders sync — no
 *     separate token-exchange dance like the conversations API needs)
 */
class PancakeOrderTagApi
{
    private const BASE_URL = 'https://pos.pages.fm/api/v1';

    /**
     * The shop's real order-tag catalog — [{id, name, color, is_system_tag,
     * groups}]. Cached for 5 minutes: rarely changes, but this gets hit on
     * every keystroke of the Outcome search box.
     */
    public function listTags(): array
    {
        $apiKey = Setting::get('pancake_api_key', '');
        $shopId = Setting::get('shop_id', '');
        if (empty($apiKey) || empty($shopId)) {
            return [];
        }

        return Cache::remember("pancake_order_tags_{$shopId}", 300, function () use ($apiKey, $shopId) {
            try {
                $response = Http::timeout(10)->get(self::BASE_URL . "/shops/{$shopId}/orders/tags", [
                    'api_key' => $apiKey,
                ]);

                return $response->successful() ? ($response->json('data') ?? []) : [];
            } catch (\Throwable $e) {
                Log::warning('PancakeOrderTagApi: listTags threw', ['message' => $e->getMessage()]);
                return [];
            }
        });
    }

    /**
     * Adds every tag in $tagNames to the real POS order — looked up by name
     * against the shop's real catalog (the API takes {id, name} pairs, not
     * bare names; an unmatched name is skipped with a warning, same
     * "feature unavailable, not fatal" convention as everywhere else).
     *
     * Reads the order's CURRENT full state first and PUTs it back with only
     * the tags array changed (existing tags merged with the new ones, never
     * replaced) — the endpoint's own spec lists no required body fields,
     * but with no live-tested precedent for this specific PUT, echoing back
     * every field GET just returned (not just a bare {tags: [...]} guess)
     * is what keeps a full-replace failure mode from silently wiping out
     * unrelated order data.
     *
     * Returns [tagName => bool].
     */
    public function addTagsToOrder(string $orderId, array $tagNames): array
    {
        $apiKey = Setting::get('pancake_api_key', '');
        $shopId = Setting::get('shop_id', '');
        if (empty($apiKey) || empty($shopId)) {
            return array_fill_keys($tagNames, false);
        }

        $catalog = collect($this->listTags());
        $matched = collect($tagNames)->mapWithKeys(function ($name) use ($catalog) {
            return [$name => $catalog->first(fn ($t) => strcasecmp($t['name'] ?? '', $name) === 0)];
        });

        foreach ($matched->filter(fn ($tag) => $tag === null)->keys() as $name) {
            Log::warning('PancakeOrderTagApi: addTagsToOrder found no matching tag', ['order_id' => $orderId, 'tag_name' => $name]);
        }

        $toAdd = $matched->filter()->values();
        if ($toAdd->isEmpty()) {
            return array_fill_keys($tagNames, false);
        }

        try {
            $getResponse = Http::timeout(15)->get(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", [
                'api_key' => $apiKey,
            ]);

            if (!$getResponse->successful()) {
                Log::warning('PancakeOrderTagApi: fetching order before tagging failed', ['order_id' => $orderId, 'status' => $getResponse->status()]);
                return array_fill_keys($tagNames, false);
            }

            // Real single-order GET responses wrap in {data: {...}} — same
            // shape ReconcileOrderStatuses.php already relies on for this
            // same endpoint.
            $order = $getResponse->json('data') ?? $getResponse->json();

            $existingTags = collect($order['tags'] ?? []);
            $existingIds  = $existingTags->pluck('id')->all();
            $newTags      = $toAdd->reject(fn ($tag) => in_array($tag['id'], $existingIds, true))
                ->map(fn ($tag) => ['id' => $tag['id'], 'name' => $tag['name']]);

            $order['tags'] = $existingTags->merge($newTags)->values()->all();

            $putResponse = Http::timeout(15)
                ->withOptions(['query' => ['api_key' => $apiKey]])
                ->put(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", $order);

            $success = $putResponse->successful() && (($putResponse->json('success') ?? true) !== false);

            if (!$success) {
                Log::warning('PancakeOrderTagApi: addTagsToOrder PUT failed', ['order_id' => $orderId, 'status' => $putResponse->status(), 'body' => $putResponse->body()]);
            }

            return collect($tagNames)->mapWithKeys(fn ($name) => [$name => $success && $matched[$name] !== null])->all();
        } catch (\Throwable $e) {
            Log::warning('PancakeOrderTagApi: addTagsToOrder threw', ['order_id' => $orderId, 'message' => $e->getMessage()]);
            return array_fill_keys($tagNames, false);
        }
    }

    /**
     * Live read of an order's two real Pancake note fields — confirmed
     * against the real OpenAPI spec: `note` ("Internal note" / "Ghi chú nội
     * bộ") and `note_print` ("Note for printing" / "Ghi chú đơn hàng"),
     * both plain strings directly on the Order object, not their own
     * sub-resource (Pancake has no dedicated /notes endpoint — reading or
     * writing either one always goes through the order itself). Explicit
     * request (2026-08-22): shown/edited from the lead detail page so a TSA
     * never has to leave Call Tracker to check or add a POS note. Fetched
     * live on every call (not cached, not synced into the local `orders`
     * table) so it can never show a stale value if someone edited it
     * directly in Pancake POS a moment ago — the same reasoning
     * PancakeProductApi::search() already follows for the exact same
     * "must reflect Pancake's real current state" requirement.
     */
    public function getNotes(string $orderId): array
    {
        $empty = ['note' => null, 'note_print' => null];

        $apiKey = Setting::get('pancake_api_key', '');
        $shopId = Setting::get('shop_id', '');
        if (empty($apiKey) || empty($shopId)) {
            return $empty;
        }

        try {
            $response = Http::timeout(15)->get(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", [
                'api_key' => $apiKey,
            ]);

            if (!$response->successful()) {
                return $empty;
            }

            $order = $response->json('data') ?? $response->json();

            return [
                'note'       => $order['note'] ?? null,
                'note_print' => $order['note_print'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('PancakeOrderTagApi: getNotes threw', ['order_id' => $orderId, 'message' => $e->getMessage()]);
            return $empty;
        }
    }

    /**
     * Writes one or both note fields back to the real order — same
     * GET-the-whole-order-then-PUT-it-back pattern as addTagsToOrder()/
     * addUpsellItem() above (see addTagsToOrder()'s own doc comment for
     * why: echoing back every field GET just returned, not a bare
     * {note: "..."} guess, is what keeps this from silently wiping out
     * unrelated order data on an endpoint with no documented partial-update
     * behavior). $note/$notePrint: null means "leave this field alone",
     * not "clear it" — pass an empty string explicitly to actually blank
     * one out.
     */
    public function updateNotes(string $orderId, ?string $note, ?string $notePrint): bool
    {
        $apiKey = Setting::get('pancake_api_key', '');
        $shopId = Setting::get('shop_id', '');
        if (empty($apiKey) || empty($shopId)) {
            return false;
        }

        try {
            $getResponse = Http::timeout(15)->get(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", [
                'api_key' => $apiKey,
            ]);

            if (!$getResponse->successful()) {
                Log::warning('PancakeOrderTagApi: fetching order before updating notes failed', ['order_id' => $orderId, 'status' => $getResponse->status()]);
                return false;
            }

            $order = $getResponse->json('data') ?? $getResponse->json();

            if ($note !== null) {
                $order['note'] = $note;
            }
            if ($notePrint !== null) {
                $order['note_print'] = $notePrint;
            }

            $putResponse = Http::timeout(15)
                ->withOptions(['query' => ['api_key' => $apiKey]])
                ->put(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", $order);

            $success = $putResponse->successful() && (($putResponse->json('success') ?? true) !== false);

            if (!$success) {
                Log::warning('PancakeOrderTagApi: updateNotes PUT failed', ['order_id' => $orderId, 'status' => $putResponse->status(), 'body' => $putResponse->body()]);
            }

            return $success;
        } catch (\Throwable $e) {
            Log::warning('PancakeOrderTagApi: updateNotes threw', ['order_id' => $orderId, 'message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Removes a tag from the real POS order by name (case-insensitive) — the write
     * side of the Leads tab's own "real tags" chip display (explicit request,
     * 2026-08-22, mirroring Pancake POS's own tag chips + × remove button). Same
     * GET-then-PUT-the-whole-order pattern as addTagsToOrder() above, just filtering
     * $order['tags'] down instead of merging into it. No catalog lookup needed (unlike
     * addTagsToOrder(), which has to resolve a bare name to a real {id, name} pair to
     * ADD one) — the order's own GET response already carries the real {id, name} for
     * every tag currently on it, so this only has to match against those.
     */
    public function removeTagFromOrder(string $orderId, string $tagName): bool
    {
        $apiKey = Setting::get('pancake_api_key', '');
        $shopId = Setting::get('shop_id', '');
        if (empty($apiKey) || empty($shopId)) {
            return false;
        }

        try {
            $getResponse = Http::timeout(15)->get(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", [
                'api_key' => $apiKey,
            ]);

            if (!$getResponse->successful()) {
                Log::warning('PancakeOrderTagApi: fetching order before removing tag failed', ['order_id' => $orderId, 'status' => $getResponse->status()]);
                return false;
            }

            $order = $getResponse->json('data') ?? $getResponse->json();

            $order['tags'] = collect($order['tags'] ?? [])
                ->reject(fn ($tag) => strcasecmp($tag['name'] ?? '', $tagName) === 0)
                ->values()->all();

            $putResponse = Http::timeout(15)
                ->withOptions(['query' => ['api_key' => $apiKey]])
                ->put(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", $order);

            $success = $putResponse->successful() && (($putResponse->json('success') ?? true) !== false);

            if (!$success) {
                Log::warning('PancakeOrderTagApi: removeTagFromOrder PUT failed', ['order_id' => $orderId, 'status' => $putResponse->status(), 'body' => $putResponse->body()]);
            }

            return $success;
        } catch (\Throwable $e) {
            Log::warning('PancakeOrderTagApi: removeTagFromOrder threw', ['order_id' => $orderId, 'message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Writes the order's status directly — one of Order::STATUS_LABELS' keys, `status`
     * being a plain top-level int field on the order object (confirmed by
     * ReconcileOrderStatuses::handle(), which already reads $raw['status'] off this
     * same GET response shape). Same GET-then-PUT-the-whole-order pattern as every
     * other write above (see addTagsToOrder()'s own doc comment for why). Explicit
     * request (2026-08-22): mirrors Pancake POS's own Status dropdown from inside
     * Call Tracker's Leads tab, so a TSA never has to leave this app to move an order
     * along (Confirm it, mark it Packing, Shipped, etc).
     */
    public function updateStatus(string $orderId, int $statusCode): bool
    {
        $apiKey = Setting::get('pancake_api_key', '');
        $shopId = Setting::get('shop_id', '');
        if (empty($apiKey) || empty($shopId)) {
            return false;
        }

        try {
            $getResponse = Http::timeout(15)->get(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", [
                'api_key' => $apiKey,
            ]);

            if (!$getResponse->successful()) {
                Log::warning('PancakeOrderTagApi: fetching order before updating status failed', ['order_id' => $orderId, 'status' => $getResponse->status()]);
                return false;
            }

            $order = $getResponse->json('data') ?? $getResponse->json();
            $order['status'] = $statusCode;

            $putResponse = Http::timeout(15)
                ->withOptions(['query' => ['api_key' => $apiKey]])
                ->put(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", $order);

            $success = $putResponse->successful() && (($putResponse->json('success') ?? true) !== false);

            if (!$success) {
                Log::warning('PancakeOrderTagApi: updateStatus PUT failed', ['order_id' => $orderId, 'status' => $putResponse->status(), 'body' => $putResponse->body()]);
            }

            return $success;
        } catch (\Throwable $e) {
            Log::warning('PancakeOrderTagApi: updateStatus threw', ['order_id' => $orderId, 'message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Creates a new order tag in Pancake's real catalog if $name doesn't
     * already exist there (case-insensitive) — e.g. a brand-new product's
     * own "UPSELL TSD - X" tag, which addTagsToOrder() alone can never add
     * since it only ever matches EXISTING catalog entries by name, never
     * creates one. Confirmed against the real OpenAPI spec: POST
     * /shops/{SHOP_ID}/orders/tags only requires {name}. Busts the 5-minute
     * listTags() cache on success so the very next lookup (addUpsellItem()'s
     * own tag-matching, called right after this) sees the tag that was just
     * created instead of a stale miss.
     */
    public function createTagIfMissing(string $name): ?array
    {
        $apiKey = Setting::get('pancake_api_key', '');
        $shopId = Setting::get('shop_id', '');
        if (empty($apiKey) || empty($shopId) || trim($name) === '') {
            return null;
        }

        $existing = collect($this->listTags())->first(fn ($t) => strcasecmp($t['name'] ?? '', $name) === 0);
        if ($existing) {
            return $existing;
        }

        try {
            $response = Http::timeout(15)
                ->withOptions(['query' => ['api_key' => $apiKey]])
                ->post(self::BASE_URL . "/shops/{$shopId}/orders/tags", ['name' => $name]);

            if (!$response->successful()) {
                Log::warning('PancakeOrderTagApi: createTagIfMissing POST failed', ['name' => $name, 'status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            Cache::forget("pancake_order_tags_{$shopId}");

            $created = $response->json('data') ?? $response->json();
            return is_array($created) ? $created : ['id' => $created['id'] ?? null, 'name' => $name];
        } catch (\Throwable $e) {
            Log::warning('PancakeOrderTagApi: createTagIfMissing threw', ['name' => $name, 'message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Adds a real product/variation as a new line item on the order — the
     * write side of the same POS "quick add products" search
     * PancakeProductApi exposes for reading. Same GET-then-PUT-the-whole-
     * order pattern as addTagsToOrder() above (see its own doc comment for
     * why), done in ONE GET/PUT cycle together with the upsell tag — not two
     * separate ones, so there's only one "someone else edits this order in
     * between" race window instead of two.
     *
     * $item must have variation_id, product_id, name, retail_price, quantity
     * (the first four exactly as PancakeProductApi::search() returns them —
     * $item['name'] is used as both the new item's variation_info.name AND
     * (via $upsellTagName, built by the caller) part of the upsell tag text,
     * so a future tag-hint lookup can still match this item by name if it
     * ever needs to). $upsellTagName must already exist in the catalog —
     * call createTagIfMissing() first. $tsaTagName re-confirms the TSA's own
     * name tag alongside it, same convention LeadController::
     * tagOutcomeInPancake() already uses, so this upsell is attributed to
     * the same TSA as everything else on the lead.
     */
    public function addUpsellItem(string $orderId, array $item, string $upsellTagName, ?string $tsaTagName): bool
    {
        $apiKey = Setting::get('pancake_api_key', '');
        $shopId = Setting::get('shop_id', '');
        if (empty($apiKey) || empty($shopId)) {
            return false;
        }

        try {
            $getResponse = Http::timeout(15)->get(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", [
                'api_key' => $apiKey,
            ]);

            if (!$getResponse->successful()) {
                Log::warning('PancakeOrderTagApi: fetching order before adding upsell item failed', ['order_id' => $orderId, 'status' => $getResponse->status()]);
                return false;
            }

            $order = $getResponse->json('data') ?? $getResponse->json();

            $order['items'] = collect($order['items'] ?? [])->push([
                'variation_id'   => $item['variation_id'],
                'product_id'     => $item['product_id'],
                'quantity'       => $item['quantity'],
                'variation_info' => [
                    'id'           => $item['variation_id'],
                    'name'         => $item['name'],
                    'product_id'   => $item['product_id'],
                    'retail_price' => $item['retail_price'],
                ],
            ])->values()->all();

            $tagCatalog     = collect($this->listTags());
            $wantedTagNames = collect([$upsellTagName, $tsaTagName])->filter()->unique()->values();
            $matchedTags    = $wantedTagNames
                ->map(fn ($name) => $tagCatalog->first(fn ($t) => strcasecmp($t['name'] ?? '', $name) === 0))
                ->filter();

            $existingTags = collect($order['tags'] ?? []);
            $existingIds  = $existingTags->pluck('id')->all();
            $newTags      = $matchedTags->reject(fn ($tag) => in_array($tag['id'], $existingIds, true))
                ->map(fn ($tag) => ['id' => $tag['id'], 'name' => $tag['name']]);

            $order['tags'] = $existingTags->merge($newTags)->values()->all();

            $putResponse = Http::timeout(15)
                ->withOptions(['query' => ['api_key' => $apiKey]])
                ->put(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", $order);

            $success = $putResponse->successful() && (($putResponse->json('success') ?? true) !== false);

            if (!$success) {
                Log::warning('PancakeOrderTagApi: addUpsellItem PUT failed', ['order_id' => $orderId, 'status' => $putResponse->status(), 'body' => $putResponse->body()]);
            }

            return $success;
        } catch (\Throwable $e) {
            Log::warning('PancakeOrderTagApi: addUpsellItem threw', ['order_id' => $orderId, 'message' => $e->getMessage()]);
            return false;
        }
    }
}
