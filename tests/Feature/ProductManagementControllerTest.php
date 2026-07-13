<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_index_groups_products_by_team(): void
    {
        $response = $this->get(route('product-management'));

        $response->assertOk();
        $response->assertViewHas('teamGroups');
    }

    public function test_store_creates_a_product(): void
    {
        $response = $this->post(route('product-management.store'), [
            'display_name'  => 'NutriLay',
            'match_keyword' => '',
            'team'          => 'SH Naturals',
        ]);

        $response->assertRedirect(route('product-management'));
        $this->assertDatabaseHas('products', [
            'display_name'  => 'NutriLay',
            'match_keyword' => null,
            'team'          => 'SH Naturals',
        ]);
    }

    public function test_store_rejects_a_team_not_in_config(): void
    {
        $response = $this->post(route('product-management.store'), [
            'display_name' => 'Mystery Product',
            'team'         => 'Not A Real Team',
        ]);

        $response->assertSessionHasErrors('team');
        $this->assertDatabaseMissing('products', ['display_name' => 'Mystery Product']);
    }

    public function test_update_changes_a_product(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();

        $response = $this->put(route('product-management.update', $product), [
            'display_name'  => 'Sinuxyl Nasal Spray',
            'match_keyword' => 'SINUXYL',
            'team'          => 'SH Naturals',
        ]);

        $response->assertRedirect(route('product-management'));
        $this->assertDatabaseHas('products', [
            'id'            => $product->id,
            'display_name'  => 'Sinuxyl Nasal Spray',
            'match_keyword' => 'SINUXYL',
        ]);
    }

    public function test_destroy_removes_a_product(): void
    {
        $product = Product::where('display_name', 'MINI GB')->first();

        $response = $this->delete(route('product-management.destroy', $product));

        $response->assertRedirect(route('product-management'));
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_toggle_hidden_hides_a_visible_product(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $this->assertFalse($product->is_hidden);

        $response = $this->patch(route('product-management.toggle-hidden', $product));

        $response->assertRedirect(route('product-management'));
        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_hidden' => true]);
    }

    public function test_toggle_hidden_twice_unhides_it_again(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();

        $this->patch(route('product-management.toggle-hidden', $product));
        $this->patch(route('product-management.toggle-hidden', $product));

        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_hidden' => false]);
    }

    public function test_hidden_products_show_a_hidden_badge_on_the_page(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $product->is_hidden = true;
        $product->save();

        $response = $this->get(route('product-management'));

        $response->assertOk();
        $response->assertSee('Hidden');
    }
}
