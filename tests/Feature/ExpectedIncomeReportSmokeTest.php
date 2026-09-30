<?php

namespace Tests\Feature;

use App\Models\ExpectedIncomeEntry;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\TsaShift;
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
            'gross_sales' => 90100.00,
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
            ['number_of_leads' => 647, 'number_of_orders' => 112, 'average_order_value' => 804.46, 'gross_sales' => 90099.52]
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

        ExpectedIncomeEntry::create(['product_id' => $product->id, 'entry_date' => today()->subDay(), 'number_of_orders' => 10, 'average_order_value' => 100, 'gross_sales' => 1000]);
        ExpectedIncomeEntry::create(['product_id' => $product->id, 'entry_date' => today(), 'number_of_orders' => 5, 'average_order_value' => 100, 'gross_sales' => 500]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->subDay()->toDateString(),
            'date_to' => today()->toDateString(),
        ]));

        $response->assertOk();
        // 1,000 + 500 = 1,500 summed Gross Sales across both days.
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

        ExpectedIncomeEntry::create(['product_id' => $productA->id, 'entry_date' => today(), 'number_of_orders' => 10, 'average_order_value' => 100, 'gross_sales' => 1000]);
        ExpectedIncomeEntry::create(['product_id' => $productB->id, 'entry_date' => today(), 'number_of_orders' => 5, 'average_order_value' => 100, 'gross_sales' => 500]);

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
        // 1,000 + 500 = 1,500 Gross Sales, summed once.
        $response->assertSee('1,500.00');
    }

    /** Explicit follow-up, 2026-09-29: "make it editable because in the
     *  side of users the merged products is only 1 product only" — same
     *  fix already applied to DSPPR's own identical grouping — a grouped
     *  card now saves to the group's own FIRST member product. */
    public function test_updating_a_grouped_products_cell_saves_to_its_first_member(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $productA = Product::orderBy('id')->first();
        $productB = Product::orderBy('id')->skip(1)->first();
        $date = today()->toDateString();

        $group = ProductGroup::create(['label' => 'TO', 'sort_order' => 0]);
        $group->products()->attach([$productA->id, $productB->id]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update', ['product' => $productA->id, 'date' => $date]),
            ['gross_sales' => 2000]
        );

        $response->assertOk();
        $this->assertDatabaseHas('expected_income_entries', [
            'product_id' => $productA->id,
            'gross_sales' => 2000,
        ]);
    }

    /** The group's own DISPLAYED figures must keep reflecting the FULL
     *  group total after an edit, not just the one member the edit landed
     *  on — the other member's own previously-saved numbers are still real
     *  and still part of what this card shows. */
    public function test_updating_a_grouped_products_cell_returns_the_full_group_total(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $productA = Product::orderBy('id')->first();
        $productB = Product::orderBy('id')->skip(1)->first();
        $date = today()->toDateString();

        // productB already has its own real, separately-saved data.
        ExpectedIncomeEntry::create(['product_id' => $productB->id, 'entry_date' => $date, 'gross_sales' => 500]);

        $group = ProductGroup::create(['label' => 'TO', 'sort_order' => 0]);
        $group->products()->attach([$productA->id, $productB->id]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update', ['product' => $productA->id, 'date' => $date]),
            ['gross_sales' => 1000]
        );

        $response->assertOk();
        // 1,000 (just-edited productA) + 500 (productB's own untouched
        // data) = 1,500 — the group's TRUE combined total, not just
        // productA's own 1,000.
        $response->assertJsonPath('derived.gross_sales', fn ($v) => (float) $v === 1500.0);
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

        ExpectedIncomeEntry::create(['product_id' => $product->id, 'entry_date' => $date, 'number_of_orders' => 10, 'average_order_value' => 100, 'gross_sales' => 1000, 'cancelled' => 50, 'returns' => 250, 'delivered' => 700]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-custom-row', ['product' => $product->id, 'date' => $date]),
            ['key' => 'custom_warehouse_fee', 'value' => 250]
        );

        $response->assertOk();
        $response->assertJsonPath('derived.selling_lines.custom_warehouse_fee', fn ($v) => abs($v - 250.0) < 0.01);
        // Gross Sales 1,000 minus the 250 custom row (no other costs) —
        // confirms it actually reduces Net Income, not just displaying.
        $response->assertJsonPath('derived.net_income', fn ($v) => abs($v - (1000 - 50 - 250 - 250 - (700 * 0.0224) - (10 * 25))) < 0.01);

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
            'gross_sales' => 1000, 'cancelled' => 50, 'returns' => 250, 'delivered' => 700,
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
            'gross_sales' => 1000, 'cancelled' => 50, 'returns' => 250, 'delivered' => 700,
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

    /** Explicit request, 2026-09-28: "the net income it should be green if
     *  positive ... and if negative it should be red." */
    public function test_a_positive_net_income_is_rendered_green(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        ExpectedIncomeEntry::create(['product_id' => $product->id, 'entry_date' => today(), 'number_of_orders' => 10, 'average_order_value' => 100, 'gross_sales' => 1000]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee('text-green-600');
    }

    public function test_a_negative_net_income_is_rendered_red(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        ExpectedIncomeEntry::create([
            'product_id' => $product->id, 'entry_date' => today(),
            'number_of_orders' => 10, 'average_order_value' => 100, 'gross_sales' => 1000, 'advertising_cost' => 5000,
        ]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee('text-red-600');
    }

    /** Explicit request, 2026-09-30: "change the dates into per TSA and per
     *  team ... i want to add team filter." Confirmed live via screenshot,
     *  2026-09-30: the top range-summary row ("Telesales Expected
     *  Performance" + product cards) is UNCHANGED by the team filter — it
     *  keeps showing the exact same product-level total, tsa_id NULL,
     *  regardless of which team pill is selected. */
    /** Confirmed live via screenshot, 2026-09-30: "why in the top
     *  LUMIEYES/CLEAR SIGHT is not reflecting, it is per team" — the top
     *  summary card now pools EVERY tsa_id for a product (the product-level
     *  row, if any, plus every real TSA's own entry), not just the
     *  product-level row, so typing into any TSA's card actually moves the
     *  top total. The team FILTER SELECTION itself still doesn't change
     *  this row (always every TSA regardless of which pill is picked) —
     *  that part of the original design is unchanged, only the
     *  tsa_id-NULL-only pool was. */
    public function test_the_range_summary_row_pools_every_tsas_entry_regardless_of_team_filter(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $tsa = TsaShift::first();
        ExpectedIncomeEntry::create(['product_id' => $product->id, 'entry_date' => today(), 'gross_sales' => 1000]);
        ExpectedIncomeEntry::create(['product_id' => $product->id, 'tsa_id' => $tsa->id, 'entry_date' => today(), 'gross_sales' => 99999]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => TsaShift::first()->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare',
        ]));

        $response->assertOk();
        $response->assertSee('Telesales Expected Performance');
        $content = $response->getContent();
        // Only the summary scroller's own slice of the page.
        $summaryStart = strpos($content, 'id="eiSummaryScroller"');
        $dailySectionStart = strpos($content, today()->format('F j, Y'), $summaryStart);
        $summaryHtml = substr($content, $summaryStart, $dailySectionStart - $summaryStart);
        // 1,000 (product-level) + 99,999 (her own) = 100,999.00 combined.
        $this->assertStringContainsString('100,999.00', $summaryHtml);
    }

    /** The team filter PILL itself is still orthogonal to the top summary
     *  — switching teams must not change which TSAs' entries get pooled
     *  into it (always every TSA on every team, not just the selected
     *  one's). */
    public function test_the_range_summary_row_is_identical_no_matter_which_team_is_selected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $tsa = TsaShift::first();
        ExpectedIncomeEntry::create(['product_id' => $product->id, 'tsa_id' => $tsa->id, 'entry_date' => today(), 'gross_sales' => 42000]);

        $viewingHerOwnTeam = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';
        $viewingTheOtherTeam = $viewingHerOwnTeam === 'sh-naturals' ? 'eyecare' : 'sh-naturals';

        $extractSummaryHtml = function (string $content) {
            $summaryStart = strpos($content, 'id="eiSummaryScroller"');
            $dailySectionStart = strpos($content, today()->format('F j, Y'), $summaryStart);
            return substr($content, $summaryStart, $dailySectionStart - $summaryStart);
        };

        $responseA = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(), 'team' => $viewingHerOwnTeam,
        ]));
        $responseB = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(), 'team' => $viewingTheOtherTeam,
        ]));

        $summaryA = $extractSummaryHtml($responseA->getContent());
        $summaryB = $extractSummaryHtml($responseB->getContent());
        $this->assertStringContainsString('42,000.00', $summaryA);
        $this->assertSame($summaryA, $summaryB);
    }

    /** Confirmed live via screenshot, 2026-09-30: picking a real team
     *  swaps the daily "TELESALES — [date]" overall card for one block PER
     *  REAL TSA on that team, her own name as the card title, followed by
     *  her own product cards. */
    public function test_selecting_a_team_shows_one_block_per_real_tsa_named_by_her_own_name(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => $teamSlug,
        ]));

        $response->assertOk();
        $response->assertSee($tsa->display_name);
        $response->assertDontSee('TELESALES — ' . today()->format('F j, Y'));
    }

    /** Confirmed live via screenshot, 2026-09-30: "the yellow is stil has
     *  this, it is over all of the individual tsa" then "but it is not
     *  editable" — her own name card is a READ-ONLY rollup of her own
     *  product cards for that day (same role/shape as "TELESALES —
     *  [date]" in the ALL view), not a bare name label and not an
     *  editable-inputs card of its own. */
    public function test_the_tsa_overview_card_sums_her_own_products_and_is_read_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';
        $productA = Product::orderBy('id')->first();
        $productB = Product::orderBy('id')->skip(1)->first();
        $date = today()->toDateString();

        ExpectedIncomeEntry::create(['product_id' => $productA->id, 'tsa_id' => $tsa->id, 'entry_date' => $date, 'gross_sales' => 1000]);
        ExpectedIncomeEntry::create(['product_id' => $productB->id, 'tsa_id' => $tsa->id, 'entry_date' => $date, 'gross_sales' => 500]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $date, 'date_to' => $date, 'team' => $teamSlug,
        ]));

        $response->assertOk();
        $content = $response->getContent();
        // Her own name card appears BEFORE any real editable <input> (her
        // own first PRODUCT card starts right after it) — confirming her
        // own overview card renders the read-only _card-body branch, not
        // the editable one.
        $namePos = strpos($content, $tsa->display_name);
        $firstFieldPos = strpos($content, 'data-field=', $namePos);
        $this->assertNotFalse($firstFieldPos, 'expected an editable field somewhere after her own name');
        // 1,000 + 500 = 1,500 — her own combined total across both products.
        $overviewHtml = substr($content, $namePos, $firstFieldPos - $namePos);
        $this->assertStringContainsString('1,500.00', $overviewHtml);
        $this->assertStringNotContainsString('data-field=', $overviewHtml);
    }

    /** Her own numbers are completely independent of the "ALL" view's own
     *  product-level entries (tsa_id NULL) — editing her own card writes a
     *  real tsa_id row, never overwriting or reading the shared one. */
    public function test_a_tsas_own_entry_is_independent_of_the_product_level_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $tsa = TsaShift::first();
        $date = today()->toDateString();

        ExpectedIncomeEntry::create(['product_id' => $product->id, 'entry_date' => $date, 'gross_sales' => 1000]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 500]
        );

        $response->assertOk();
        $this->assertDatabaseHas('expected_income_entries', [
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'gross_sales' => 500,
        ]);
        // The product-level (tsa_id NULL) row is untouched.
        $this->assertDatabaseHas('expected_income_entries', [
            'product_id' => $product->id, 'tsa_id' => null, 'gross_sales' => 1000,
        ]);
    }

    /** Same "upsert, never a duplicate" convention as the product-level
     *  update() endpoint — saving a TSA's own field twice for the same
     *  product+day updates the one row, not two. */
    public function test_updating_a_tsas_entry_twice_does_not_create_a_duplicate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $tsa = TsaShift::first();
        $date = today()->toDateString();

        $route = route('data.expected-income.update-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => $date]);
        $this->actingAs($admin)->patchJson($route, ['gross_sales' => 100])->assertOk();
        $this->actingAs($admin)->patchJson($route, ['gross_sales' => 200])->assertOk();

        $this->assertSame(1, ExpectedIncomeEntry::where('product_id', $product->id)->where('tsa_id', $tsa->id)->count());
        $this->assertEquals(200, ExpectedIncomeEntry::where('product_id', $product->id)->where('tsa_id', $tsa->id)->first()->gross_sales);
    }

    /** A TSA's own custom-row value is saved through the tsa-scoped custom
     *  row endpoint, independent of the product-level custom value for the
     *  same key/product/day. */
    public function test_a_tsas_own_custom_row_value_is_independent_of_the_product_level_one(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $tsa = TsaShift::first();
        $date = today()->toDateString();

        \App\Models\ProjectionCustomRow::create([
            'key' => 'custom_warehouse_fee', 'section' => 'selling',
            'label' => 'Warehouse Fee', 'is_fixed' => false, 'sort_order' => 0,
        ]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-custom-row-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => $date]),
            ['key' => 'custom_warehouse_fee', 'value' => 250]
        );

        $response->assertOk();
        $this->assertDatabaseHas('expected_income_custom_values', [
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'custom_row_key' => 'custom_warehouse_fee', 'value' => 250.00,
        ]);
    }
}
