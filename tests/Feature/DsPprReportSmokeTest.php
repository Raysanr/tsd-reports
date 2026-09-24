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
}
