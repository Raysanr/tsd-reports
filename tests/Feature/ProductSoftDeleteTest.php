<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_deleting_a_product_soft_deletes_it_not_hard_deletes(): void
    {
        $product = Product::create(['display_name' => 'Widget', 'team' => 'SH Naturals', 'sort_order' => 1]);

        $this->delete(route('product-management.destroy', $product))->assertRedirect();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id]); // row still exists
    }

    public function test_deleted_product_does_not_appear_in_the_active_index(): void
    {
        $product = Product::create(['display_name' => 'Widget', 'team' => 'SH Naturals', 'sort_order' => 1]);
        $product->delete();

        $response = $this->get(route('product-management'));

        $response->assertOk();
        $response->assertViewHas('trashedProducts', function ($trashed) use ($product) {
            return $trashed->pluck('id')->contains($product->id);
        });
    }

    public function test_deleted_product_does_not_appear_in_team_groups(): void
    {
        $product = Product::create(['display_name' => 'Widget', 'team' => 'SH Naturals', 'sort_order' => 1]);
        $product->delete();

        $response = $this->get(route('product-management'));

        $response->assertOk();
        $response->assertViewHas('teamGroups', function ($teamGroups) use ($product) {
            foreach ($teamGroups as $group) {
                if ($group['products']->pluck('id')->contains($product->id)) {
                    return false;
                }
            }
            return true;
        });
    }

    public function test_restoring_a_deleted_product_brings_it_back(): void
    {
        $product = Product::create(['display_name' => 'Widget', 'team' => 'SH Naturals', 'sort_order' => 1]);
        $product->delete();

        $response = $this->post(route('product-management.restore', $product->id));

        $response->assertRedirect(route('product-management'));
        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }

    public function test_restore_route_404s_for_a_product_that_was_never_deleted(): void
    {
        $product = Product::create(['display_name' => 'Widget', 'team' => 'SH Naturals', 'sort_order' => 1]);

        $this->post(route('product-management.restore', $product->id))->assertNotFound();
    }
}
