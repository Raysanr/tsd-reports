<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\PancakeOrderTagApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ported from call-tracker (merged into one app 2026-08-12) — unmodified (no Tsa/route references). */
class PancakeOrderTagApiTest extends TestCase
{
    use RefreshDatabase;

    private PancakeOrderTagApi $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = new PancakeOrderTagApi();
        Setting::set('pancake_api_key', 'fake-api-key');
        Setting::set('shop_id', '4');
    }

    public function test_list_tags_returns_the_real_pos_order_tag_catalog(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 1, 'name' => 'SINUXYL', 'color' => '#000'],
                ['id' => 2, 'name' => 'PTERYGIUM', 'color' => '#111'],
            ]], 200),
        ]);

        $tags = $this->api->listTags();

        $this->assertCount(2, $tags);
        $this->assertSame('SINUXYL', $tags[0]['name']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api_key=fake-api-key'));
    }

    public function test_list_tags_returns_empty_without_api_key_or_shop_id(): void
    {
        Setting::set('pancake_api_key', '');
        Http::fake();

        $this->assertSame([], $this->api->listTags());
        Http::assertNothingSent();
    }

    /**
     * addTagsToOrder() now verifies a tag actually landed via a follow-up
     * GET after the PUT (explicit report, 2026-09-18: "look at this at
     * angel, she tag it as upsell tsd" — a real PUT reported success while
     * the tag never actually appeared, confirmed via that order's own
     * Pancake history showing no corresponding diff for that write at
     * all). Tests that assert a genuinely successful add need a stateful
     * fake tracking tags across GET -> PUT -> verify-GET, not a static
     * response that can never reflect "the tag exists after the write."
     */
    public function test_add_tags_to_order_merges_new_tags_into_the_orders_existing_tags(): void
    {
        $tagsOnOrder = [['id' => 99, 'name' => 'EXISTING']];
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 10, 'name' => 'Confirmed'],
                ['id' => 20, 'name' => 'Gemma'],
            ]], 200),
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => function ($request) use (&$tagsOnOrder) {
                if ($request->method() === 'PUT') {
                    $tagsOnOrder = $request['tags'] ?? [];
                }
                return Http::response(['success' => true, 'data' => [
                    'id' => 9001, 'tags' => $tagsOnOrder, 'note' => 'do not clobber me',
                ]], 200);
            },
        ]);

        $results = $this->api->addTagsToOrder('9001', ['Confirmed', 'Gemma']);

        $this->assertSame(['Confirmed' => true, 'Gemma' => true], $results);

        Http::assertSent(function ($r) {
            if ($r->method() !== 'PUT') return false;
            $tagIds = collect($r['tags'])->pluck('id')->all();
            // Existing tag preserved, both new ones merged in, unrelated
            // fields echoed back unchanged (not dropped by a partial body).
            return in_array(99, $tagIds) && in_array(10, $tagIds) && in_array(20, $tagIds)
                && $r['note'] === 'do not clobber me';
        });
    }

    public function test_add_tags_to_order_does_not_duplicate_a_tag_the_order_already_has(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 10, 'name' => 'Confirmed'],
            ]], 200),
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['success' => true, 'data' => [
                'id' => 9001, 'tags' => [['id' => 10, 'name' => 'Confirmed']],
            ]], 200),
        ]);

        $this->api->addTagsToOrder('9001', ['Confirmed']);

        Http::assertSent(function ($r) {
            if ($r->method() !== 'PUT') return false;
            return count($r['tags']) === 1;
        });
    }

    /**
     * Confirmed live, 2026-09-16: Pancake's real catalog can genuinely
     * contain a tag with trailing whitespace baked in ("Not answering ",
     * confirmed via listTags() on production — a literal trailing space).
     * Laravel's default TrimStrings middleware (on for every web request)
     * silently strips that same whitespace off the submitted tag name
     * before it ever reaches this method, so "Not answering" (submitted)
     * vs "Not answering " (Pancake's own stored name) failed an exact
     * strcasecmp() even though it's case-insensitive — the add silently
     * failed with "found no matching tag," surfacing to the TSA as "Could
     * not add this tag in Pancake."
     */
    public function test_add_tags_to_order_matches_a_catalog_tag_with_trailing_whitespace(): void
    {
        $tagsOnOrder = [];
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 30, 'name' => 'Not answering '],
            ]], 200),
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => function ($request) use (&$tagsOnOrder) {
                if ($request->method() === 'PUT') {
                    $tagsOnOrder = $request['tags'] ?? [];
                }
                return Http::response(['success' => true, 'data' => ['id' => 9001, 'tags' => $tagsOnOrder]], 200);
            },
        ]);

        // TrimStrings would have already stripped this before a real
        // controller ever called addTagsToOrder() — simulated directly here
        // since this is a unit-level call into the API class itself.
        $results = $this->api->addTagsToOrder('9001', ['Not answering']);

        $this->assertSame(['Not answering' => true], $results);

        Http::assertSent(function ($r) {
            if ($r->method() !== 'PUT') return false;
            return collect($r['tags'])->pluck('id')->contains(30);
        });
    }

    public function test_add_tags_to_order_skips_a_tag_name_with_no_real_match_and_still_adds_the_rest(): void
    {
        $tagsOnOrder = [];
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 10, 'name' => 'Confirmed'],
            ]], 200),
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => function ($request) use (&$tagsOnOrder) {
                if ($request->method() === 'PUT') {
                    $tagsOnOrder = $request['tags'] ?? [];
                }
                return Http::response(['success' => true, 'data' => ['id' => 9001, 'tags' => $tagsOnOrder]], 200);
            },
        ]);

        $results = $this->api->addTagsToOrder('9001', ['Confirmed', 'Made Up Tag']);

        $this->assertSame(['Confirmed' => true, 'Made Up Tag' => false], $results);
    }

    public function test_add_tags_to_order_fails_gracefully_when_fetching_the_order_fails(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 10, 'name' => 'Confirmed'],
            ]], 200),
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['message' => 'not found'], 404),
        ]);

        $results = $this->api->addTagsToOrder('9001', ['Confirmed']);

        $this->assertSame(['Confirmed' => false], $results);
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
    }

    public function test_create_tag_if_missing_creates_a_new_tag_when_none_exists(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::sequence()
                // First call is the pre-check GET (listTags) — empty catalog.
                ->push(['success' => true, 'data' => []], 200)
                // The POST create.
                ->push(['success' => true, 'data' => ['id' => 55, 'name' => 'UPSELL TSD - Haplunas Balm']], 200),
        ]);

        $tag = $this->api->createTagIfMissing('UPSELL TSD - Haplunas Balm');

        $this->assertSame(55, $tag['id']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && ($r['name'] ?? null) === 'UPSELL TSD - Haplunas Balm');
    }

    public function test_create_tag_if_missing_returns_the_existing_tag_without_creating_a_duplicate(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 10, 'name' => 'UPSELL TSD - Sinuxyl'],
            ]], 200),
        ]);

        $tag = $this->api->createTagIfMissing('upsell tsd - sinuxyl'); // case-insensitive match

        $this->assertSame(10, $tag['id']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_add_upsell_item_appends_a_new_line_item_and_the_upsell_tag_in_one_put(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 30, 'name' => 'UPSELL TSD - Haplunas Balm'],
                ['id' => 40, 'name' => 'Gemma'],
            ]], 200),
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['success' => true, 'data' => [
                'id' => 9001,
                'items' => [['variation_id' => 'base-item', 'quantity' => 1, 'variation_info' => ['name' => 'Ginseng Serum', 'retail_price' => 800]]],
                'tags' => [],
                'note' => 'do not clobber me',
            ]], 200),
        ]);

        $success = $this->api->addUpsellItem('9001', [
            'variation_id' => 'new-item', 'product_id' => 'prod-1', 'name' => 'Haplunas Balm', 'retail_price' => 499.0, 'quantity' => 2,
        ], 'UPSELL TSD - Haplunas Balm', 'Gemma');

        $this->assertTrue($success);

        Http::assertSent(function ($r) {
            if ($r->method() !== 'PUT') return false;
            $itemIds = collect($r['items'])->pluck('variation_id')->all();
            $tagIds  = collect($r['tags'])->pluck('id')->all();
            return count($r['items']) === 2 && in_array('base-item', $itemIds) && in_array('new-item', $itemIds)
                && in_array(30, $tagIds) && in_array(40, $tagIds)
                && $r['note'] === 'do not clobber me';
        });
    }

    public function test_add_upsell_item_fails_gracefully_when_fetching_the_order_fails(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => []], 200),
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['message' => 'not found'], 404),
        ]);

        $success = $this->api->addUpsellItem('9001', [
            'variation_id' => 'new-item', 'product_id' => 'prod-1', 'name' => 'Haplunas Balm', 'retail_price' => 499.0, 'quantity' => 1,
        ], 'UPSELL TSD - Haplunas Balm', null);

        $this->assertFalse($success);
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
    }

    public function test_update_status_writes_the_new_status_code_back_without_disturbing_other_fields(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['success' => true, 'data' => [
                'id' => 9001, 'status' => 0, 'note' => 'do not clobber me', 'tags' => [['id' => 1, 'name' => 'GEMMA']],
            ]], 200),
        ]);

        $success = $this->api->updateStatus('9001', 1);

        $this->assertTrue($success);
        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && $r['status'] === 1
            && $r['note'] === 'do not clobber me'
            && $r['tags'][0]['name'] === 'GEMMA');
    }

    public function test_update_status_fails_gracefully_when_fetching_the_order_fails(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['message' => 'not found'], 404),
        ]);

        $success = $this->api->updateStatus('9001', 1);

        $this->assertFalse($success);
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
    }

    public function test_remove_tag_from_order_filters_the_matching_tag_out_case_insensitively(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['success' => true, 'data' => [
                'id' => 9001, 'tags' => [
                    ['id' => 1, 'name' => 'Gemma'],
                    ['id' => 2, 'name' => 'Confirmed'],
                ],
            ]], 200),
        ]);

        $success = $this->api->removeTagFromOrder('9001', 'confirmed');

        $this->assertTrue($success);
        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && count($r['tags']) === 1
            && $r['tags'][0]['name'] === 'Gemma');
    }

    /** Same trailing-whitespace fix as addTagsToOrder() above — an order's
     *  own existing tag can carry whitespace Pancake stored, while the
     *  submitted name to remove was already trimmed by Laravel's default
     *  middleware before this method ever saw it. */
    public function test_remove_tag_from_order_matches_an_existing_tag_with_trailing_whitespace(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['success' => true, 'data' => [
                'id' => 9001, 'tags' => [
                    ['id' => 1, 'name' => 'Gemma'],
                    ['id' => 2, 'name' => 'Not answering '],
                ],
            ]], 200),
        ]);

        $success = $this->api->removeTagFromOrder('9001', 'Not answering');

        $this->assertTrue($success);
        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && count($r['tags']) === 1
            && $r['tags'][0]['name'] === 'Gemma');
    }

    public function test_remove_tag_from_order_fails_gracefully_when_fetching_the_order_fails(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['message' => 'not found'], 404),
        ]);

        $success = $this->api->removeTagFromOrder('9001', 'Confirmed');

        $this->assertFalse($success);
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
    }

    /**
     * Performance fix (explicit request, 2026-09-17: "why is it so slow") —
     * getOrderDetail() and getNotes() used to each fire their own live GET
     * of the same order; the lead detail modal polls both every 8s from its
     * two separate panels, so every open modal fired two live Pancake calls
     * roughly in lockstep. Both now read through fetchRawOrder()'s shared
     * short cache — confirmed here that calling getOrderDetail() then
     * getNotes() (or the reverse) for the SAME order only hits Pancake
     * once.
     */
    public function test_get_order_detail_and_get_notes_share_one_cached_fetch_for_the_same_order(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['success' => true, 'data' => [
                'id' => 9001, 'items' => [], 'tags' => [], 'note' => 'Internal note', 'note_print' => 'Print note',
            ]], 200),
        ]);

        $detail = $this->api->getOrderDetail('9001');
        $notes  = $this->api->getNotes('9001');

        $this->assertNotNull($detail);
        $this->assertSame('Internal note', $notes['note']);
        $this->assertSame('Print note', $notes['note_print']);
        Http::assertSentCount(1);
    }

    /** Companion to the shared-cache test above — a DIFFERENT order id must
     *  never reuse another order's cached response just because both calls
     *  happened close together. */
    public function test_get_order_detail_for_a_different_order_is_not_served_from_another_orders_cache(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['success' => true, 'data' => [
                'id' => 9001, 'items' => [], 'tags' => [], 'note' => 'Order 9001 note',
            ]], 200),
            'pos.pages.fm/api/v1/shops/4/orders/9002*' => Http::response(['success' => true, 'data' => [
                'id' => 9002, 'items' => [], 'tags' => [], 'note' => 'Order 9002 note',
            ]], 200),
        ]);

        $notesFor9001 = $this->api->getNotes('9001');
        $notesFor9002 = $this->api->getNotes('9002');

        $this->assertSame('Order 9001 note', $notesFor9001['note']);
        $this->assertSame('Order 9002 note', $notesFor9002['note']);
        Http::assertSentCount(2);
    }

    /**
     * Regression test for the cache-invalidation fix (explicit report,
     * 2026-09-17: "when tsa add tag and the reflect of the tag ... is
     * slow ... like it saved like that") — root-caused: a getOrderDetail()
     * call BEFORE a tag was added populated fetchRawOrder()'s cache; the
     * write itself (addTagsToOrder()) does its own separate, never-cached
     * GET+PUT, so the write succeeded immediately, but the very next
     * getOrderDetail() call (the lead detail card's own post-save refresh)
     * could still return that stale pre-write cache entry for up to
     * LIVE_ORDER_CACHE_SECONDS — reading as "the tag isn't showing up yet"
     * even though it had already saved. addTagsToOrder() now busts the
     * cache on success, so the refresh right after a save always sees the
     * new tag.
     */
    public function test_a_successful_tag_add_invalidates_the_cached_order_read_for_that_order(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 10, 'name' => 'Confirmed'],
            ]], 200),
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::sequence()
                // First read (before the tag exists) — populates the cache.
                ->push(['success' => true, 'data' => ['id' => 9001, 'tags' => []]], 200)
                // addTagsToOrder()'s own internal GET (never cached).
                ->push(['success' => true, 'data' => ['id' => 9001, 'tags' => []]], 200)
                // addTagsToOrder()'s own internal PUT.
                ->push(['success' => true], 200)
                // The refresh AFTER the save — must be a fresh GET, not the
                // stale cache from the very first read above.
                ->push(['success' => true, 'data' => ['id' => 9001, 'tags' => [['id' => 10, 'name' => 'Confirmed']]]], 200),
        ]);

        $beforeAdd = $this->api->getOrderDetail('9001');
        $this->assertSame([], $beforeAdd['tags']);

        $result = $this->api->addTagsToOrder('9001', ['Confirmed']);
        $this->assertSame(['Confirmed' => true], $result);

        $afterAdd = $this->api->getOrderDetail('9001');
        $this->assertCount(1, $afterAdd['tags']);
        $this->assertSame('Confirmed', $afterAdd['tags'][0]['name']);
    }
}
