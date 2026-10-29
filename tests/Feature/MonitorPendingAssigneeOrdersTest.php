<?php

namespace Tests\Feature;

use App\Models\PendingAssigneeOrder;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Explicit request, 2026-10-29: orders the lead sync is holding for an
 *  Assignee show on Monitor TSA's "Leads waiting" panel with a countdown. */
class MonitorPendingAssigneeOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_order_waiting_for_an_assignee_is_listed_with_its_countdown(): void
    {
        $product = Product::create(['display_name' => 'Nutrilay', 'match_keyword' => 'Nutrilay', 'team' => 'SH Naturals', 'sort_order' => 0]);
        PendingAssigneeOrder::create([
            'pancake_order_id'   => '1385999',
            'customer_name'      => 'Held Customer',
            'product_id'         => $product->id,
            'pancake_created_at' => now()->subMinutes(2),
            'release_at'         => now()->addMinutes(3)->addSeconds(10),
        ]);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('calls.monitor'));

        $response->assertOk();
        $response->assertSee('Waiting for assignee · 1');
        $response->assertSee('Held Customer');
        $response->assertSee('#1385999');
        $response->assertSee('in 3m');
    }

    public function test_the_section_is_hidden_when_nothing_is_waiting_for_an_assignee(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('calls.monitor'));

        $response->assertOk();
        $response->assertDontSee('Waiting for assignee');
    }
}
