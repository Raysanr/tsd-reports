<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\PancakeProductApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ported from call-tracker (merged into one app 2026-08-12) — unmodified (no Tsa/route references). */
class PancakeProductApiTest extends TestCase
{
    use RefreshDatabase;

    private PancakeProductApi $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = new PancakeProductApi();
        Setting::set('pancake_api_key', 'fake-api-key');
        Setting::set('shop_id', '4');
    }

    /** Real response shape confirmed live (2026-08-08): each result IS a
     *  sellable variation directly, not nested under a parent product. */
    public function test_search_returns_normalized_variations_from_the_real_pos_catalog(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/products/variations*' => Http::response(['success' => true, 'data' => [
                [
                    'id' => 'var-1', 'product_id' => 'prod-1', 'display_id' => '3 Sinuxyl',
                    'retail_price' => 999, 'product' => ['name' => 'Sinuxyl'],
                ],
                [
                    'id' => 'var-2', 'product_id' => 'prod-1', 'display_id' => '4 Sinuxyl',
                    'retail_price' => 1000, 'product' => ['name' => 'Sinuxyl'],
                ],
            ]], 200),
        ]);

        $results = $this->api->search('sinuxyl');

        $this->assertCount(2, $results);
        $this->assertSame('var-1', $results[0]['variation_id']);
        $this->assertSame('prod-1', $results[0]['product_id']);
        $this->assertSame('3 Sinuxyl', $results[0]['name']);
        $this->assertSame(999.0, $results[0]['retail_price']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'search=sinuxyl') && str_contains($r->url(), 'api_key=fake-api-key'));
    }

    public function test_search_returns_empty_for_a_blank_query_without_calling_the_api(): void
    {
        Http::fake();

        $this->assertSame([], $this->api->search(''));
        Http::assertNothingSent();
    }

    public function test_search_returns_empty_without_credentials(): void
    {
        Setting::set('pancake_api_key', '');
        Http::fake();

        $this->assertSame([], $this->api->search('sinuxyl'));
        Http::assertNothingSent();
    }

    public function test_search_returns_empty_when_the_api_errors(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/products/variations*' => Http::response(['message' => 'error'], 500),
        ]);

        $this->assertSame([], $this->api->search('sinuxyl'));
    }

    /** A variation missing its own id is unusable (nothing to add to an
     *  order with) — dropped rather than surfaced with a null variation_id. */
    public function test_search_drops_a_result_with_no_variation_id(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/products/variations*' => Http::response(['success' => true, 'data' => [
                ['product_id' => 'prod-1', 'display_id' => 'Broken', 'retail_price' => 100],
                ['id' => 'var-1', 'product_id' => 'prod-1', 'display_id' => 'Fine', 'retail_price' => 100],
            ]], 200),
        ]);

        $results = $this->api->search('x');

        $this->assertCount(1, $results);
        $this->assertSame('var-1', $results[0]['variation_id']);
    }
}
