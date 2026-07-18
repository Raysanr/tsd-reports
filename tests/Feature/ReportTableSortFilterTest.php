<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Lightweight presence checks for the sortable-column + live-filter markup
// added to the per-entity report tables (resources/js/app.js's
// data-sortable-table controller). Mirrors how earlier work on this app
// tested toast/dark-mode markup presence — not a JS behavior test (no JS
// test runner exists in this project), just proof the attributes render.
class ReportTableSortFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    // leads-report.blade.php's Orders table — one row per order. The Orders
    // table only renders (instead of the "No orders found" empty state) once
    // there's at least one order in the selected window, so seed one.
    public function test_leads_report_orders_table_has_sort_and_filter_markup(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        Order::create([
            'pancake_order_id'   => 'sort-filter-test-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'product'            => 'CANPRO',
            'raw_tags'           => ['CANPRO', strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell'          => false,
            'status_code'        => 1,
            'amount'             => 999,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $response = $this->get(route('leads-report'));

        $response->assertOk();
        $response->assertViewIs('leads-report');
        $response->assertSee('data-sortable-table', false);
        $response->assertSee('data-table-filter="ordersTable"', false);
        $response->assertSee('data-sort-key="orderId"', false);
    }

    // leads-report-all.blade.php (team=all) — one row per product, combined
    // across every team.
    public function test_leads_report_all_products_table_has_sort_and_filter_markup(): void
    {
        $response = $this->get(route('leads-report', ['team' => 'all']));

        $response->assertOk();
        $response->assertViewIs('leads-report-all');
        $response->assertSee('data-sortable-table', false);
        $response->assertSee('data-table-filter="productAllTable"', false);
        $response->assertSee('data-sort-key="product"', false);
    }

    // rts-report.blade.php's per-team tables — one row per TSA.
    public function test_rts_report_team_tables_have_sort_and_filter_markup(): void
    {
        $response = $this->get(route('rts-report'));

        $response->assertOk();
        $response->assertViewIs('rts-report');
        $response->assertSee('data-sortable-table', false);
        $response->assertSee('data-table-filter="rtsTable-0"', false);
        $response->assertSee('data-sort-key="rts"', false);
    }

    // tsa-performance-all.blade.php (team=all) — one row per TSA, combined
    // across every team.
    public function test_tsa_performance_all_table_has_sort_and_filter_markup(): void
    {
        $response = $this->get(route('tsa-performance', ['team' => 'all']));

        $response->assertOk();
        $response->assertViewIs('tsa-performance-all');
        $response->assertSee('data-sortable-table', false);
        $response->assertSee('data-table-filter="tsaPerfAllTable"', false);
        $response->assertSee('data-sort-key="tsa"', false);
    }

    // The hourly pivot tables (tsa-performance.blade.php's main grid,
    // tsa-performance-individual.blade.php's hourly breakdowns) must NOT gain
    // this markup — reordering by a metric would scramble their chronology.
    public function test_tsa_performance_hourly_view_has_no_sort_or_filter_markup(): void
    {
        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals']));

        $response->assertOk();
        $response->assertViewIs('tsa-performance');
        $response->assertDontSee('data-sortable-table', false);
        $response->assertDontSee('data-table-filter', false);
    }
}
