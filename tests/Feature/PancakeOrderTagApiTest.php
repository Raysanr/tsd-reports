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

    public function test_add_tags_to_order_merges_new_tags_into_the_orders_existing_tags(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 10, 'name' => 'Confirmed'],
                ['id' => 20, 'name' => 'Gemma'],
            ]], 200),
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['success' => true, 'data' => [
                'id' => 9001, 'tags' => [['id' => 99, 'name' => 'EXISTING']], 'note' => 'do not clobber me',
            ]], 200),
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

    public function test_add_tags_to_order_skips_a_tag_name_with_no_real_match_and_still_adds_the_rest(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 10, 'name' => 'Confirmed'],
            ]], 200),
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['success' => true, 'data' => [
                'id' => 9001, 'tags' => [],
            ]], 200),
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
}
