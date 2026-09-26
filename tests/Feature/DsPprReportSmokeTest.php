<?php

namespace Tests\Feature;

use App\Models\DsPprEntry;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request, 2026-09-24: "create this page like DSPPR - TSM
 * REPORT ... exactly like in the sheets." Smoke-level only — confirms the
 * page renders and the auto-save endpoint recomputes correctly; the
 * derived formulas themselves are independently verified against the
 * real source sheet's own Sep 1 Clearsight row in DsPprCalculator's own
 * doc comment testing during development (NI%, AOV, Excess Leads,
 * Actual Cost Per Lead all matched exactly).
 */
class DsPprReportSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_the_report_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        DsPprEntry::create([
            'product_id' => $product->id,
            'entry_date' => today(),
            'gross_sales' => 3800,
            'net_income' => -8208.10,
            'ads_spent' => 3024.31,
            'total_orders' => 6,
            'total_leads' => 44,
            'catered_leads' => 33,
        ]);

        $response = $this->actingAs($admin)->get(route('data.dsppr'));

        $response->assertOk();
        $response->assertSee(strtoupper($product->display_name));
        $response->assertSee('OVERALL TOTAL');
    }

    public function test_a_non_admin_cannot_view_the_report_page(): void
    {
        $tsaUser = User::factory()->create(['role' => 'normal']);

        $response = $this->actingAs($tsaUser)->get(route('data.dsppr'));

        $response->assertForbidden();
    }

    public function test_updating_a_cell_upserts_and_returns_recomputed_figures(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();

        $response = $this->actingAs($admin)->patchJson(
            route('data.dsppr.update', ['product' => $product->id, 'date' => today()->toDateString()]),
            ['gross_sales' => 3800, 'net_income' => -8208.10]
        );

        $response->assertOk();
        $response->assertJsonPath('derived.ni_pct', fn ($v) => abs($v - (-8208.10 / 3800)) < 0.0001);

        $this->assertDatabaseHas('dsppr_entries', [
            'product_id' => $product->id,
            'gross_sales' => 3800,
        ]);
    }

    public function test_updating_an_existing_entry_does_not_create_a_duplicate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $date = today()->toDateString();

        $this->actingAs($admin)->patchJson(
            route('data.dsppr.update', ['product' => $product->id, 'date' => $date]),
            ['gross_sales' => 1000]
        );
        $this->actingAs($admin)->patchJson(
            route('data.dsppr.update', ['product' => $product->id, 'date' => $date]),
            ['gross_sales' => 2000]
        );

        $this->assertSame(1, DsPprEntry::where('product_id', $product->id)->whereDate('entry_date', $date)->count());
        $this->assertSame(2000.0, DsPprEntry::where('product_id', $product->id)->whereDate('entry_date', $date)->first()->gross_sales);
    }

    /**
     * Root-caused 2026-09-26 while building Expected Income's own identical
     * query: entry_date is stored as a full 'Y-m-d H:i:s' datetime string,
     * and a plain whereBetween($dateFrom, $dateTo) compares SQLite's stored
     * value against the bare date bound LEXICOGRAPHICALLY — so
     * '2026-09-26 00:00:00' (the LAST day of a range) sorts AFTER the bound
     * '2026-09-26' and gets silently dropped from both the summary row and
     * every TOTAL row. Fixed via whereDate() >=/<= instead, which extracts
     * just the date part before comparing.
     */
    public function test_the_last_day_of_a_selected_range_is_not_dropped_from_the_summary(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();

        DsPprEntry::create(['product_id' => $product->id, 'entry_date' => today()->subDay(), 'gross_sales' => 1000, 'total_orders' => 1]);
        DsPprEntry::create(['product_id' => $product->id, 'entry_date' => today(), 'gross_sales' => 500, 'total_orders' => 1]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => today()->subDay()->toDateString(),
            'date_to' => today()->toDateString(),
        ]));

        $response->assertOk();
        // 1,000 + 500 = 1,500 summed Gross Sales across both days — would
        // read 1,000.00 (today's entry silently dropped) if the bug regressed.
        $response->assertSee('1,500.00');
    }
}
