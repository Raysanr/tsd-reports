<?php

namespace Tests\Feature;

use App\Models\DsPprEntry;
use App\Models\Order;
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

    /** CSV-download + PNG-snapshot icons (explicit request, 2026-10-05)
     *  on the summary table AND every daily chunk table. */
    public function test_the_page_shows_export_icons_on_every_table(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.dsppr'));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('data-export-csv="dsPprSummaryTable"', $content);
        $this->assertMatchesRegularExpression('/data-export-csv="dsPprScroller-\d+"/', $content);
    }

    public function test_a_non_admin_cannot_view_the_report_page(): void
    {
        $tsaUser = User::factory()->create(['role' => 'normal']);

        $response = $this->actingAs($tsaUser)->get(route('data.dsppr'));

        $response->assertForbidden();
    }

    /** The daily-entry table's own PER-DATE lock (explicit follow-up,
     *  2026-10-07: "i want to make it per date like the lock icon is in
     *  the dates right side" — supersedes the same-day "one lock for the
     *  whole table" decision). Existence of a DsPprLockedDate row for a
     *  date means that date is locked; other dates stay independently
     *  editable. */
    public function test_locking_a_date_persists_and_is_reflected_on_reload(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();

        $response = $this->actingAs($admin)->patchJson(route('data.dsppr.toggle-lock', ['date' => $date]), ['locked' => true]);
        $response->assertOk()->assertJson(['success' => true, 'date' => $date, 'locked' => true]);
        $this->assertDatabaseHas('dsppr_locked_dates', ['entry_date' => $date . ' 00:00:00']);

        $page = $this->actingAs($admin)->get(route('data.dsppr', ['date_from' => $date, 'date_to' => $date]));
        $page->assertOk();
        $page->assertSee('data-dsppr-date-header data-date="' . $date . '" data-locked="1"', false);
    }

    public function test_unlocking_a_date_removes_its_locked_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();
        \App\Models\DsPprLockedDate::create(['entry_date' => $date]);

        $response = $this->actingAs($admin)->patchJson(route('data.dsppr.toggle-lock', ['date' => $date]), ['locked' => false]);
        $response->assertOk()->assertJson(['success' => true, 'date' => $date, 'locked' => false]);

        $this->assertDatabaseMissing('dsppr_locked_dates', ['entry_date' => $date . ' 00:00:00']);
    }

    public function test_a_non_admin_cannot_toggle_a_date_lock(): void
    {
        $user = User::factory()->create(['role' => 'normal']);

        $this->actingAs($user)->patchJson(route('data.dsppr.toggle-lock', ['date' => today()->toDateString()]), ['locked' => true])->assertForbidden();
    }

    public function test_a_locked_date_refuses_a_direct_update(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();
        $product = Product::first();
        \App\Models\DsPprLockedDate::create(['entry_date' => $date]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.dsppr.update', ['product' => $product->id, 'date' => $date]),
            ['gross_sales' => 999]
        );
        $response->assertStatus(422);
        $this->assertDatabaseMissing('dsppr_entries', ['product_id' => $product->id, 'gross_sales' => 999]);
    }

    public function test_a_locked_date_refuses_a_direct_tiktok_update(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();
        \App\Models\DsPprLockedDate::create(['entry_date' => $date]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.dsppr.update-tiktok', ['date' => $date]),
            ['gross_sales' => 999]
        );
        $response->assertStatus(422);
    }

    public function test_an_unlocked_date_is_unaffected_by_a_different_locked_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $lockedDate = today()->toDateString();
        $otherDate = today()->addDay()->toDateString();
        $product = Product::first();
        \App\Models\DsPprLockedDate::create(['entry_date' => $lockedDate]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.dsppr.update', ['product' => $product->id, 'date' => $otherDate]),
            ['gross_sales' => 777]
        );
        $response->assertOk();
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

    /** Total Orders/Total Leads/Catered Leads are no longer manual inputs
     *  (explicit request, 2026-10-01: "i want to make it automated based
     *  on the leads report page in TSD LEADS REPORT") — no editable
     *  <input> for any of the 3 anywhere on the page, even though Gross
     *  Sales/Net Income/Ads Spent stay editable. */
    /** Scoped to a real product's own row only — the TIKTOK ORDERS row
     *  added 2026-10-05 is an intentional exception (no real Order data
     *  backs it, so every field there IS editable, Total Orders/Total
     *  Leads/Catered Leads included — see DsPprReportController's own
     *  doc comment on updateTiktok()), so a page-wide "nowhere on the
     *  page" assertion would now be wrong. */
    public function test_total_orders_leads_and_catered_have_no_editable_input(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
        ]));

        $response->assertOk();
        preg_match('/<tr class="dsppr-row[^"]*"\s+data-row-key="p' . $product->id . '".*?<\/tr>/s', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'expected to find the real product\'s own daily row');
        $this->assertStringContainsString('data-field="gross_sales"', $matches[0]);
        $this->assertStringNotContainsString('data-field="total_orders"', $matches[0]);
        $this->assertStringNotContainsString('data-field="total_leads"', $matches[0]);
        $this->assertStringNotContainsString('data-field="catered_leads"', $matches[0]);
    }

    /** End-to-end: a real matched Order moves Total Leads/Catered Leads/
     *  Total Orders on BOTH the daily cell and the range summary row,
     *  confirming the whole chain (controller's day-loop → ProductPerformance
     *  ::dsPprRow() → view) is actually wired, not just the calculator
     *  formulas in isolation. */
    /** Total Orders = 'upsell_confirmation' specifically (explicit
     *  correction, 2026-10-01: "the orders is the upsell w confirmation")
     *  — a plain confirmed-call lead with NO upsell counts toward Total
     *  Leads/Catered Leads but NOT Total Orders; only a genuine upsell
     *  order does. */
    public function test_real_order_data_drives_total_orders_and_leads(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::where('display_name', 'SINUXYL')->where('team', 'SH Naturals')->firstOrFail();
        $date = today()->toDateString();

        Order::create([
            'pancake_order_id' => 'dsppr-test-1', 'team' => 'SH Naturals', 'raw_tags' => ['SINUXYL'],
            'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'pancake_inserted_at' => $date . ' 10:00:00',
            'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'dsppr-test-2', 'team' => 'SH Naturals', 'raw_tags' => ['SINUXYL'],
            'is_upsell' => true, 'amount' => 500.0, 'status_code' => 1,
            'pancake_created_at' => $date . ' 11:00:00', 'pancake_inserted_at' => $date . ' 11:00:00',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => $date, 'date_to' => $date,
        ]));

        $response->assertOk();
        $rows = $response->viewData('rows');
        $productRow = $rows->first(fn ($row) => $row['products']->first()->id === $product->id);

        // Only the genuine upsell order counts toward Total Orders.
        $this->assertEquals(1, $productRow['derived']['total_orders']);
        // Both leads count toward Total Leads/Catered Leads.
        $this->assertEquals(2, $productRow['derived']['total_leads']);
        $this->assertEquals(2, $productRow['derived']['catered_leads']);
    }

    /** Root-caused live, 2026-10-01: the daily chunk table's own bottom
     *  TOTAL row was merging the SAME pooled real-data row onto every
     *  individual product's own entry before summing them — with N real
     *  products on the page, Total Orders/Leads/rates all inflated by a
     *  factor of N (confirmed live: 4,172 Total Orders and 116% rates on
     *  a single real day with ~9 products, when the real underlying data
     *  was nowhere near that large). With 2 real products and ONE real
     *  matched order total across both, the TOTAL row must show exactly
     *  1, not 2 (or more, with every other seeded product in the catalog
     *  counted as a phantom "0 orders" row that still got the pooled
     *  figure merged in). */
    public function test_the_daily_total_row_does_not_inflate_total_orders_per_product(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::where('display_name', 'SINUXYL')->where('team', 'SH Naturals')->firstOrFail();
        $date = today()->toDateString();

        Order::create([
            'pancake_order_id' => 'dsppr-total-1', 'team' => 'SH Naturals', 'raw_tags' => ['SINUXYL'],
            'is_upsell' => true, 'amount' => 500.0, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'pancake_inserted_at' => $date . ' 10:00:00',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => $date, 'date_to' => $date,
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $totalRowStart = strpos($content, 'dsppr-day-total-row');
        $this->assertNotFalse($totalRowStart);
        $totalRowHtml = substr($content, $totalRowStart, 3000);

        $this->assertMatchesRegularExpression(
            '/data-out="total_orders"[^>]*>\s*1\s*</',
            $totalRowHtml,
            'Total Orders on the TOTAL row should be exactly 1 (one real matched order), not inflated by the product catalog size'
        );
        $this->assertStringNotContainsString('116.4', $totalRowHtml);
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

    /**
     * Root-caused 2026-09-27 (user report: "why is it the last date is
     * sept 30 but the last display is oct 1?"): daysUntil() is already
     * INCLUSIVE of its own end date — the controller's own
     * ->addDay() before calling it was based on the wrong assumption that
     * daysUntil() excludes the end date, so every selected range rendered
     * one extra day PAST the real "To" date (e.g. picking Sep 21-30 showed
     * a stray Oct 1 column no entry could ever be saved against from the
     * date picker itself).
     */
    public function test_the_daily_table_never_shows_a_day_past_the_selected_range(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => '2026-09-21',
            'date_to' => '2026-09-30',
        ]));

        $response->assertOk();
        $response->assertSee('Mon, Sep 21');
        $response->assertSee('Wed, Sep 30');
        $response->assertDontSee('Thu, Oct 1');
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

    /** A combined/merged row's own real-data figures pool every member's
     *  own matched orders into ONE tally — same "collab" idea as its
     *  Gross Sales (explicit request, 2026-10-01: "if there are combined/
     *  merged in the dsppr products it will collab like that"). A real
     *  order matched to only ONE of the two members still counts exactly
     *  once (not zero, not twice). */
    public function test_a_combined_rows_total_orders_pools_every_members_matched_orders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $sinuxyl = Product::where('display_name', 'SINUXYL')->where('team', 'SH Naturals')->firstOrFail();
        $otherProduct = Product::where('team', 'SH Naturals')->where('id', '!=', $sinuxyl->id)->firstOrFail();
        $date = today()->toDateString();

        Order::create([
            'pancake_order_id' => 'dsppr-group-1', 'team' => 'SH Naturals', 'raw_tags' => ['SINUXYL'],
            'is_upsell' => true, 'amount' => 500.0, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'pancake_inserted_at' => $date . ' 10:00:00',
            'synced_at' => now(),
        ]);

        $this->actingAs($admin)->postJson(route('data.product-groups.store'), [
            'label' => 'COMBO',
            'product_ids' => [$sinuxyl->id, $otherProduct->id],
        ])->assertOk();

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => $date, 'date_to' => $date,
        ]));

        $response->assertOk();
        $rows = $response->viewData('rows');
        $comboRow = $rows->first(fn ($row) => $row['label'] === 'COMBO');

        $this->assertEquals(1, $comboRow['derived']['total_orders']);
        $this->assertEquals(1, $comboRow['derived']['total_leads']);
    }

    /** Explicit follow-up, 2026-09-29: "make it editable because in the
     *  side of users the merged products is only 1 product only" — a
     *  grouped row is now editable, saving to the group's own FIRST member
     *  product ($row['products']->first(), same product the Blade's own
     *  data-product-id always uses). */
    public function test_updating_a_grouped_products_cell_saves_to_its_first_member(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $productA = Product::orderBy('id')->first();
        $productB = Product::orderBy('id')->skip(1)->first();
        $today = today()->toDateString();

        $this->actingAs($admin)->postJson(route('data.product-groups.store'), [
            'label' => 'TO',
            'product_ids' => [$productA->id, $productB->id],
        ])->assertOk();

        $response = $this->actingAs($admin)->patchJson(
            route('data.dsppr.update', ['product' => $productA->id, 'date' => $today]),
            ['gross_sales' => 2000, 'total_orders' => 2]
        );

        $response->assertOk();
        $this->assertDatabaseHas('dsppr_entries', [
            'product_id' => $productA->id,
            'gross_sales' => 2000,
        ]);
    }

    /** The group's own READ-ONLY cells (NI %, AOV, ...) must keep
     *  reflecting the FULL group total after an edit, not just the one
     *  member the edit landed on — the other member's own previously-saved
     *  numbers are still real and still part of what this row displays. */
    public function test_updating_a_grouped_products_cell_returns_the_full_group_total(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $productA = Product::orderBy('id')->first();
        $productB = Product::orderBy('id')->skip(1)->first();
        $today = today()->toDateString();

        // productB already has its own real, separately-saved data.
        DsPprEntry::create(['product_id' => $productB->id, 'entry_date' => $today, 'gross_sales' => 500, 'total_orders' => 1]);

        $this->actingAs($admin)->postJson(route('data.product-groups.store'), [
            'label' => 'TO',
            'product_ids' => [$productA->id, $productB->id],
        ])->assertOk();

        $response = $this->actingAs($admin)->patchJson(
            route('data.dsppr.update', ['product' => $productA->id, 'date' => $today]),
            ['gross_sales' => 1000, 'total_orders' => 1]
        );

        $response->assertOk();
        // 1,000 (just-edited productA) + 500 (productB's own untouched
        // data) = 1,500 — the group's TRUE combined total, not just
        // productA's own 1,000.
        $response->assertJsonPath('derived.gross_sales', fn ($v) => (float) $v === 1500.0);
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

    /** Superseded 2026-10-03 (explicit request: "in the dsppr too make it
     *  all black," confirmed "always black but if negative it is red") —
     *  the 2026-09-28 green-if-positive rule is gone; a positive Net
     *  Income now stays plain ink (black), same as every other number on
     *  this page, with no green tier at all (unlike Summary Sales
     *  Report's own 3-tier black/red/green rule, which this page
     *  deliberately does NOT get). Scoped to the specific Net Income
     *  cell via regex, not a bare assertSee(), since 'text-ink' appears
     *  elsewhere on the page regardless. */
    public function test_a_positive_net_income_stays_black_not_green(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        DsPprEntry::create([
            'product_id' => $product->id, 'entry_date' => today(),
            'gross_sales' => 3800, 'net_income' => 500,
        ]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
        ]));

        $response->assertOk();
        $this->assertStringNotContainsString('text-green-600', $response->getContent());
        $this->assertStringNotContainsString('text-green-400', $response->getContent());
        $this->assertMatchesRegularExpression(
            '/<input[^>]*data-field="net_income"[^>]*class="[^"]*text-ink[^"]*"/',
            $response->getContent(),
            'a positive Net Income input should render in plain ink color, not green'
        );
    }

    /** Same feature, the negative case — already-existing red-on-negative
     *  behavior, confirmed still correct alongside the new green case. */
    public function test_a_negative_net_income_is_rendered_red(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        DsPprEntry::create([
            'product_id' => $product->id, 'entry_date' => today(),
            'gross_sales' => 3800, 'net_income' => -500,
        ]);

        $response = $this->actingAs($admin)->get(route('data.dsppr'));

        $response->assertOk();
        $response->assertSee('text-red-600');
    }

    /** The EDITABLE Net Income <input> itself carries red/green coloring,
     *  not just the read-only derived spans elsewhere on the page
     *  (explicit request, 2026-10-01: "in the net income column i want to
     *  have like can input negative number and if negative is color red
     *  and if positive it is green" — the input previously always
     *  rendered in plain ink color regardless of its saved value, and
     *  silently stripped a leading '-' client-side if the user tried to
     *  type one). */
    public function test_the_net_income_input_itself_is_colored_by_its_saved_value(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        DsPprEntry::create([
            'product_id' => $product->id, 'entry_date' => today(),
            'gross_sales' => 3800, 'net_income' => -500,
        ]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<input[^>]*data-field="net_income"[^>]*class="[^"]*text-red-600[^"]*"/',
            $content
        );
    }

    /** TIKTOK ORDERS — explicit request, 2026-10-05: a manual-only row at
     *  the bottom of the product table, NOT a real Product (no Pancake
     *  matching backs it). Unlike a real product, every field here is
     *  editable, Total Orders/Total Leads/Catered Leads included. */
    public function test_the_tiktok_orders_row_appears_with_every_field_editable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        \App\Models\DsPprTiktokEntry::create([
            'entry_date' => today(), 'gross_sales' => 6000, 'net_income' => 500,
            'total_orders' => 5, 'total_leads' => 10, 'catered_leads' => 8,
        ]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('TIKTOK ORDERS', $content);

        preg_match('/<tr class="dsppr-row[^"]*"\s+data-row-key="tiktok".*?<\/tr>/s', $content, $matches);
        $this->assertNotEmpty($matches, 'expected to find the TIKTOK ORDERS daily row');
        // Excess Leads/Pick-up/Conversion/Upselling Rate (explicit
        // follow-up, 2026-10-07: "make it editable") joined the original
        // 5 as real inputs too — every one of the row's 9 non-derived
        // columns (ni_pct/aov stay derived-only, no raw column backs
        // either) is now a data-field input.
        foreach (['gross_sales', 'net_income', 'total_orders', 'total_leads', 'catered_leads', 'excess_leads', 'pickup_rate', 'conversion_rate', 'upselling_rate'] as $field) {
            $this->assertStringContainsString(
                "data-field=\"{$field}\"", $matches[0],
                "{$field} should be editable on the TIKTOK ORDERS row"
            );
        }
    }

    /** Excess Leads/Pick-up/Conversion/Upselling Rate overrides (explicit
     *  request, 2026-10-07: "make it editable") — these 4 are pure
     *  formulas everywhere else (DsPprCalculator::derive(), computed from
     *  Total Leads/Catered Leads/Total Orders), but TIKTOK ORDERS' own
     *  manual row gets a direct per-day override for each, saved to its
     *  own *_override column rather than replacing the formula outright —
     *  see DsPprTiktokEntry::toRawRowWithOverrides()'s own doc comment. */
    public function test_a_tiktok_rate_override_wins_over_the_formula(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();

        \App\Models\DsPprTiktokEntry::create([
            'entry_date' => $date, 'total_orders' => 5, 'total_leads' => 10, 'catered_leads' => 8,
        ]);

        // Un-overridden: Pick-up Rate = catered/total = 8/10 = 80%.
        $response = $this->actingAs($admin)->patchJson(
            route('data.dsppr.update-tiktok', ['date' => $date]),
            ['pickup_rate_override' => 0.5]
        );
        $response->assertOk();
        $response->assertJsonPath('derived.pickup_rate', fn ($v) => abs($v - 0.5) < 0.0001);

        $this->assertDatabaseHas('dsppr_tiktok_entries', ['pickup_rate_override' => 0.5]);
    }

    public function test_clearing_a_tiktok_rate_override_reverts_to_the_formula(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();

        \App\Models\DsPprTiktokEntry::create([
            'entry_date' => $date, 'total_orders' => 5, 'total_leads' => 10, 'catered_leads' => 8,
            'pickup_rate_override' => 0.5,
        ]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.dsppr.update-tiktok', ['date' => $date]),
            ['pickup_rate_override' => '']
        );
        $response->assertOk();
        // Formula: 8/10 = 80%, not the cleared 50% override.
        $response->assertJsonPath('derived.pickup_rate', fn ($v) => abs($v - 0.8) < 0.0001);

        $this->assertDatabaseHas('dsppr_tiktok_entries', ['pickup_rate_override' => null]);
    }

    public function test_an_excess_leads_override_is_a_count_not_a_percentage(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();

        \App\Models\DsPprTiktokEntry::create([
            'entry_date' => $date, 'total_leads' => 10, 'catered_leads' => 8,
        ]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.dsppr.update-tiktok', ['date' => $date]),
            ['excess_leads_override' => 99]
        );
        $response->assertOk();
        $response->assertJsonPath('derived.excess_leads', 99);
    }

    public function test_a_tiktok_rate_override_is_folded_into_the_overall_total(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();
        $product = \App\Models\Product::first();
        $product->update(['has_cost_allocation' => true]);

        \App\Models\DsPprTiktokEntry::create([
            'entry_date' => $date, 'total_orders' => 5, 'total_leads' => 10, 'catered_leads' => 8,
            'upselling_rate_override' => 1.0,
        ]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => $date, 'date_to' => $date,
        ]));
        $response->assertOk();
        // Not asserting an exact blended figure (depends on every other
        // seeded product's own rate too) — just that the page renders
        // without error and the override itself is visible as the saved
        // input value.
        $this->assertStringContainsString('value="100.00"', $response->getContent());
    }

    public function test_updating_a_tiktok_entry_upserts_every_manual_field(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();

        $response = $this->actingAs($admin)->patchJson(
            route('data.dsppr.update-tiktok', ['date' => $date]),
            ['gross_sales' => 6000, 'net_income' => 500, 'total_orders' => 5, 'total_leads' => 10, 'catered_leads' => 8]
        );

        $response->assertOk();
        $response->assertJsonPath('derived.ni_pct', fn ($v) => abs($v - (500 / 6000)) < 0.0001);
        $this->assertDatabaseHas('dsppr_tiktok_entries', [
            'gross_sales' => 6000, 'total_orders' => 5, 'total_leads' => 10, 'catered_leads' => 8,
        ]);
    }

    public function test_updating_an_existing_tiktok_entry_does_not_create_a_duplicate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();

        $this->actingAs($admin)->patchJson(route('data.dsppr.update-tiktok', ['date' => $date]), ['gross_sales' => 1000]);
        $this->actingAs($admin)->patchJson(route('data.dsppr.update-tiktok', ['date' => $date]), ['gross_sales' => 2000]);

        $this->assertSame(1, \App\Models\DsPprTiktokEntry::whereDate('entry_date', $date)->count());
        $this->assertSame(2000.0, \App\Models\DsPprTiktokEntry::whereDate('entry_date', $date)->first()->gross_sales);
    }

    /** TikTok Orders' own totals must fold into OVERALL TOTAL, same as
     *  every real product already does. */
    public function test_tiktok_orders_totals_are_included_in_the_overall_total(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        DsPprEntry::create(['product_id' => $product->id, 'entry_date' => today(), 'gross_sales' => 1000, 'net_income' => 100]);
        \App\Models\DsPprTiktokEntry::create(['entry_date' => today(), 'gross_sales' => 6000, 'net_income' => 500]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
        ]));
        $response->assertOk();

        preg_match('/<tr class="bg-black text-white font-bold dsppr-overall-total-row">.*?<\/tr>/s', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'expected to find the OVERALL TOTAL row');
        $this->assertMatchesRegularExpression('/data-out="gross_sales">\s*7,000\.00\s*</', $matches[0]);
    }

    /** TikTok Orders' own raw numbers must fold into each day's own
     *  bottom TOTAL row too, same as every real product's Gross Sales/Net
     *  Income/Total Orders/Total Leads/Catered Leads already do. */
    public function test_tiktok_orders_are_included_in_the_daily_total_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();
        \App\Models\DsPprTiktokEntry::create([
            'entry_date' => $date, 'gross_sales' => 6000, 'net_income' => 500,
            'total_orders' => 5, 'total_leads' => 10, 'catered_leads' => 8,
        ]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => $date, 'date_to' => $date,
        ]));
        $response->assertOk();

        preg_match('/<tr class="bg-black text-white font-bold dsppr-day-total-row">.*?<\/tr>/s', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'expected to find the daily TOTAL row');
        $this->assertMatchesRegularExpression('/data-out="gross_sales"[^>]*data-total-row="1">\s*6,000\.00\s*</', $matches[0]);
        $this->assertMatchesRegularExpression('/data-out="total_orders"[^>]*data-total-row="1">\s*5\s*</', $matches[0]);
    }
}
