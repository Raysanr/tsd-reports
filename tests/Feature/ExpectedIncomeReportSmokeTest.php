<?php

namespace Tests\Feature;

use App\Models\ExpectedIncomeEntry;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request, 2026-09-26: "analyze this expected income and add it
 * to the data management module ... i want exactly like this like in the
 * sheets like every product is has card ... in the top there's expected
 * sales and after that it is dates going down." Smoke-level only —
 * confirms the page renders (a range-summary card row, then one card row
 * per calendar day) and the per-day auto-save endpoint upserts and
 * recomputes correctly; the derived formulas themselves are independently
 * verified against the real "EXPECTED INCOME 2026" tab in
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
            'entry_date' => today(),
            'number_of_leads' => 647,
            'number_of_orders' => 112,
            'average_order_value' => 804.46,
        ]);

        $response = $this->actingAs($admin)->get(route('data.expected-income'));

        $response->assertOk();
        $response->assertSee($product->display_name);
        $response->assertSee('Telesales Expected Performance');
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
        $date = today()->toDateString();

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update', ['product' => $product->id, 'date' => $date]),
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
        $date = today()->toDateString();

        $this->actingAs($admin)->patchJson(
            route('data.expected-income.update', ['product' => $product->id, 'date' => $date]),
            ['number_of_orders' => 100]
        );
        $this->actingAs($admin)->patchJson(
            route('data.expected-income.update', ['product' => $product->id, 'date' => $date]),
            ['number_of_orders' => 200]
        );

        $this->assertSame(1, ExpectedIncomeEntry::where('product_id', $product->id)->whereDate('entry_date', $date)->count());
        $this->assertSame(200, ExpectedIncomeEntry::where('product_id', $product->id)->whereDate('entry_date', $date)->first()->number_of_orders);
    }

    public function test_summary_row_sums_every_day_in_the_selected_range(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();

        ExpectedIncomeEntry::create(['product_id' => $product->id, 'entry_date' => today()->subDay(), 'number_of_orders' => 10, 'average_order_value' => 100]);
        ExpectedIncomeEntry::create(['product_id' => $product->id, 'entry_date' => today(), 'number_of_orders' => 5, 'average_order_value' => 100]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->subDay()->toDateString(),
            'date_to' => today()->toDateString(),
        ]));

        $response->assertOk();
        // 15 orders × 100 AOV = 1,500 summed Gross Sales across both days.
        $response->assertSee('1,500.00');
    }

    /** Same off-by-one root-caused 2026-09-27 in DsPprReportController's
     *  own identical daysUntil()->addDay() call — see that test's own doc
     *  comment for the full root cause (daysUntil() is already inclusive
     *  of its own end date). */
    public function test_the_daily_rows_never_show_a_day_past_the_selected_range(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => '2026-09-21',
            'date_to' => '2026-09-30',
        ]));

        $response->assertOk();
        $response->assertSee('September 30, 2026');
        $response->assertDontSee('October 1, 2026');
    }

    /** Explicit request, 2026-09-26: a product group created on DSPPR
     *  "will reflect it to the expected income" — grouped products show as
     *  ONE combined card here too, not two separate ones, on both the
     *  range-summary row and every day's own row. */
    public function test_a_product_group_shows_one_combined_card_instead_of_two(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $productA = Product::orderBy('id')->first();
        $productB = Product::orderBy('id')->skip(1)->first();

        ExpectedIncomeEntry::create(['product_id' => $productA->id, 'entry_date' => today(), 'number_of_orders' => 10, 'average_order_value' => 100]);
        ExpectedIncomeEntry::create(['product_id' => $productB->id, 'entry_date' => today(), 'number_of_orders' => 5, 'average_order_value' => 100]);

        $group = ProductGroup::create(['label' => 'TO', 'sort_order' => 0]);
        $group->products()->attach([$productA->id, $productB->id]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(),
            'date_to' => today()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertDontSee($productA->display_name);
        $response->assertDontSee($productB->display_name);
        $response->assertSee('TO');
        // 10 + 5 = 15 orders × 100 AOV = 1,500 Gross Sales, summed once.
        $response->assertSee('1,500.00');
    }

    /** Explicit request, 2026-09-28: "when i add in the + icon in the
     *  projections it should be automatically added to the expected income
     *  rows" — a row added via Projections' own custom-row endpoint shows
     *  up here too, under the same section, with zero extra step. */
    public function test_a_custom_row_added_on_projections_appears_on_this_page_too(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        \App\Models\ProjectionCustomRow::create([
            'key' => 'custom_warehouse_fee', 'section' => 'selling',
            'label' => 'Warehouse Fee', 'is_fixed' => false, 'sort_order' => 0,
        ]);

        $response = $this->actingAs($admin)->get(route('data.expected-income'));

        $response->assertOk();
        $response->assertSee('Warehouse Fee');
    }

    /** A row under 'operating' shows up under Operating Costs here, not
     *  Selling And Marketing — the section carries over too, not just the
     *  row's own name. */
    public function test_an_operating_section_custom_row_appears_under_operating_costs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        \App\Models\ProjectionCustomRow::create([
            'key' => 'custom_office_snacks', 'section' => 'operating',
            'label' => 'Office Snacks', 'is_fixed' => false, 'sort_order' => 0,
        ]);

        $response = $this->actingAs($admin)->get(route('data.expected-income'));

        $response->assertOk();
        $response->assertSee('Office Snacks');
    }

    /** Saving a custom row's own dollar value (a distinct endpoint from the
     *  fixed-field update() above, since a custom row's key is dynamic —
     *  see ExpectedIncomeController::updateCustomRow()'s own doc comment)
     *  persists it and folds it into Net Income, exactly like a built-in
     *  row's value would. */
    public function test_updating_a_custom_rows_value_persists_and_affects_net_income(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $date = today()->toDateString();

        \App\Models\ProjectionCustomRow::create([
            'key' => 'custom_warehouse_fee', 'section' => 'selling',
            'label' => 'Warehouse Fee', 'is_fixed' => false, 'sort_order' => 0,
        ]);

        ExpectedIncomeEntry::create(['product_id' => $product->id, 'entry_date' => $date, 'number_of_orders' => 10, 'average_order_value' => 100]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-custom-row', ['product' => $product->id, 'date' => $date]),
            ['key' => 'custom_warehouse_fee', 'value' => 250]
        );

        $response->assertOk();
        $response->assertJsonPath('derived.selling_lines.custom_warehouse_fee', fn ($v) => abs($v - 250.0) < 0.01);
        // Gross Sales 1,000 minus the 250 custom row (no other costs) —
        // confirms it actually reduces Net Income, not just displaying.
        $response->assertJsonPath('derived.net_income', fn ($v) => abs($v - (1000 - 1000 * 0.05 - 1000 * 0.25 - 250 - (1000 * 0.70 * 0.0224) - (10 * 25))) < 0.01);

        $this->assertDatabaseHas('expected_income_custom_values', [
            'product_id' => $product->id, 'custom_row_key' => 'custom_warehouse_fee', 'value' => 250.00,
        ]);
    }

    /** Root-caused live, 2026-09-28: typing ONLY into a custom row's field
     *  (never any built-in field) creates an ExpectedIncomeCustomValue but
     *  no ExpectedIncomeEntry at all for that product+day. Every earlier
     *  version of index() iterated $entries/$dailyByKey directly and never
     *  visited that product/day at all, so the already-saved custom value
     *  displayed correctly right after saving (the PATCH response includes
     *  it directly) but silently reverted to 0.00 on the very next full
     *  page load, since nothing there ever read it back. */
    public function test_a_custom_rows_value_survives_a_page_reload_with_no_built_in_fields_ever_saved(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $date = today()->toDateString();

        \App\Models\ProjectionCustomRow::create([
            'key' => 'custom_warehouse_fee', 'section' => 'selling',
            'label' => 'Warehouse Fee', 'is_fixed' => false, 'sort_order' => 0,
        ]);

        // No ExpectedIncomeEntry::create() here at all — this is the
        // exact "custom field only, never any built-in field" scenario.
        $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-custom-row', ['product' => $product->id, 'date' => $date]),
            ['key' => 'custom_warehouse_fee', 'value' => 333.50]
        )->assertOk();

        $this->assertDatabaseMissing('expected_income_entries', ['product_id' => $product->id]);

        // A fresh GET of the index page — simulating a reload — must still
        // show the value, not silently reset it to 0.00.
        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $date, 'date_to' => $date,
        ]));

        $response->assertOk();
        $response->assertSee('333.50');
    }

    /** Saving a custom row's value twice for the same product+day upserts,
     *  never creates a duplicate row — same convention as every other
     *  per-cell save on this page. */
    public function test_updating_a_custom_rows_value_twice_does_not_create_a_duplicate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $date = today()->toDateString();

        \App\Models\ProjectionCustomRow::create([
            'key' => 'custom_warehouse_fee', 'section' => 'selling',
            'label' => 'Warehouse Fee', 'is_fixed' => false, 'sort_order' => 0,
        ]);

        $route = route('data.expected-income.update-custom-row', ['product' => $product->id, 'date' => $date]);
        $this->actingAs($admin)->patchJson($route, ['key' => 'custom_warehouse_fee', 'value' => 100])->assertOk();
        $this->actingAs($admin)->patchJson($route, ['key' => 'custom_warehouse_fee', 'value' => 200])->assertOk();

        $this->assertSame(1, \App\Models\ExpectedIncomeCustomValue::where('product_id', $product->id)->where('custom_row_key', 'custom_warehouse_fee')->count());
        $this->assertSame('200.00', \App\Models\ExpectedIncomeCustomValue::where('product_id', $product->id)->where('custom_row_key', 'custom_warehouse_fee')->first()->value);
    }

    /** A key not in the real allowed set (a typo, or a row that was since
     *  removed on Projections) is rejected, not silently saved under an
     *  orphaned key nothing will ever read back. */
    public function test_an_unknown_custom_row_key_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-custom-row', ['product' => $product->id, 'date' => today()->toDateString()]),
            ['key' => 'not_a_real_row', 'value' => 100]
        );

        $response->assertStatus(422);
    }

    /** Root-caused live, 2026-09-28, while building the custom-row
     *  reflection above: both overall rollup cards ("Telesales Expected
     *  Performance" and each day's own "TELESALES — [date]") were computed
     *  by re-summing already-DERIVED product rows, but sum()'s own
     *  $row[$key] lookups only ever find a Selling/Operating line as a
     *  TOP-LEVEL array key — a derived row nests every one of those under
     *  selling_lines/operating_lines instead, so a manually-typed
     *  Advertising Cost (or any other line) silently read back as 0 on the
     *  overall card while still showing correctly on the individual
     *  product's own card. Fixed by summing every product's own RAW row
     *  instead (see ExpectedIncomeController::index()'s own
     *  $allEntriesRaw/$dailyOverallTotals). */
    public function test_the_range_summary_overall_total_includes_a_manually_entered_selling_cost(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();

        ExpectedIncomeEntry::create([
            'product_id' => $product->id, 'entry_date' => today(),
            'number_of_orders' => 10, 'average_order_value' => 100, 'advertising_cost' => 500,
        ]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(),
            'date_to' => today()->toDateString(),
        ]));

        $response->assertOk();
        // Gross Sales 1,000; Cancelled 50, Returns 250, COD Fee (700×2.24%)
        // 15.68, Fulfillment Fee (10×25) 250, plus the manual 500
        // Advertising Cost = Total Selling Costs 765.68. Before the fix
        // this overall card's own total silently read 265.68 (missing the
        // manual 500) even though the individual product card was correct.
        $response->assertSee('765.68');
    }

    /** Same bug, the daily "TELESALES — [date]" rollup card. */
    public function test_a_days_overall_total_includes_a_manually_entered_selling_cost(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $date = today()->toDateString();

        ExpectedIncomeEntry::create([
            'product_id' => $product->id, 'entry_date' => $date,
            'number_of_orders' => 10, 'average_order_value' => 100, 'advertising_cost' => 500,
        ]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $date, 'date_to' => $date,
        ]));

        $response->assertOk();
        // Same math as the range-summary test above — with only one day
        // and one product in range, the day's own overall card and the
        // range summary's overall card show the identical total.
        $response->assertSeeInOrder(['TELESALES — ' . today()->format('F j, Y'), '765.68']);
    }
}
