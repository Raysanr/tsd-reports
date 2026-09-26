<?php

namespace Tests\Feature;

use App\Models\ExpectedIncomeEntry;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request, 2026-09-26: "analyze this expected income and add it
 * to the data management module." Smoke-level only — confirms the page
 * renders (grouped by team, with a TEAM total row) and the auto-save
 * endpoint recomputes correctly; the derived formulas themselves are
 * independently verified against the real "EXPECTED INCOME 2026" tab in
 * ExpectedIncomeCalculatorTest.
 */
class ExpectedIncomeReportSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_the_report_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        ExpectedIncomeEntry::create([
            'product_id' => $product->id,
            'month' => today()->startOfMonth(),
            'number_of_leads' => 647,
            'number_of_orders' => 112,
            'average_order_value' => 804.46,
        ]);

        $response = $this->actingAs($admin)->get(route('data.expected-income'));

        $response->assertOk();
        $response->assertSee(strtoupper($product->display_name));
        $response->assertSee('TOTAL — ' . strtoupper($product->team));
    }

    public function test_a_non_admin_cannot_view_the_report_page(): void
    {
        $tsaUser = User::factory()->create(['role' => 'normal']);

        $response = $this->actingAs($tsaUser)->get(route('data.expected-income'));

        $response->assertForbidden();
    }

    public function test_updating_a_cell_upserts_and_returns_recomputed_figures(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $month = today()->format('Y-m');

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update', ['product' => $product->id, 'month' => $month]),
            ['number_of_leads' => 647, 'number_of_orders' => 112, 'average_order_value' => 804.46]
        );

        $response->assertOk();
        $response->assertJsonPath('derived.gross_sales', fn ($v) => abs($v - 90099.52) < 1.0);

        $this->assertDatabaseHas('expected_income_entries', [
            'product_id' => $product->id,
            'number_of_orders' => 112,
        ]);
    }

    public function test_updating_an_existing_entry_does_not_create_a_duplicate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $month = today()->format('Y-m');

        $this->actingAs($admin)->patchJson(
            route('data.expected-income.update', ['product' => $product->id, 'month' => $month]),
            ['number_of_orders' => 100]
        );
        $this->actingAs($admin)->patchJson(
            route('data.expected-income.update', ['product' => $product->id, 'month' => $month]),
            ['number_of_orders' => 200]
        );

        $this->assertSame(1, ExpectedIncomeEntry::where('product_id', $product->id)->whereDate('month', today()->startOfMonth())->count());
        $this->assertSame(200, ExpectedIncomeEntry::where('product_id', $product->id)->whereDate('month', today()->startOfMonth())->first()->number_of_orders);
    }

    public function test_updating_one_product_returns_its_teams_total(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $month = today()->format('Y-m');

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update', ['product' => $product->id, 'month' => $month]),
            ['number_of_orders' => 10, 'average_order_value' => 100]
        );

        $response->assertOk();
        $response->assertJsonStructure(['success', 'derived', 'team_total' => ['gross_sales', 'net_income']]);
    }
}
