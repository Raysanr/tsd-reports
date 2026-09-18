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

    /** Every real shipping_address this shop's own synced orders have ever
     *  carried uses country_code "63" (confirmed live) — the Philippines.
     *  Hardcoded, not a Setting, since a single shop never changes country. */
    private const GEO_COUNTRY_CODE = '63';

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

        // trim() both sides — confirmed live, 2026-09-16: Pancake's real
        // catalog can genuinely contain a tag with trailing whitespace baked
        // in (e.g. "Not answering " — confirmed via listTags(), a literal
        // space before the closing quote), but Laravel's default TrimStrings
        // middleware (on by default for every web request, not something
        // this app opted into or out of) silently strips that same
        // whitespace off $tagNames before it ever reaches this method —
        // "Not answering" (submitted) vs "Not answering " (Pancake's own
        // stored name) then failed strcasecmp()'s exact-length comparison,
        // even though it's case-insensitive, and the tag add silently
        // failed with "found no matching tag." There is no way to submit a
        // trailing space through a normal web form to begin with, so the
        // fix has to be tolerating Pancake's own untrimmed catalog entries
        // here, not trying to preserve whitespace client-side.
        $catalog = collect($this->listTags());
        $matched = collect($tagNames)->mapWithKeys(function ($name) use ($catalog) {
            return [$name => $catalog->first(fn ($t) => strcasecmp(trim($t['name'] ?? ''), trim($name)) === 0)];
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
            } else {
                $this->invalidateRawOrderCache($orderId);
            }

            return collect($tagNames)->mapWithKeys(fn ($name) => [$name => $success && $matched[$name] !== null])->all();
        } catch (\Throwable $e) {
            Log::warning('PancakeOrderTagApi: addTagsToOrder threw', ['order_id' => $orderId, 'message' => $e->getMessage()]);
            return array_fill_keys($tagNames, false);
        }
    }

    /**
     * The shop's real staff/seller directory — [{id (real user id, what
     * assigning_seller_id actually stores), name, avatar_url}], the exact
     * pool Pancake POS's own Assignee dropdown picks from. Confirmed live
     * against a real, undocumented-in-the-OpenAPI-spec endpoint: GET
     * /shops/{SHOP_ID}/users returns 261 real entries shaped
     * {id, user: {id, name, avatar_url, ...}, ...} — the OUTER id is a
     * shop-membership row id, not what orders reference; assigning_seller_id
     * on an order matches the INNER user.id, confirmed by comparing a real
     * order's own assigning_seller.id against this same endpoint's entries.
     * Cached 5 minutes, same convention as listTags() above — a shop's
     * staff roster doesn't change often, but this feeds the Assignee
     * picker's own search-as-you-type.
     */
    public function listStaff(): array
    {
        $apiKey = Setting::get('pancake_api_key', '');
        $shopId = Setting::get('shop_id', '');
        if (empty($apiKey) || empty($shopId)) {
            return [];
        }

        return Cache::remember("pancake_shop_staff_{$shopId}", 300, function () use ($apiKey, $shopId) {
            try {
                $response = Http::timeout(15)->get(self::BASE_URL . "/shops/{$shopId}/users", [
                    'api_key' => $apiKey,
                ]);

                if (!$response->successful()) {
                    return [];
                }

                return collect($response->json('data') ?? [])
                    ->map(fn ($row) => [
                        'id'         => $row['user']['id'] ?? null,
                        'name'       => $row['user']['name'] ?? null,
                        'avatar_url' => $row['user']['avatar_url'] ?? null,
                    ])
                    ->filter(fn ($u) => $u['id'] && $u['name'])
                    ->unique('id')
                    ->values()
                    ->all();
            } catch (\Throwable $e) {
                Log::warning('PancakeOrderTagApi: listStaff threw', ['message' => $e->getMessage()]);
                return [];
            }
        });
    }

    /**
     * Sets the real POS order's Assignee (explicit request, 2026-09-18: "i
     * want to make it like in the leads modal has this icon too like can
     * assign the asignee too like in the pos") — same GET-then-PUT-whole-
     * order pattern as addTagsToOrder() above (see its own doc comment for
     * why), just setting the single scalar assigning_seller_id field
     * instead of merging an array. $staffId null clears the assignee
     * (Pancake's own "Choose a staff member…" empty state).
     */
    public function updateAssignee(string $orderId, ?string $staffId): bool
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
                Log::warning('PancakeOrderTagApi: fetching order before updating assignee failed', ['order_id' => $orderId, 'status' => $getResponse->status()]);
                return false;
            }

            $order = $getResponse->json('data') ?? $getResponse->json();
            $order['assigning_seller_id'] = $staffId;

            $putResponse = Http::timeout(15)
                ->withOptions(['query' => ['api_key' => $apiKey]])
                ->put(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", $order);

            $success = $putResponse->successful() && (($putResponse->json('success') ?? true) !== false);

            if (!$success) {
                Log::warning('PancakeOrderTagApi: updateAssignee PUT failed', ['order_id' => $orderId, 'status' => $putResponse->status(), 'body' => $putResponse->body()]);
            } else {
                $this->invalidateRawOrderCache($orderId);
            }

            return $success;
        } catch (\Throwable $e) {
            Log::warning('PancakeOrderTagApi: updateAssignee threw', ['order_id' => $orderId, 'message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Sets ONE line item's own Assignee — the real per-item counterpart to
     * updateAssignee() above. Regression fix, explicit report 2026-09-18:
     * "i want the assignee is the per product like this because it is
     * like in the pos ... but in the call tracker the 2 product including
     * upsell it has no assignee in that" — confirmed live against real
     * production orders that EACH item in items[] carries its own
     * independent assigning_seller/assigning_seller_id, distinct from the
     * order-level field; Pancake's own POS row-level icon falls back to
     * the order-level assignee only when the item's own is null (confirmed
     * live: a real 2-item order with both items' assigning_seller_id null
     * still showed the order-level assignee's name on every row in POS).
     * Same GET-then-PUT-whole-order, match-by-variation_id pattern as
     * updateItem() above. $staffId null clears just this item's own
     * override (it then falls back to the order-level assignee again, same
     * as Pancake's own empty-state behavior).
     */
    public function updateItemAssignee(string $orderId, string $variationId, ?string $staffId): bool
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
                Log::warning('PancakeOrderTagApi: fetching order before updating item assignee failed', ['order_id' => $orderId, 'status' => $getResponse->status()]);
                return false;
            }

            $order = $getResponse->json('data') ?? $getResponse->json();

            $found = false;
            $order['items'] = collect($order['items'] ?? [])->map(function ($item) use ($variationId, $staffId, &$found) {
                if ((string) ($item['variation_id'] ?? '') !== $variationId) {
                    return $item;
                }
                $found = true;
                $item['assigning_seller_id'] = $staffId;
                return $item;
            })->values()->all();

            if (!$found) {
                Log::warning('PancakeOrderTagApi: updateItemAssignee found no matching variation_id', ['order_id' => $orderId, 'variation_id' => $variationId]);
                return false;
            }

            $putResponse = Http::timeout(15)
                ->withOptions(['query' => ['api_key' => $apiKey]])
                ->put(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", $order);

            $success = $putResponse->successful() && (($putResponse->json('success') ?? true) !== false);

            if (!$success) {
                Log::warning('PancakeOrderTagApi: updateItemAssignee PUT failed', ['order_id' => $orderId, 'status' => $putResponse->status(), 'body' => $putResponse->body()]);
            } else {
                $this->invalidateRawOrderCache($orderId);
            }

            return $success;
        } catch (\Throwable $e) {
            Log::warning('PancakeOrderTagApi: updateItemAssignee threw', ['order_id' => $orderId, 'message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Raw order GET, shared by getOrderDetail() and getNotes() below — both
     * hit this exact same Pancake endpoint for the exact same order object,
     * just reading different fields off it. Cached for
     * self::LIVE_ORDER_CACHE_SECONDS (explicit request, 2026-09-17: "why is
     * it so slow" — the lead detail modal polls BOTH getOrderDetail() (the
     * history panel, every 8s) and getNotes() (the notes panel, also every
     * 8s) independently, so before this every open modal fired two live
     * Pancake GETs of the identical order roughly in lockstep, forever, for
     * as long as it stayed open). A cache this short is deliberately much
     * shorter than the 8s poll interval itself — it only ever collapses
     * the notes+history polls that land within the same couple seconds of
     * each other into one real Pancake call, it never makes a poll go a
     * full cycle without checking Pancake again, so a genuine edit in
     * Pancake POS is still visible within one normal poll tick same as
     * before. Keyed by order id + shop id (not api key) since a cached
     * response is only ever valid for the shop it was fetched from.
     * Returns the decoded body's 'data' (or the bare body) on success, null
     * on any failure — same "unauthorized/missing order still returns
     * HTTP 200" check getOrderDetail() always applied, now shared instead
     * of only ever existing on that one method's own copy.
     */
    private const LIVE_ORDER_CACHE_SECONDS = 5;

    private function fetchRawOrder(string $orderId): ?array
    {
        $apiKey = Setting::get('pancake_api_key', '');
        $shopId = Setting::get('shop_id', '');
        if (empty($apiKey) || empty($shopId)) {
            return null;
        }

        return Cache::remember("pancake_raw_order_{$shopId}_{$orderId}", self::LIVE_ORDER_CACHE_SECONDS, function () use ($apiKey, $shopId, $orderId) {
            try {
                $response = Http::timeout(15)->get(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", [
                    'api_key' => $apiKey,
                ]);

                // Real behavior confirmed live, not assumed: Pancake returns
                // HTTP 200 even for an order it can't find/isn't authorized
                // for — {"message":"You do not have permission to view this
                // order","success":false} — so successful() alone isn't
                // enough. Checking the body's own success flag is the same
                // convention every write method below already applies (see
                // addUpsellItem()'s own $success check) — this was just never
                // applied on the READ side until a stale/wrong
                // pancake_order_id on a real lead surfaced it as a silently
                // empty Detail/Status history instead of the intended
                // "Pancake unreachable" local-activity fallback.
                if (!$response->successful() || $response->json('success') === false) {
                    return null;
                }

                return $response->json('data') ?? $response->json();
            } catch (\Throwable $e) {
                Log::warning('PancakeOrderTagApi: fetchRawOrder threw', ['order_id' => $orderId, 'message' => $e->getMessage()]);
                return null;
            }
        });
    }

    /**
     * Clears fetchRawOrder()'s cached entry for one order — called after
     * every successful write below (addTagsToOrder, removeTagFromOrder,
     * updateNotes, updateStatus, addUpsellItem, removeItem, updateItem,
     * updateShippingAddress). Regression fix, 2026-09-17: "when tsa add tag
     * and the reflect of the tag ... is slow" — a TSA adding a tag
     * immediately triggers a refresh of the lead detail card
     * (refreshLeadDetail() in calls.js -> LeadController::show() ->
     * getOrderDetail()), and without this, that refresh could still read
     * fetchRawOrder()'s cache entry from BEFORE the write (up to
     * LIVE_ORDER_CACHE_SECONDS old), so the just-added tag silently didn't
     * show up until the cache naturally expired — reading as "the save is
     * slow" when the save itself was actually already done; only the
     * REFLECTED view was serving a stale cached read. Every write method
     * does its own separate, never-cached GET+PUT (see addTagsToOrder()'s
     * own doc comment for why: a write can never safely read a stale
     * cache either) — this only clears the READ-side cache other methods
     * share, so the very next getOrderDetail()/getNotes() call after a
     * write is guaranteed to hit Pancake fresh instead of possibly
     * re-serving pre-write data.
     */
    private function invalidateRawOrderCache(string $orderId): void
    {
        $shopId = Setting::get('shop_id', '');
        if (empty($shopId)) {
            return;
        }

        Cache::forget("pancake_raw_order_{$shopId}_{$orderId}");
    }

    /**
     * The order's full current line-item list and tag catalog, straight
     * from Pancake — explicit request (2026-08-25): the lead detail
     * modal's Product card was showing only ONE summarized line (this
     * app's own `orders` table stores just a computed base_product/
     * bundle_description/amount per order — for an upsell order that's
     * deliberately the isolated addon's own info, not the base item, per
     * extractUpsellProduct()/extractUpsellAmount() — so a genuine
     * multi-item order rendered as if the upsell WAS the only product,
     * with no way to show the base item's own line or its own price
     * alongside it). Pancake's raw items[]/tags[] were never persisted
     * locally at sync time (only the computed summary was), so unlike
     * getNotes() below this can't fall back to anything local — a failed
     * fetch here just means the modal falls back to the local summary
     * card instead (see calls/leads/_detail.blade.php). Reads through
     * fetchRawOrder()'s own short cache (see that method's own doc
     * comment) rather than fetching directly.
     */
    public function getOrderDetail(string $orderId): ?array
    {
        $order = $this->fetchRawOrder($orderId);
        if ($order === null) {
            return null;
        }

        return [
            'items'    => $order['items'] ?? [],
            'tags'     => $order['tags'] ?? [],
            // Delivery (explicit follow-up request, 2026-08-25: "add
            // delivery to this like in the POS") — confirmed against a
            // real live order's own raw response: shipping_address is
            // the recipient/full address, partner is the assigned
            // courier (null until Pancake/the shop actually books one —
            // e.g. still New/unprinted), estimate_delivery_date is
            // nullable the same way. Read-only display only — Pancake's
            // own Delivery panel is a real editable form backed by a
            // full province/city/barangay address-cascading picker,
            // building an equivalent editor here is a materially
            // bigger undertaking than what was asked for.
            'shipping_address'      => $order['shipping_address'] ?? null,
            'shipping_fee'          => $order['shipping_fee'] ?? null,
            'estimate_delivery_date' => $order['estimate_delivery_date'] ?? null,
            'courier_name'          => $order['partner']['partner_name'] ?? null,
            'tracking_link'         => $order['tracking_link'] ?? null,
            // Real order-history (explicit follow-up request: "add
            // history like in the POS", then "can you fetch the
            // history from pos?" once the local LeadActivity log
            // proved too sparse) — confirmed live against a real
            // order's raw response: 'histories' is a field-level diff
            // log (old/new pairs per changed field, editor_id,
            // timestamp), 'status_history' is specifically status
            // transitions with a resolved editor name/avatar already
            // attached. Both already ride along in this same GET, no
            // separate endpoint exists for them. Editor names/avatars
            // for 'histories' entries are resolved from the order's
            // own creator/last_editor/assigning_seller (see
            // PancakeOrderHistoryFormatter, which does the actual
            // diff-to-sentence translation) since 'histories' itself
            // only carries a bare editor_id.
            'histories'       => $order['histories'] ?? [],
            'status_history'  => $order['status_history'] ?? [],
            'creator'         => $order['creator'] ?? null,
            'last_editor'     => $order['last_editor'] ?? null,
            'assigning_seller' => $order['assigning_seller'] ?? null,
        ];
    }

    /**
     * The real province catalog feeding the Delivery card's editable
     * cascading picker (explicit follow-up request, 2026-08-25: "make it
     * editable like in the POS") — confirmed against the real OpenAPI spec:
     * GET /geo/provinces?country_code=63, unauthenticated (no api_key
     * required, confirmed live) and shop-agnostic, so this doesn't need
     * $shopId at all. Cached an hour: provinces essentially never change.
     */
    public function listProvinces(): array
    {
        if (empty(Setting::get('pancake_api_key', ''))) {
            return [];
        }

        return Cache::remember('pancake_geo_provinces', 3600, function () {
            try {
                $response = Http::timeout(10)->get(self::BASE_URL . '/geo/provinces', [
                    'country_code' => self::GEO_COUNTRY_CODE,
                ]);

                return $response->successful() ? ($response->json('data') ?? $response->json() ?? []) : [];
            } catch (\Throwable $e) {
                Log::warning('PancakeOrderTagApi: listProvinces threw', ['message' => $e->getMessage()]);
                return [];
            }
        });
    }

    /** Districts within one province — GET /geo/districts?province_id=. */
    public function listDistricts(string $provinceId): array
    {
        if (empty(Setting::get('pancake_api_key', ''))) {
            return [];
        }

        return Cache::remember("pancake_geo_districts_{$provinceId}", 3600, function () use ($provinceId) {
            try {
                $response = Http::timeout(10)->get(self::BASE_URL . '/geo/districts', [
                    'province_id' => $provinceId,
                ]);

                return $response->successful() ? ($response->json('data') ?? $response->json() ?? []) : [];
            } catch (\Throwable $e) {
                Log::warning('PancakeOrderTagApi: listDistricts threw', ['province_id' => $provinceId, 'message' => $e->getMessage()]);
                return [];
            }
        });
    }

    /** Communes (barangays) within one district — GET /geo/communes?province_id=&district_id=.
     *  Each commune carries its own `postcode` array, which the Delivery
     *  form uses to auto-fill the Postcode field once one is picked. */
    public function listCommunes(string $provinceId, string $districtId): array
    {
        if (empty(Setting::get('pancake_api_key', ''))) {
            return [];
        }

        return Cache::remember("pancake_geo_communes_{$districtId}", 3600, function () use ($provinceId, $districtId) {
            try {
                $response = Http::timeout(10)->get(self::BASE_URL . '/geo/communes', [
                    'province_id' => $provinceId,
                    'district_id' => $districtId,
                ]);

                return $response->successful() ? ($response->json('data') ?? $response->json() ?? []) : [];
            } catch (\Throwable $e) {
                Log::warning('PancakeOrderTagApi: listCommunes threw', ['district_id' => $districtId, 'message' => $e->getMessage()]);
                return [];
            }
        });
    }

    /**
     * Writes the order's shipping address back to the real order — the
     * write side of the lead detail modal's own editable Delivery card.
     * Same GET-then-PUT-whole-order pattern as every other write above (see
     * addTagsToOrder()'s own doc comment for why echoing back every GET'd
     * field matters here too).
     *
     * $shippingAddress is merged onto the order's EXISTING shipping_address
     * rather than replacing it outright, so a field this app's own form
     * never collects (e.g. `render_type`, `marketplace_address`) survives
     * untouched. The caller (LeadController::updateDelivery()) is
     * responsible for resolving real {id, name} pairs for province/
     * district/commune against listProvinces()/listDistricts()/
     * listCommunes() first — this method just writes whatever it's handed.
     */
    public function updateShippingAddress(string $orderId, array $shippingAddress): bool
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
                Log::warning('PancakeOrderTagApi: fetching order before updating shipping address failed', ['order_id' => $orderId, 'status' => $getResponse->status()]);
                return false;
            }

            $order = $getResponse->json('data') ?? $getResponse->json();

            $order['shipping_address'] = array_merge($order['shipping_address'] ?? [], $shippingAddress);

            $putResponse = Http::timeout(15)
                ->withOptions(['query' => ['api_key' => $apiKey]])
                ->put(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", $order);

            $success = $putResponse->successful() && (($putResponse->json('success') ?? true) !== false);

            if (!$success) {
                Log::warning('PancakeOrderTagApi: updateShippingAddress PUT failed', ['order_id' => $orderId, 'status' => $putResponse->status(), 'body' => $putResponse->body()]);
            } else {
                $this->invalidateRawOrderCache($orderId);
            }

            return $success;
        } catch (\Throwable $e) {
            Log::warning('PancakeOrderTagApi: updateShippingAddress threw', ['order_id' => $orderId, 'message' => $e->getMessage()]);
            return false;
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
     * never has to leave Call Tracker to check or add a POS note. Reads
     * through fetchRawOrder()'s own short cache (see that method's own doc
     * comment) rather than fetching directly — this and getOrderDetail()
     * above both poll the exact same order every 8s from the lead detail
     * modal's two separate panels, so sharing one fetch/cache means one
     * real Pancake call covers both instead of two.
     */
    public function getNotes(string $orderId): array
    {
        $empty = ['note' => null, 'note_print' => null];

        $order = $this->fetchRawOrder($orderId);
        if ($order === null) {
            return $empty;
        }

        return [
            'note'       => $order['note'] ?? null,
            'note_print' => $order['note_print'] ?? null,
        ];
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
            } else {
                $this->invalidateRawOrderCache($orderId);
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

            // trim() both sides — same reasoning as addTagsToOrder()'s own
            // comment above: a real Pancake tag name can carry trailing
            // whitespace baked into the catalog, but Laravel's default
            // TrimStrings middleware silently strips it off $tagName before
            // this method ever sees it.
            $order['tags'] = collect($order['tags'] ?? [])
                ->reject(fn ($tag) => strcasecmp(trim($tag['name'] ?? ''), trim($tagName)) === 0)
                ->values()->all();

            $putResponse = Http::timeout(15)
                ->withOptions(['query' => ['api_key' => $apiKey]])
                ->put(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", $order);

            $success = $putResponse->successful() && (($putResponse->json('success') ?? true) !== false);

            if (!$success) {
                Log::warning('PancakeOrderTagApi: removeTagFromOrder PUT failed', ['order_id' => $orderId, 'status' => $putResponse->status(), 'body' => $putResponse->body()]);
            } else {
                $this->invalidateRawOrderCache($orderId);
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
            } else {
                $this->invalidateRawOrderCache($orderId);
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

        // trim() both sides — same reasoning as addTagsToOrder()'s own
        // comment: a real catalog entry can carry trailing whitespace, and
        // Laravel's default TrimStrings middleware already stripped $name's
        // own whitespace before this method saw it, upstream of any caller
        // of this method that came from a web request.
        $existing = collect($this->listTags())->first(fn ($t) => strcasecmp(trim($t['name'] ?? ''), trim($name)) === 0);
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

            // trim() both sides — same reasoning as addTagsToOrder()'s own
            // comment: a real catalog entry can carry trailing whitespace.
            $tagCatalog     = collect($this->listTags());
            $wantedTagNames = collect([$upsellTagName, $tsaTagName])->filter()->unique()->values();
            $matchedTags    = $wantedTagNames
                ->map(fn ($name) => $tagCatalog->first(fn ($t) => strcasecmp(trim($t['name'] ?? ''), trim($name)) === 0))
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
            } else {
                $this->invalidateRawOrderCache($orderId);
            }

            return $success;
        } catch (\Throwable $e) {
            Log::warning('PancakeOrderTagApi: addUpsellItem threw', ['order_id' => $orderId, 'message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Removes one line item from the order — same GET-then-PUT-the-whole-
     * order pattern as addUpsellItem() above. $variationId is matched
     * against each item's variation_id, which (unlike a dedicated line-item
     * id — Pancake's item shape here carries none) is the only field that
     * reliably identifies one line, same key addUpsellItem() writes when
     * adding. If two items on the same order somehow share a variation_id
     * (e.g. the same product added twice as separate upsells), both are
     * removed together — this API has no per-instance id to distinguish
     * them, and correcting a miskeyed add is already what deleting resolves.
     */
    public function removeItem(string $orderId, string $variationId): bool
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
                Log::warning('PancakeOrderTagApi: fetching order before removing item failed', ['order_id' => $orderId, 'status' => $getResponse->status()]);
                return false;
            }

            $order = $getResponse->json('data') ?? $getResponse->json();

            $order['items'] = collect($order['items'] ?? [])
                ->reject(fn ($item) => (string) ($item['variation_id'] ?? '') === $variationId)
                ->values()->all();

            $putResponse = Http::timeout(15)
                ->withOptions(['query' => ['api_key' => $apiKey]])
                ->put(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", $order);

            $success = $putResponse->successful() && (($putResponse->json('success') ?? true) !== false);

            if (!$success) {
                Log::warning('PancakeOrderTagApi: removeItem PUT failed', ['order_id' => $orderId, 'status' => $putResponse->status(), 'body' => $putResponse->body()]);
            } else {
                $this->invalidateRawOrderCache($orderId);
            }

            return $success;
        } catch (\Throwable $e) {
            Log::warning('PancakeOrderTagApi: removeItem threw', ['order_id' => $orderId, 'message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Updates one line item's retail_price (and optionally quantity) —
     * same GET-then-PUT-the-whole-order pattern, same variation_id matching
     * as removeItem() above. Only the matched item's variation_info.
     * retail_price/quantity are touched; every other item and field on the
     * order round-trips unchanged.
     */
    public function updateItem(string $orderId, string $variationId, float $retailPrice, ?int $quantity = null): bool
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
                Log::warning('PancakeOrderTagApi: fetching order before updating item failed', ['order_id' => $orderId, 'status' => $getResponse->status()]);
                return false;
            }

            $order = $getResponse->json('data') ?? $getResponse->json();

            $found = false;
            $order['items'] = collect($order['items'] ?? [])->map(function ($item) use ($variationId, $retailPrice, $quantity, &$found) {
                if ((string) ($item['variation_id'] ?? '') !== $variationId) {
                    return $item;
                }
                $found = true;
                $item['variation_info']['retail_price'] = $retailPrice;
                if ($quantity !== null) {
                    $item['quantity'] = $quantity;
                }
                return $item;
            })->values()->all();

            if (!$found) {
                Log::warning('PancakeOrderTagApi: updateItem found no matching variation_id', ['order_id' => $orderId, 'variation_id' => $variationId]);
                return false;
            }

            $putResponse = Http::timeout(15)
                ->withOptions(['query' => ['api_key' => $apiKey]])
                ->put(self::BASE_URL . "/shops/{$shopId}/orders/{$orderId}", $order);

            $success = $putResponse->successful() && (($putResponse->json('success') ?? true) !== false);

            if (!$success) {
                Log::warning('PancakeOrderTagApi: updateItem PUT failed', ['order_id' => $orderId, 'status' => $putResponse->status(), 'body' => $putResponse->body()]);
            } else {
                $this->invalidateRawOrderCache($orderId);
            }

            return $success;
        } catch (\Throwable $e) {
            Log::warning('PancakeOrderTagApi: updateItem threw', ['order_id' => $orderId, 'message' => $e->getMessage()]);
            return false;
        }
    }
}
