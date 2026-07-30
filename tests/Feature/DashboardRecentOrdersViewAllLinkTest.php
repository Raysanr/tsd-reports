<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI/UX fix: Recent Orders' card is stretched (items-stretch on the parent
 * grid) to match Hourly Activity's height, which is usually taller than a
 * flat 10-row table — left a large, purposeless blank gap below the last
 * row. A "View all orders" link pinned to the bottom (mt-auto) fills that
 * space with something useful instead, linking to Leads Report for the same
 * date range currently shown.
 */
class DashboardRecentOrdersViewAllLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_shows_a_view_all_orders_link_pointing_to_leads_report_for_the_same_date_range(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        Order::create([
            'pancake_order_id'   => 'recent-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'product'            => 'Sinuxyl',
            'is_upsell'          => false,
            'status_code'        => 1,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $response->assertSee('View all orders');
        // Checked piecemeal, not as one literal URL string — Blade HTML-escapes
        // the href's "&" to "&amp;", so a raw route()-built string with plain
        // "&" would never substring-match the actual rendered markup.
        $response->assertSee('/leads-report?', false);
        $response->assertSee('team=all', false);
        $response->assertSee('range=dates', false);
        $response->assertSee('date_from=' . $today, false);
        $response->assertSee('date_to=' . $today, false);
    }

    public function test_does_not_show_the_link_when_there_are_no_recent_orders(): void
    {
        $today = now()->toDateString();
        $response = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $response->assertDontSee('View all orders');
    }
}
