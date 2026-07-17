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

    public function test_destroy_soft_deletes_a_product(): void
    {
        // Destroy now soft-deletes (see ProductSoftDeleteTest for full coverage) —
        // the row is no longer hard-deleted, so this only checks it's gone from
        // the active/default query, not gone from the table entirely.
        $product = Product::where('display_name', 'MINI GB')->first();

        $response = $this->delete(route('product-management.destroy', $product));

        $response->assertRedirect(route('product-management'));
        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('products', ['id' => $product->id, 'deleted_at' => null]);
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

    public function test_visible_products_do_not_show_a_hidden_badge(): void
    {
        // Checks for the badge's rendered text specifically, not the bare
        // substring "Hidden" — the toggle button's own CSS class
        // ("toggleHiddenBtn") contains that substring on every row regardless
        // of state, which would make a plain assertDontSee('Hidden') always fail.
        $response = $this->get(route('product-management'));

        $response->assertOk();
        $response->assertDontSee('>Hidden</span>', false);
    }

    public function test_bulk_hide_hides_multiple_products(): void
    {
        $ids = Product::whereIn('display_name', ['SINUXYL', 'SINUVEX'])->pluck('id');

        $response = $this->post(route('product-management.bulk'), [
            'ids'    => $ids->all(),
            'action' => 'hide',
        ]);

        $response->assertRedirect(route('product-management'));
        foreach ($ids as $id) {
            $this->assertDatabaseHas('products', ['id' => $id, 'is_hidden' => true]);
        }
    }

    public function test_bulk_unhide_unhides_multiple_products(): void
    {
        $ids = Product::whereIn('display_name', ['SINUXYL', 'SINUVEX'])->pluck('id');
        Product::whereIn('id', $ids)->update(['is_hidden' => true]);

        $response = $this->post(route('product-management.bulk'), [
            'ids'    => $ids->all(),
            'action' => 'unhide',
        ]);

        $response->assertRedirect(route('product-management'));
        foreach ($ids as $id) {
            $this->assertDatabaseHas('products', ['id' => $id, 'is_hidden' => false]);
        }
    }

    public function test_bulk_move_changes_team_for_multiple_products(): void
    {
        $ids = Product::whereIn('display_name', ['SINUXYL', 'SINUVEX'])->pluck('id');
        $this->assertDatabaseHas('products', ['id' => $ids->first(), 'team' => 'SH Naturals']);

        $response = $this->post(route('product-management.bulk'), [
            'ids'    => $ids->all(),
            'action' => 'move',
            'team'   => 'Eyecare Team',
        ]);

        $response->assertRedirect(route('product-management'));
        foreach ($ids as $id) {
            $this->assertDatabaseHas('products', ['id' => $id, 'team' => 'Eyecare Team']);
        }
    }

    public function test_bulk_delete_soft_deletes_multiple_products(): void
    {
        $ids = Product::whereIn('display_name', ['SINUXYL', 'SINUVEX'])->pluck('id');

        $response = $this->post(route('product-management.bulk'), [
            'ids'    => $ids->all(),
            'action' => 'delete',
        ]);

        $response->assertRedirect(route('product-management'));
        foreach ($ids as $id) {
            $this->assertSoftDeleted('products', ['id' => $id]);
        }
    }

    public function test_bulk_move_without_a_team_fails_validation(): void
    {
        $ids = Product::whereIn('display_name', ['SINUXYL', 'SINUVEX'])->pluck('id');

        $response = $this->post(route('product-management.bulk'), [
            'ids'    => $ids->all(),
            'action' => 'move',
        ]);

        $response->assertSessionHasErrors('team');
        $this->assertDatabaseHas('products', ['id' => $ids->first(), 'team' => 'SH Naturals']);
    }
}
