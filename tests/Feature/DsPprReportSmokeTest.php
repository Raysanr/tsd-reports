<?php

namespace Tests\Feature;

use App\Models\DsPprEntry;
use App\Models\Product;
use App\Models\ProductGroup;
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

    /** Explicit request, 2026-09-26: "drag the TO-01 to TO-02 ... it can
     *  have pop up like new name ... it is only combine." Combining two
     *  products replaces their own two rows with ONE row summing both. */
    public function test_combining_two_products_shows_one_summed_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $productA = Product::orderBy('id')->first();
        $productB = Product::orderBy('id')->skip(1)->first();

        DsPprEntry::create(['product_id' => $productA->id, 'entry_date' => today(), 'gross_sales' => 1000, 'total_orders' => 1]);
        DsPprEntry::create(['product_id' => $productB->id, 'entry_date' => today(), 'gross_sales' => 500, 'total_orders' => 1]);

        $storeResponse = $this->actingAs($admin)->postJson(route('data.product-groups.store'), [
            'label' => 'TO',
            'product_ids' => [$productA->id, $productB->id],
        ]);
        $storeResponse->assertOk();
        $this->assertDatabaseHas('product_groups', ['label' => 'TO']);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => today()->toDateString(),
            'date_to' => today()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertDontSee(strtoupper($productA->display_name));
        $response->assertDontSee(strtoupper($productB->display_name));
        $response->assertSee('TO');
        $response->assertSee('1,500.00'); // 1,000 + 500 summed into the one combined row.
    }

    public function test_a_product_cannot_join_two_groups(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $products = Product::orderBy('id')->limit(3)->get();

        $this->actingAs($admin)->postJson(route('data.product-groups.store'), [
            'label' => 'Group A',
            'product_ids' => [$products[0]->id, $products[1]->id],
        ])->assertOk();

        $response = $this->actingAs($admin)->postJson(route('data.product-groups.store'), [
            'label' => 'Group B',
            'product_ids' => [$products[1]->id, $products[2]->id],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('product_groups', ['label' => 'Group B']);
    }

    public function test_ungrouping_splits_products_back_into_their_own_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $productA = Product::orderBy('id')->first();
        $productB = Product::orderBy('id')->skip(1)->first();

        $group = ProductGroup::create(['label' => 'TO', 'sort_order' => 0]);
        $group->products()->attach([$productA->id, $productB->id]);

        $response = $this->actingAs($admin)->deleteJson(route('data.product-groups.destroy', $group));

        $response->assertOk();
        $this->assertDatabaseMissing('product_groups', ['id' => $group->id]);

        $indexResponse = $this->actingAs($admin)->get(route('data.dsppr'));
        $indexResponse->assertSee(strtoupper($productA->display_name));
        $indexResponse->assertSee(strtoupper($productB->display_name));
    }

    /** Explicit follow-up, 2026-09-26: "what about more than 2 combine" —
     *  dragging a 3rd product onto an ALREADY-combined row joins that same
     *  group (no new name needed) rather than being a dead end. */
    public function test_a_third_product_can_join_an_existing_group(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $products = Product::orderBy('id')->limit(3)->get();

        DsPprEntry::create(['product_id' => $products[0]->id, 'entry_date' => today(), 'gross_sales' => 1000, 'total_orders' => 1]);
        DsPprEntry::create(['product_id' => $products[1]->id, 'entry_date' => today(), 'gross_sales' => 500, 'total_orders' => 1]);
        DsPprEntry::create(['product_id' => $products[2]->id, 'entry_date' => today(), 'gross_sales' => 250, 'total_orders' => 1]);

        $group = ProductGroup::create(['label' => 'TO', 'sort_order' => 0]);
        $group->products()->attach([$products[0]->id, $products[1]->id]);

        $response = $this->actingAs($admin)->postJson(
            route('data.product-groups.add-member', $group),
            ['product_id' => $products[2]->id]
        );

        $response->assertOk();
        $this->assertDatabaseHas('product_group_members', ['product_group_id' => $group->id, 'product_id' => $products[2]->id]);

        $indexResponse = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => today()->toDateString(),
            'date_to' => today()->toDateString(),
        ]));
        $indexResponse->assertOk();
        $indexResponse->assertDontSee(strtoupper($products[2]->display_name));
        // 1,000 + 500 + 250 = 1,750, now all three summed into the one row.
        $indexResponse->assertSee('1,750.00');
    }

    public function test_a_product_already_in_a_group_cannot_join_another(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $products = Product::orderBy('id')->limit(3)->get();

        $groupA = ProductGroup::create(['label' => 'A', 'sort_order' => 0]);
        $groupA->products()->attach([$products[0]->id, $products[1]->id]);
        $groupB = ProductGroup::create(['label' => 'B', 'sort_order' => 1]);
        $groupB->products()->attach($products[2]->id);

        $response = $this->actingAs($admin)->postJson(
            route('data.product-groups.add-member', $groupB),
            ['product_id' => $products[0]->id]
        );

        $response->assertStatus(422);
        $this->assertDatabaseMissing('product_group_members', ['product_group_id' => $groupB->id, 'product_id' => $products[0]->id]);
    }
}
