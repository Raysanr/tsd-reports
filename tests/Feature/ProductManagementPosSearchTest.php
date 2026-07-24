<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Explicit request: "Match keywords" should be picked from the real Pancake
 * product catalog (GET /shops/{id}/products) instead of free-typed/guessed,
 * same pattern as TSA Management's "Also matches" Pancake-tags picker.
 */
class ProductManagementPosSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');
    }

    private function fakeCatalogPage(array $products, int $pageSize = 100): array
    {
        return ['data' => $products, 'success' => true, 'page_size' => $pageSize];
    }

    public function test_search_returns_matching_real_pos_products(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/products*' => Http::response($this->fakeCatalogPage([
                ['id' => 'p1', 'name' => 'Sinuxyl'],
                ['id' => 'p2', 'name' => 'Sinuxyl Nasal Inhaler'],
                ['id' => 'p3', 'name' => 'Ginsera Serum'],
            ])),
        ]);

        $response = $this->getJson(route('product-management.search-pos-products') . '?q=sinuxyl');

        $response->assertOk();
        $names = collect($response->json())->pluck('name');
        $this->assertTrue($names->contains('Sinuxyl'));
        $this->assertTrue($names->contains('Sinuxyl Nasal Inhaler'));
        $this->assertFalse($names->contains('Ginsera Serum'));
    }

    public function test_search_paginates_through_the_whole_catalog(): void
    {
        $page1 = array_map(fn($i) => ['id' => "p{$i}", 'name' => "Product {$i}"], range(1, 100));
        $page2 = [['id' => 'p101', 'name' => 'Last Product']];

        Http::fake(function ($request) use ($page1, $page2) {
            $page = (int) ($request->data()['page_number'] ?? 1);
            return Http::response($this->fakeCatalogPage($page === 1 ? $page1 : $page2));
        });

        $response = $this->getJson(route('product-management.search-pos-products') . '?q=Last');

        $response->assertOk();
        $names = collect($response->json())->pluck('name');
        $this->assertTrue($names->contains('Last Product'));
    }

    public function test_search_with_no_pancake_credentials_returns_empty_not_an_error(): void
    {
        Setting::set('pancake_api_key', '');
        Setting::set('shop_id', '');

        $response = $this->getJson(route('product-management.search-pos-products') . '?q=anything');

        $response->assertOk();
        $response->assertJson([]);
    }

    public function test_normal_role_user_cannot_reach_product_management_search(): void
    {
        $this->actingAs(User::factory()->normal()->create());

        $this->getJson(route('product-management.search-pos-products') . '?q=x')->assertForbidden();
    }
}
