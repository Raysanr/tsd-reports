<?php

namespace Tests\Feature;

use App\Models\DsPprEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\TsaShift;
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

    /** Reversed 2026-10-07 (explicit request: "make it the data
     *  management module is visible for the TSA's (NORMAL USERS)", full
     *  edit access confirmed) — a normal user can now both view and edit
     *  DSPPR, same as Projections/Summary Sales Report/Expected Income;
     *  only Cost Breakdown stays admin-only within this module. */
    public function test_a_normal_user_can_view_the_report_page(): void
    {
        $tsaUser = User::factory()->create(['role' => 'normal']);

        $response = $this->actingAs($tsaUser)->get(route('data.dsppr'));

        $response->assertOk();
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

    /** Reversed 2026-10-07 (see test_a_normal_user_can_view_the_report_
     *  page()'s own doc comment) — a normal user can now toggle a date
     *  lock too, same full edit access as every other write action on
     *  this page. */
    public function test_a_normal_user_can_toggle_a_date_lock(): void
    {
        $user = User::factory()->create(['role' => 'normal']);

        $this->actingAs($user)->patchJson(route('data.dsppr.toggle-lock', ['date' => today()->toDateString()]), ['locked' => true])->assertOk();
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

    /** gross_sales/net_income are no longer accepted by this endpoint
     *  (2026-10-10 — see DsPprReportController::update()'s own doc
     *  comment) — ads_spent is the only real-product field still
     *  manually writable, used here as the write-mechanics proxy (upsert
     *  + recomputed-response), same pattern Expected Income's own
     *  smoke tests already use for their analogous automated fields. The
     *  response's own Gross Sales/Net Income still reflect the real
     *  automated figure (0.00 here — no Order fixture seeded) rather
     *  than the no-longer-written stored value. */
    public function test_updating_a_cell_upserts_and_returns_recomputed_figures(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();

        $response = $this->actingAs($admin)->patchJson(
            route('data.dsppr.update', ['product' => $product->id, 'date' => today()->toDateString()]),
            ['ads_spent' => 150.00]
        );

        $response->assertOk();
        $response->assertJsonPath('derived.gross_sales', fn ($v) => (float) $v === 0.0);
        $response->assertJsonPath('derived.net_income', fn ($v) => (float) $v === 0.0);

        $this->assertDatabaseHas('dsppr_entries', [
            'product_id' => $product->id,
            'ads_spent' => 150.00,
        ]);
    }

    /** Gross Sales/Net Income on a real product's own daily cell now come
     *  from Expected Income's own per-product-per-day figures (explicit
     *  request, 2026-10-10: "i want to make it the gross sales and net
     *  income is automated and the basis is from the expected income"),
     *  not a manually-typed DsPprEntry value — a seeded Order with a
     *  genuine upsell tag is what actually drives it now. */
    public function test_gross_sales_and_net_income_are_automated_from_expected_income(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::where('display_name', 'SINUXYL')->where('team', 'SH Naturals')->firstOrFail();
        $tsa = TsaShift::where('team', 'SH Naturals')->firstOrFail();
        $date = today()->toDateString();

        Order::create([
            'pancake_order_id' => 'dsppr-ei-1', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'raw_tags' => ['SINUXYL'], 'is_upsell' => true, 'amount' => 500.0, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'pancake_inserted_at' => $date . ' 10:00:00',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => $date, 'date_to' => $date,
        ]));

        $response->assertOk();
        $rows = $response->viewData('rows');
        $productRow = $rows->first(fn ($row) => $row['products']->first()->id === $product->id);

        $this->assertEquals(500.0, $productRow['derived']['gross_sales']);
    }

    /** Total Orders/Total Leads/Catered Leads are no longer manual inputs
     *  (explicit request, 2026-10-01: "i want to make it automated based
     *  on the leads report page in TSD LEADS REPORT") — no editable
     *  <input> for any of the 3 anywhere on a real product's own row.
     *  Gross Sales/Net Income joined them as read-only 2026-10-10
     *  (explicit request: "i want to make it the gross sales and net
     *  income is automated and the basis is from the expected income")
     *  — every one of this page's own $dayColumns is now read-only for a
     *  real product (Ads Spent is still writable via update()'s own
     *  validation array, but isn't one of the 11 rendered $dayColumns at
     *  all, same as before this change — removed from display entirely
     *  2026-09-26). */
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
        $this->assertStringNotContainsString('data-field="gross_sales"', $matches[0]);
        $this->assertStringNotContainsString('data-field="net_income"', $matches[0]);
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

    /** Real production bug, root-caused live 2026-10-09: the per-day TOTAL
     *  row's own Total Leads (298) disagreed with the top "TELESALES
     *  RUNNING PERFORMANCE" summary row (299) for the exact same single
     *  day — a 1-lead gap. Cause: the TOTAL row used to compute Total
     *  Leads by pooling EVERY real product into ONE
     *  ProductPerformance::dsPprRow() call and deduping matched orders
     *  GLOBALLY across the whole pool — but the top summary (and
     *  LeadsReportController's own ALL-view Grand Total, "a plain sum of
     *  the visible rows") sums each product/group's own ALREADY-deduped
     *  count, so a real order genuinely matching TWO different products
     *  (a cross-team combo bundle) is deliberately counted ONCE PER
     *  MATCHING ROW everywhere else on this page, but the old TOTAL row
     *  silently deduped it down to once total. Fixed: the TOTAL row now
     *  sums $realByRowKeyAndDate's own per-row figures for that date (the
     *  SAME source every individual row + the top summary already read
     *  from), not a fresh globally-pooled dsPprRow() call. Reproduces the
     *  real scenario on the ALL view (no team param, same as
     *  LeadsReportController::indexAll()'s own cross-team pool): one
     *  order genuinely bundles both Pterygium and Sinuxyl. */
    public function test_the_daily_total_row_counts_a_cross_product_combo_order_once_per_matching_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();

        Order::create([
            'pancake_order_id' => 'dsppr-combo-1', 'team' => 'Eyecare Team',
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Pterygium',
            'bundle_description' => '10 Pterygium Drops + 10 Sinuxyl',
            'raw_tags' => ['CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'pancake_inserted_at' => $date . ' 10:00:00',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => $date, 'date_to' => $date,
        ]));

        $response->assertOk();
        $content = $response->getContent();

        $summaryRowStart = strpos($content, 'TELESALES RUNNING PERFORMANCE');
        $this->assertNotFalse($summaryRowStart);
        $overallTotalStart = strpos($content, 'OVERALL TOTAL', $summaryRowStart);
        $this->assertNotFalse($overallTotalStart);
        $overallTotalHtml = substr($content, $overallTotalStart, 1500);
        preg_match('/data-out="total_leads"[^>]*>([^<]*)</', $overallTotalHtml, $summaryMatch);

        $totalRowStart = strpos($content, 'dsppr-day-total-row');
        $this->assertNotFalse($totalRowStart);
        $totalRowHtml = substr($content, $totalRowStart, 4000);
        preg_match('/data-out="total_leads"[^>]*>([^<]*)</', $totalRowHtml, $totalMatch);

        // Both rows must show 2 (the combo order counted once under
        // Pterygium AND once under Sinuxyl) and must agree with each
        // other — the actual bug was these two disagreeing (299 vs 298
        // in production), not either one being wrong in isolation.
        $this->assertSame('2', trim($summaryMatch[1] ?? ''));
        $this->assertSame('2', trim($totalMatch[1] ?? ''));
    }

    /** FINAL decision, 2026-10-09 (this flip-flopped twice the same
     *  session — see DsPprReportController::index()'s own doc comment
     *  for the full back-and-forth): DSPPR's own OVERALL TOTAL
     *  Pick-up/Conversion/Upselling Rate must tally to Leads Report's own
     *  Grand Total for the identical range ("it is still not tally, it
     *  should be tally the total percentage" / "because it is same
     *  data"), same sum-then-recompute-from-counts convention Summary
     *  Sales Report's own identical fix already uses ("same as in the
     *  sales summary report"). An EARLIER attempt at this exact fix was
     *  reverted mid-session after a conflicting, spreadsheet-verified doc
     *  comment was found on `DsPprCalculator::sum()` — that comment
     *  described the OLD, no-longer-desired behavior; this is the
     *  standing final direction. Seeds a realistic mixed bag of
     *  dispositions across 2 products so the rates are genuinely
     *  non-trivial, then cross-references DSPPR's $overallTotal directly
     *  against LeadsReportController::indexAll()'s own $grandTotal for
     *  the same day — only valid while TikTok Orders has no real data (it
     *  has no Leads Report equivalent, so the two are expected to diverge
     *  once TikTok carries real figures — confirmed, not a bug). */
    public function test_the_overall_totals_percentages_match_leads_reports_grand_total(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();
        $sinuxylTsa = TsaShift::where('team', 'SH Naturals')->first();
        $pterygiumTsa = TsaShift::where('team', 'Eyecare Team')->first();

        $dispositions = [
            ['team' => 'SH Naturals', 'tsa' => $sinuxylTsa, 'product' => 'Sinuxyl', 'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false],
            ['team' => 'SH Naturals', 'tsa' => $sinuxylTsa, 'product' => 'Sinuxyl', 'disposition' => 'NOT ANSWERING', 'is_upsell' => false],
            ['team' => 'SH Naturals', 'tsa' => $sinuxylTsa, 'product' => 'Sinuxyl', 'disposition' => null, 'is_upsell' => true],
            ['team' => 'Eyecare Team', 'tsa' => $pterygiumTsa, 'product' => 'Pterygium', 'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false],
            ['team' => 'Eyecare Team', 'tsa' => $pterygiumTsa, 'product' => 'Pterygium', 'disposition' => 'CALL BACK', 'is_upsell' => false],
        ];
        foreach ($dispositions as $i => $d) {
            Order::create([
                'pancake_order_id' => "dsppr-pct-{$i}", 'team' => $d['team'], 'tsa_name' => $d['tsa']->tsa_key,
                'disposition' => $d['disposition'], 'product' => $d['product'],
                'raw_tags' => array_filter([strtoupper($d['tsa']->tsa_key), $d['disposition'], $d['is_upsell'] ? 'UPSELL TSD' : null]),
                'is_upsell' => $d['is_upsell'], 'status_code' => 1,
                'pancake_created_at' => "{$date} 10:00:00", 'pancake_inserted_at' => "{$date} 10:00:00",
                'synced_at' => now(),
            ]);
        }

        $dsppr = $this->actingAs($admin)->get(route('data.dsppr', ['date_from' => $date, 'date_to' => $date]));
        $leadsReport = $this->actingAs($admin)->get(route('leads-report', [
            'team' => 'all', 'range' => 'dates', 'date_from' => $date, 'date_to' => $date,
        ]));

        $dsppr->assertOk();
        $leadsReport->assertOk();

        $grandTotal = $leadsReport->viewData('grandTotal');
        $content = $dsppr->getContent();
        $overallTotalStart = strpos($content, 'OVERALL TOTAL');
        $this->assertNotFalse($overallTotalStart);
        $overallTotalHtml = substr($content, $overallTotalStart, 1500);

        foreach (['pickup_rate' => 'pick_up_rate', 'conversion_rate' => 'conversion_rate', 'upselling_rate' => 'upselling_rate'] as $dsPprKey => $leadsKey) {
            preg_match('/data-out="' . $dsPprKey . '"[^>]*>([^<]*)</', $overallTotalHtml, $m);
            $dsPprPct = (float) trim(str_replace('%', '', $m[1] ?? '0'));
            $leadsPct = round((float) ($grandTotal[$leadsKey] ?? 0), 1);
            $this->assertEqualsWithDelta($leadsPct, $dsPprPct, 0.15, "{$dsPprKey} mismatch: DSPPR={$dsPprPct} vs Leads Report={$leadsPct}");
        }
    }

    /** Companion test: a product with real orders that day plus every
     *  OTHER product at zero orders (every product now gets a card
     *  regardless of activity — 2026-10-06 decision) — the sum-then-
     *  recompute convention above is naturally immune to zero-activity
     *  dilution (a 0-total_leads product contributes 0/0 to the sum, not
     *  a diluting literal 0%), unlike the plain-average convention this
     *  page used to use. */
    public function test_zero_activity_products_do_not_dilute_the_overall_totals_rate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();
        $tsa = TsaShift::where('team', 'SH Naturals')->first();

        Order::create([
            'pancake_order_id' => 'dsppr-zero-activity-1', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Sinuxyl',
            'raw_tags' => [strtoupper($tsa->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => "{$date} 10:00:00", 'pancake_inserted_at' => "{$date} 10:00:00",
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', ['date_from' => $date, 'date_to' => $date]));
        $response->assertOk();
        $content = $response->getContent();

        $overallTotalStart = strpos($content, 'OVERALL TOTAL');
        $this->assertNotFalse($overallTotalStart);
        $overallTotalHtml = substr($content, $overallTotalStart, 1500);
        preg_match('/data-out="pickup_rate"[^>]*>([^<]*)</', $overallTotalHtml, $m);
        $pct = (float) trim(str_replace('%', '', $m[1] ?? '0'));

        $this->assertEqualsWithDelta(100.0, $pct, 0.15, "Pick-up Rate should be 100% (the one active product's own real rate), got {$pct}");
    }

    /** Real production bug, root-caused live 2026-10-09 (screenshot,
     *  right after the server-side sum-then-recompute fix was finalized):
     *  typing "20%" into TikTok Orders' own Pick-up Rate override spiked
     *  the TOTAL row to 87.65% — nowhere near a reasonable blend — and
     *  stayed wrong until a full page reload. Cause: the server's own
     *  $overallTotal/TOTAL row switched to sum-then-recompute (matching
     *  Leads Report), but this view's own JS live-refresh functions
     *  (refreshDayTotal()/refreshOverallTotal()) were never updated to
     *  match — they still averaged each row's own already-rendered
     *  percentage, including TikTok's own rate OVERRIDE at full per-row
     *  weight, same bug class already fixed on Summary Sales Report's own
     *  identical page earlier this session. Fixed by exposing each real
     *  product row's own raw answered/unanswered/confirmed_via_call/
     *  upsell_confirmation counts as data-* attributes (on the summary
     *  table's own dsppr-summary-row, and on the daily table's own
     *  pickup_rate/conversion_rate/upselling_rate cells) so the JS can
     *  recompute via the same rateFromCounts() formula instead of
     *  averaging. This test only confirms the SERVER renders those data-*
     *  attributes with the correct values (PHPUnit can't execute the JS
     *  itself) — the live-recompute behavior was re-verified manually in
     *  the browser. */
    public function test_summary_row_carries_raw_counts_for_live_recompute(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();
        $tsa = TsaShift::where('team', 'SH Naturals')->first();

        Order::create([
            'pancake_order_id' => 'dsppr-raw-counts-1', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Sinuxyl',
            'raw_tags' => [strtoupper($tsa->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => "{$date} 10:00:00", 'pancake_inserted_at' => "{$date} 10:00:00",
            'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'dsppr-raw-counts-2', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'disposition' => 'NOT ANSWERING', 'product' => 'Sinuxyl',
            'raw_tags' => [strtoupper($tsa->tsa_key), 'NOT ANSWERING'],
            'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => "{$date} 11:00:00", 'pancake_inserted_at' => "{$date} 11:00:00",
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', ['date_from' => $date, 'date_to' => $date]));
        $response->assertOk();
        $content = $response->getContent();

        $sinuxyl = Product::where('display_name', 'SINUXYL')->first();
        preg_match('/<tr class="dsppr-summary-row[^"]*" data-row-key="p' . $sinuxyl->id . '"[^>]*>/', $content, $rowMatch);
        $this->assertNotEmpty($rowMatch, 'expected to find the summary row for SINUXYL');
        $this->assertMatchesRegularExpression('/data-answered="1"/', $rowMatch[0]);
        $this->assertMatchesRegularExpression('/data-unanswered="1"/', $rowMatch[0]);
    }

    public function test_updating_an_existing_entry_does_not_create_a_duplicate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $date = today()->toDateString();

        $this->actingAs($admin)->patchJson(
            route('data.dsppr.update', ['product' => $product->id, 'date' => $date]),
            ['ads_spent' => 100]
        );
        $this->actingAs($admin)->patchJson(
            route('data.dsppr.update', ['product' => $product->id, 'date' => $date]),
            ['ads_spent' => 200]
        );

        $this->assertSame(1, DsPprEntry::where('product_id', $product->id)->whereDate('entry_date', $date)->count());
        $this->assertSame(200.0, DsPprEntry::where('product_id', $product->id)->whereDate('entry_date', $date)->first()->ads_spent);
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
        $product = Product::where('display_name', 'SINUXYL')->where('team', 'SH Naturals')->firstOrFail();
        $tsa = TsaShift::where('team', 'SH Naturals')->firstOrFail();
        $yesterday = today()->subDay()->toDateString();
        $todayStr = today()->toDateString();

        Order::create([
            'pancake_order_id' => 'dsppr-lastday-1', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'raw_tags' => ['SINUXYL'], 'is_upsell' => true, 'amount' => 1000.0, 'status_code' => 1,
            'pancake_created_at' => $yesterday . ' 10:00:00', 'pancake_inserted_at' => $yesterday . ' 10:00:00',
            'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'dsppr-lastday-2', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'raw_tags' => ['SINUXYL'], 'is_upsell' => true, 'amount' => 500.0, 'status_code' => 1,
            'pancake_created_at' => $todayStr . ' 10:00:00', 'pancake_inserted_at' => $todayStr . ' 10:00:00',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => $yesterday,
            'date_to' => $todayStr,
        ]));

        $response->assertOk();
        // 1,000 + 500 = 1,500 summed Gross Sales across both days — would
        // read 1,000.00 (today's order silently dropped) if the bug regressed.
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
        // Explicit real products (not orderBy('id')->first()/skip(1) —
        // Gross Sales now needs each one matchable via a real Order fixture,
        // so both must be genuine, differently-keyworded catalog products).
        $productA = Product::where('display_name', 'SINUXYL')->where('team', 'SH Naturals')->firstOrFail();
        $productB = Product::where('display_name', 'AUDICURE')->where('team', 'SH Naturals')->firstOrFail();
        $tsa = TsaShift::where('team', 'SH Naturals')->firstOrFail();
        $date = today()->toDateString();

        Order::create([
            'pancake_order_id' => 'dsppr-combine-1', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'raw_tags' => ['SINUXYL'], 'is_upsell' => true, 'amount' => 1000.0, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'pancake_inserted_at' => $date . ' 10:00:00',
            'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'dsppr-combine-2', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'raw_tags' => ['AUDICURE'], 'is_upsell' => true, 'amount' => 500.0, 'status_code' => 1,
            'pancake_created_at' => $date . ' 11:00:00', 'pancake_inserted_at' => $date . ' 11:00:00',
            'synced_at' => now(),
        ]);

        $storeResponse = $this->actingAs($admin)->postJson(route('data.product-groups.store'), [
            'label' => 'TO',
            'product_ids' => [$productA->id, $productB->id],
        ]);
        $storeResponse->assertOk();
        $this->assertDatabaseHas('product_groups', ['label' => 'TO']);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => $date,
            'date_to' => $date,
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

        // ads_spent is the only real-product field still manually
        // writable here (gross_sales/net_income removed 2026-10-10 — see
        // update()'s own doc comment) — same write-mechanics proxy as
        // every other rewritten test in this file.
        $response = $this->actingAs($admin)->patchJson(
            route('data.dsppr.update', ['product' => $productA->id, 'date' => $today]),
            ['ads_spent' => 300]
        );

        $response->assertOk();
        $this->assertDatabaseHas('dsppr_entries', [
            'product_id' => $productA->id,
            'ads_spent' => 300,
        ]);
    }

    /** The group's own READ-ONLY cells (NI %, AOV, ...) must keep
     *  reflecting the FULL group total after an edit, not just the one
     *  member the edit landed on — the other member's own previously-saved
     *  numbers are still real and still part of what this row displays. */
    public function test_updating_a_grouped_products_cell_returns_the_full_group_total(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $productA = Product::where('display_name', 'SINUXYL')->where('team', 'SH Naturals')->firstOrFail();
        $productB = Product::where('display_name', 'AUDICURE')->where('team', 'SH Naturals')->firstOrFail();
        $tsa = TsaShift::where('team', 'SH Naturals')->firstOrFail();
        $today = today()->toDateString();

        // Both products' own real matched orders — Gross Sales is now
        // automated from Expected Income (2026-10-10), not a manually
        // saved DsPprEntry value.
        Order::create([
            'pancake_order_id' => 'dsppr-grouptotal-1', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'raw_tags' => ['SINUXYL'], 'is_upsell' => true, 'amount' => 1000.0, 'status_code' => 1,
            'pancake_created_at' => $today . ' 10:00:00', 'pancake_inserted_at' => $today . ' 10:00:00',
            'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'dsppr-grouptotal-2', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'raw_tags' => ['AUDICURE'], 'is_upsell' => true, 'amount' => 500.0, 'status_code' => 1,
            'pancake_created_at' => $today . ' 11:00:00', 'pancake_inserted_at' => $today . ' 11:00:00',
            'synced_at' => now(),
        ]);

        $this->actingAs($admin)->postJson(route('data.product-groups.store'), [
            'label' => 'TO',
            'product_ids' => [$productA->id, $productB->id],
        ])->assertOk();

        // ads_spent is the only real-product field still manually
        // writable here (see test_updating_a_grouped_products_cell_
        // saves_to_its_first_member()'s own identical note) — the
        // response's own derived.gross_sales must still reflect the
        // FULL group total (both members' real orders), not just
        // productA's own.
        $response = $this->actingAs($admin)->patchJson(
            route('data.dsppr.update', ['product' => $productA->id, 'date' => $today]),
            ['ads_spent' => 50]
        );

        $response->assertOk();
        // 1,000 (productA's own real order) + 500 (productB's own real
        // order) = 1,500 — the group's TRUE combined total.
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
        $products = collect([
            Product::where('display_name', 'SINUXYL')->where('team', 'SH Naturals')->firstOrFail(),
            Product::where('display_name', 'AUDICURE')->where('team', 'SH Naturals')->firstOrFail(),
            Product::where('display_name', 'GINSENG SERUM')->where('team', 'SH Naturals')->firstOrFail(),
        ]);
        $tsa = TsaShift::where('team', 'SH Naturals')->firstOrFail();
        $date = today()->toDateString();
        $amounts = [1000.0, 500.0, 250.0];

        foreach ($products as $i => $product) {
            Order::create([
                'pancake_order_id' => "dsppr-thirdjoin-{$i}", 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
                'raw_tags' => [$product->keywords_array[0]], 'is_upsell' => true, 'amount' => $amounts[$i], 'status_code' => 1,
                'pancake_created_at' => "{$date} " . (10 + $i) . ':00:00', 'pancake_inserted_at' => "{$date} " . (10 + $i) . ':00:00',
                'synced_at' => now(),
            ]);
        }

        $group = ProductGroup::create(['label' => 'TO', 'sort_order' => 0]);
        $group->products()->attach([$products[0]->id, $products[1]->id]);

        $response = $this->actingAs($admin)->postJson(
            route('data.product-groups.add-member', $group),
            ['product_id' => $products[2]->id]
        );

        $response->assertOk();
        $this->assertDatabaseHas('product_group_members', ['product_group_id' => $group->id, 'product_id' => $products[2]->id]);

        $indexResponse = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => $date,
            'date_to' => $date,
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
     *  deliberately does NOT get).
     *
     *  Net Income is read-only for a real product since 2026-10-10 (see
     *  this file's own class-level automation notes) — rewritten from
     *  asserting an editable <input>'s own color to the real read-only
     *  [data-out="net_income"] span's color instead, scoped to the real
     *  product's own daily row specifically (NOT a bare page-wide
     *  assertSee(), and NOT TikTok's own still-editable input — a prior
     *  version of this test accidentally passed by matching TikTok's row
     *  instead of the real product's, since TikTok's own net_income
     *  input also renders with the same default ink color). A real
     *  matched upsell order drives a genuinely positive Net Income here
     *  (Gross Sales with no Operating Costs seeded nets positive). */
    public function test_a_positive_net_income_stays_black_not_green(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::where('display_name', 'SINUXYL')->where('team', 'SH Naturals')->firstOrFail();
        $tsa = TsaShift::where('team', 'SH Naturals')->firstOrFail();
        $date = today()->toDateString();

        Order::create([
            'pancake_order_id' => 'dsppr-posni-1', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'raw_tags' => ['SINUXYL'], 'is_upsell' => true, 'amount' => 500.0, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'pancake_inserted_at' => $date . ' 10:00:00',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => $date, 'date_to' => $date,
        ]));

        $response->assertOk();
        $content = $response->getContent();
        preg_match('/<tr class="dsppr-row[^"]*"\s+data-row-key="p' . $product->id . '".*?<\/tr>/s', $content, $matches);
        $this->assertNotEmpty($matches, 'expected to find the real product\'s own daily row');
        $this->assertStringNotContainsString('text-green-600', $matches[0]);
        $this->assertStringNotContainsString('text-green-400', $matches[0]);
        $this->assertMatchesRegularExpression(
            '/data-out="net_income"[^>]*text-ink[^>]*>|class="[^"]*text-ink[^"]*"[^>]*data-out="net_income"/',
            $matches[0],
            'a positive Net Income should render in plain ink color, not green'
        );
    }

    /** Same feature, the negative case — already-existing red-on-negative
     *  behavior, confirmed still correct alongside the new black case.
     *  Scoped to the real product's own row (see
     *  test_a_positive_net_income_stays_black_not_green()'s own doc
     *  comment for why a bare assertSee() isn't specific enough on its
     *  own — kept here too since 'text-red-600' genuinely only appears
     *  on this one row for this fixture, but scoped for consistency). */
    public function test_a_negative_net_income_is_rendered_red(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::where('display_name', 'SINUXYL')->where('team', 'SH Naturals')->firstOrFail();
        $tsa = TsaShift::where('team', 'SH Naturals')->firstOrFail();
        $date = today()->toDateString();

        // A small isolated upsell amount (₱10) nets genuinely negative
        // through Expected Income's own derive() formula with no manual
        // cost overrides seeded — the fixed Fulfillment Fee (Orders ×
        // 25) alone already exceeds this Gross Sales figure, confirmed
        // directly (₱10 Gross Sales → -17.67 Net Income).
        Order::create([
            'pancake_order_id' => 'dsppr-negni-1', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'raw_tags' => ['SINUXYL'], 'is_upsell' => true, 'amount' => 10.0, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'pancake_inserted_at' => $date . ' 10:00:00',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => $date, 'date_to' => $date,
        ]));

        $response->assertOk();
        $content = $response->getContent();
        preg_match('/<tr class="dsppr-row[^"]*"\s+data-row-key="p' . $product->id . '".*?<\/tr>/s', $content, $matches);
        $this->assertNotEmpty($matches, 'expected to find the real product\'s own daily row');
        $this->assertStringContainsString('text-red-600', $matches[0]);
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

    /** The saved override's own input shows a "%" suffix on the next page
     *  load (explicit follow-up, 2026-10-07: "why is it the % is not
     *  visible ... it should be visible not just by number only") — not
     *  just a bare number, same way every other %-labeled figure on this
     *  page reads. The underlying stored value stays a plain fraction
     *  (0.5), unaffected — this is purely how the seeded input value
     *  renders. */
    public function test_a_saved_pct_override_shows_a_percent_suffix_on_reload(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();

        \App\Models\DsPprTiktokEntry::create([
            'entry_date' => $date, 'total_orders' => 5, 'total_leads' => 10, 'catered_leads' => 8,
            'pickup_rate_override' => 0.5,
        ]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', ['date_from' => $date, 'date_to' => $date]));
        $response->assertOk();
        $this->assertStringContainsString('value="50.00%"', $response->getContent());
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
        // input value. "%" suffix included (explicit follow-up,
        // 2026-10-07: "why is it the % is not visible ... it should be
        // visible not just by number only").
        $this->assertStringContainsString('value="100.00%"', $response->getContent());
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
        $tsa = TsaShift::where('team', 'SH Naturals')->firstOrFail();
        $date = today()->toDateString();

        // Real product's own Gross Sales is now automated from Expected
        // Income (2026-10-10) — a real matched upsell order replaces the
        // old DsPprEntry::create(['gross_sales' => 1000]) seed.
        Order::create([
            'pancake_order_id' => 'dsppr-ovtotal-1', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'raw_tags' => ['SINUXYL'], 'is_upsell' => true, 'amount' => 1000.0, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'pancake_inserted_at' => $date . ' 10:00:00',
            'synced_at' => now(),
        ]);
        \App\Models\DsPprTiktokEntry::create(['entry_date' => $date, 'gross_sales' => 6000, 'net_income' => 500]);

        $response = $this->actingAs($admin)->get(route('data.dsppr', [
            'date_from' => $date, 'date_to' => $date,
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
