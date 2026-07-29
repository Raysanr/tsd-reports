<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnmatchedOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_role_user_cannot_access_unmatched_orders(): void
    {
        $this->actingAs(User::factory()->normal()->create());
        $this->get(route('unmatched-orders'))->assertForbidden();
    }

    public function test_page_lists_only_team_null_orders(): void
    {
        $this->actingAs(User::factory()->create());

        Order::create(['pancake_order_id' => '1', 'team' => 'SH Naturals', 'amount' => 100, 'raw_tags' => ['SH Naturals']]);
        Order::create(['pancake_order_id' => '2', 'team' => null, 'product' => 'Mystery Item', 'amount' => 200, 'raw_tags' => ['UNKNOWNTAG']]);

        $response = $this->get(route('unmatched-orders'));

        $response->assertOk();
        $response->assertViewHas('totalUnmatched', 1);
        $response->assertSee('Mystery Item', false);
        // Plain "#1" isn't a safe needle here: the shared layout's inline flatpickr
        // dark-mode CSS contains hex colors like "#1e293b", which contains "#1" as a
        // substring and false-positives against every page using this layout. Assert
        // on the matched order's actual distinguishing content (its tag) instead —
        // that content genuinely shouldn't render anywhere in this unmatched-only list.
        $response->assertDontSee('SH Naturals', false);
    }

    public function test_empty_state_shows_when_nothing_is_unmatched(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('unmatched-orders'));

        $response->assertOk();
        $response->assertViewHas('totalUnmatched', 0);
    }

    public function test_reinfer_button_recovers_orders_matching_a_current_product_keyword(): void
    {
        $this->actingAs(User::factory()->create());

        Product::create(['display_name' => 'Clear Sight 3.0', 'match_keyword' => 'CLEARSIGHT', 'team' => 'SH Naturals', 'sort_order' => 1]);
        Order::create(['pancake_order_id' => '3', 'team' => null, 'product' => 'Clear Sight 3.0', 'amount' => 300, 'raw_tags' => []]);

        $response = $this->post(route('unmatched-orders.reinfer'));

        $response->assertRedirect(route('unmatched-orders'));
        $this->assertDatabaseHas('orders', ['pancake_order_id' => '3', 'team' => 'SH Naturals']);
    }

    /**
     * UI/UX review finding: the real task on this page is "which product
     * names are missing from Product Management," not "read every individual
     * order" — a flat chronological list doesn't scale once it runs into the
     * thousands. This confirms the grouped summary actually groups and sums
     * correctly, and doesn't collapse two different missing products
     * together.
     */
    public function test_by_product_groups_and_sums_correctly(): void
    {
        $this->actingAs(User::factory()->create());

        Order::create(['pancake_order_id' => '10', 'team' => null, 'product' => 'NutriLay', 'amount' => 100, 'raw_tags' => []]);
        Order::create(['pancake_order_id' => '11', 'team' => null, 'product' => 'NutriLay', 'amount' => 150, 'raw_tags' => []]);
        Order::create(['pancake_order_id' => '12', 'team' => null, 'product' => 'VitaMax', 'amount' => 50, 'raw_tags' => []]);

        $response = $this->get(route('unmatched-orders'));

        $response->assertOk();
        $response->assertViewHas('byProduct', function ($rows) {
            $nutriLay = $rows->firstWhere('product_name', 'NutriLay');
            $vitaMax  = $rows->firstWhere('product_name', 'VitaMax');
            return $nutriLay && (int) $nutriLay->order_count === 2 && (float) $nutriLay->total_amount === 250.0
                && $vitaMax && (int) $vitaMax->order_count === 1 && (float) $vitaMax->total_amount === 50.0;
        });
    }

    public function test_clicking_a_product_filters_the_order_list_server_side(): void
    {
        $this->actingAs(User::factory()->create());

        Order::create(['pancake_order_id' => '20', 'team' => null, 'product' => 'NutriLay', 'amount' => 100, 'raw_tags' => []]);
        Order::create(['pancake_order_id' => '21', 'team' => null, 'product' => 'VitaMax', 'amount' => 50, 'raw_tags' => []]);

        $response = $this->get(route('unmatched-orders', ['product' => 'NutriLay']));

        $response->assertOk();
        $response->assertViewHas('orders', fn ($orders) => $orders->total() === 1 && $orders->first()->pancake_order_id === '20');
        // totalUnmatched stays the whole-system count regardless of the
        // active product filter — it's the KPI tile above, not this list.
        $response->assertViewHas('totalUnmatched', 2);
    }

    public function test_filtering_by_the_no_product_name_group_matches_a_genuinely_null_product(): void
    {
        $this->actingAs(User::factory()->create());

        Order::create(['pancake_order_id' => '30', 'team' => null, 'product' => null, 'amount' => 75, 'raw_tags' => []]);
        Order::create(['pancake_order_id' => '31', 'team' => null, 'product' => 'NutriLay', 'amount' => 100, 'raw_tags' => []]);

        $response = $this->get(route('unmatched-orders', ['product' => '(no product name)']));

        $response->assertOk();
        $response->assertViewHas('orders', fn ($orders) => $orders->total() === 1 && $orders->first()->pancake_order_id === '30');
    }
}
