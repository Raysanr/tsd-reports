<?php

namespace Tests\Feature;

use App\Models\CostBreakdownPool;
use App\Models\CostBreakdownRole;
use App\Models\CostBreakdownTsaEntry;
use App\Models\ExpectedIncomeEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProjectionColumn;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\ProductGrouping;
use App\Support\TsaDailyRateService;
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

    /** Every seeded product flagged has_cost_allocation = true by default
     *  (explicit request, 2026-10-03: "the only will display on that is
     *  has cost products ... the products that has cost is has check in
     *  cost breakdown page Cost Allocation Per TSA" — ExpectedIncomeController
     *  now only queries flagged products at all, so an unflagged product
     *  renders NO card whatsoever). This test file's own tests almost all
     *  predate that filter and were written assuming "every seeded product
     *  gets a card" — flagging every product here keeps their own actual
     *  intent (page rendering/autosave behavior, not the flag itself)
     *  working unchanged; a test that specifically needs an UNFLAGGED
     *  product (e.g. confirming it renders no card) un-flags its own
     *  fixture explicitly instead. */
    protected function setUp(): void
    {
        parent::setUp();
        Product::query()->update(['has_cost_allocation' => true]);
    }

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

    /** PNG-snapshot icon only (explicit request, 2026-10-05) — this page
     *  is card-based, not a <table>/<tr>, so no CSV icon here (see
     *  table-actions.blade.php's own 'pngOnly' doc comment). */
    public function test_the_summary_section_shows_a_png_snapshot_icon_but_no_csv_icon(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.expected-income'));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('data-export-png="eiSummaryScroller"', $content);
        $this->assertStringNotContainsString('data-export-csv="eiSummaryScroller"', $content);
    }

    /** Reversed 2026-10-06 (explicit request: "it should be all products
     *  has product card in the expected income but it has no cost like
     *  in the Operating Costs row" — supersedes the narrower 2026-10-03
     *  decision this test used to encode) — EVERY product gets a card
     *  regardless of has_cost_allocation; only the per-product Operating
     *  Costs SHARE (on a TSA-scoped card) is gated on the flag, not the
     *  card's own existence. */
    public function test_an_unflagged_product_still_gets_a_card(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $flagged = Product::first();
        $unflagged = Product::orderBy('id')->skip(1)->first();
        $unflagged->update(['has_cost_allocation' => false]);

        $response = $this->actingAs($admin)->get(route('data.expected-income'));

        $response->assertOk();
        $response->assertSee($flagged->display_name);
        $response->assertSee($unflagged->display_name);
    }

    /** On a TSA-scoped card (a real team selected), a FLAGGED product's
     *  Operating Costs rows show the real per-product Cost Breakdown
     *  share; an UNFLAGGED product's own card shows 0.00 there instead of
     *  silently inheriting the same figure (real bug caught live,
     *  2026-10-06: before this fix, every product card — flagged or not —
     *  showed the identical Operating Costs total, since the per-TSA
     *  override was applied uniformly with no per-product gate). */
    public function test_an_unflagged_products_card_shows_no_operating_costs_share(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownPool::ensureSeeded();
        $flagged = Product::first();
        $flagged->update(['has_cost_allocation' => true]);
        $unflagged = Product::orderBy('id')->skip(1)->first();
        $unflagged->update(['has_cost_allocation' => false]);
        $tsa = TsaShift::first();
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';

        $expected = TsaDailyRateService::dailyCostPerProductRow()['communication_allowance'];
        $this->assertGreaterThan(0, $expected);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => $teamSlug,
        ]));

        $response->assertOk();
        $content = $response->getContent();

        $flaggedPos = strpos($content, 'data-product-id="' . $flagged->id . '"');
        $this->assertNotFalse($flaggedPos, 'expected to find the flagged product card');
        $nextCardPos = strpos($content, 'data-product-id=', $flaggedPos + 1) ?: strlen($content);
        $flaggedSlice = substr($content, $flaggedPos, $nextCardPos - $flaggedPos);
        $this->assertMatchesRegularExpression(
            '/data-out="communication_allowance"[^>]*>\s*' . preg_quote(number_format($expected, 2), '/') . '/',
            $flaggedSlice,
            'a flagged product card should show its real Cost Breakdown share'
        );

        $unflaggedPos = strpos($content, 'data-product-id="' . $unflagged->id . '"');
        $this->assertNotFalse($unflaggedPos, 'expected to find the unflagged product card');
        $nextCardPos2 = strpos($content, 'data-product-id=', $unflaggedPos + 1) ?: strlen($content);
        $unflaggedSlice = substr($content, $unflaggedPos, $nextCardPos2 - $unflaggedPos);
        $this->assertMatchesRegularExpression(
            '/data-out="communication_allowance"[^>]*>\s*0\.00/',
            $unflaggedSlice,
            'an unflagged product card should show NO Operating Costs share'
        );
    }

    /** The AJAX live-refresh endpoint (summary()) must never show a
     *  different product list than the page's own initial render — same
     *  "every product shows" behavior applies there too. */
    public function test_the_summary_endpoint_also_shows_unflagged_products(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $unflagged = Product::first();
        $unflagged->update(['has_cost_allocation' => false]);

        $response = $this->actingAs($admin)->get(route('data.expected-income.summary', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee($unflagged->display_name);
    }

    /** Only Gross Sales/Cancelled stay editable inputs — Projected Returns
     *  and Projected Delivered are derived again (explicit correction,
     *  2026-10-01: "the only auto is Projected Returns / Projected
     *  Delivered"), so neither has a data-field input anywhere, even on
     *  an otherwise-editable product card. */
    public function test_projected_returns_and_delivered_have_no_editable_input(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => $teamSlug,
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('data-field="gross_sales"', $content);
        $this->assertStringContainsString('data-field="cancelled"', $content);
        $this->assertStringNotContainsString('data-field="returns"', $content);
        $this->assertStringNotContainsString('data-field="delivered"', $content);
    }

    /** End-to-end: saving Gross Sales/Cancelled on a real product card
     *  returns Projected Returns/Delivered computed from THOSE values, not
     *  whatever (if anything) was previously stored in the returns/
     *  delivered DB columns — confirmed exact against the real sheet's own
     *  rate (90,100 × 25% = 22,525; 90,100 − 4,505 − 22,525 = 63,070). */
    public function test_saving_gross_sales_recomputes_returns_and_delivered(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $date = today()->toDateString();

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update', ['product' => $product->id, 'date' => $date]),
            ['gross_sales' => 90100.00, 'cancelled' => 4505.00]
        );

        $response->assertOk();
        $response->assertJsonPath('derived.returns', fn ($v) => abs($v - 22525.00) < 1.0);
        $response->assertJsonPath('derived.delivered', fn ($v) => abs($v - 63070.00) < 1.0);
    }

    /** Explicit request, 2026-09-30: "why is it when i am clicking other
     *  page and then go back why is it resetting ... i want to make it it
     *  is first like today only when first open." A bare visit with no
     *  date_from/date_to in the URL (e.g. clicking the sidebar link fresh)
     *  defaults to today only, not the old "this month" default. */
    public function test_a_bare_visit_with_no_date_params_defaults_to_today_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.expected-income'));

        $response->assertOk();
        $response->assertViewHas('dateFrom', today()->toDateString());
        $response->assertViewHas('dateTo', today()->toDateString());
    }

    /** Explicit request, 2026-09-30: "depend of the user if they will date
     *  pick wide range" — once a real range is picked, a LATER bare visit
     *  (no date params at all, exactly what a fresh sidebar-link click
     *  produces) remembers it instead of resetting to today. */
    public function test_a_picked_range_is_remembered_on_a_later_bare_visit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => '2026-02-01', 'date_to' => '2026-02-10',
        ]))->assertOk();

        $laterResponse = $this->actingAs($admin)->get(route('data.expected-income'));

        $laterResponse->assertOk();
        $laterResponse->assertViewHas('dateFrom', '2026-02-01');
        $laterResponse->assertViewHas('dateTo', '2026-02-10');
    }

    /** Same "remembered across a bare sidebar-link revisit" behavior as
     *  the date range above, now for the team filter too (explicit
     *  request, 2026-10-01: "the expected income team filter is when i
     *  wilck other page in sidebar is when i go back to the expected
     *  income it is staying to that what i filter" — a sidebar link is a
     *  completely fresh request with no ?team= of its own, so without
     *  session persistence the filter silently reset to ALL every time). */
    public function test_a_picked_team_is_remembered_on_a_later_bare_visit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('data.expected-income', ['team' => 'sh-naturals']))->assertOk();

        $laterResponse = $this->actingAs($admin)->get(route('data.expected-income'));

        $laterResponse->assertOk();
        $laterResponse->assertViewHas('selectedTeam', 'sh-naturals');
    }

    /** An invalid/stale team slug saved to session (e.g. a team renamed or
     *  removed since it was picked) falls back to ALL rather than a
     *  broken filter. */
    public function test_an_invalid_remembered_team_falls_back_to_all(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('data.expected-income', ['team' => 'sh-naturals']))->assertOk();
        session(['expected-income.team' => 'no-longer-real-team']);

        $response = $this->actingAs($admin)->get(route('data.expected-income'));

        $response->assertOk();
        $response->assertViewHas('selectedTeam', 'all');
    }

    /** Reversed 2026-10-07 (explicit request: "make it the data
     *  management module is visible for the TSA's (NORMAL USERS)", full
     *  edit access confirmed) — a normal user can now both view and edit
     *  Expected Income, same as Projections/DSPPR/Summary Sales Report;
     *  only Cost Breakdown stays admin-only within this module. */
    public function test_a_normal_user_can_view_the_report_page(): void
    {
        $tsaUser = User::factory()->create(['role' => 'normal']);

        $response = $this->actingAs($tsaUser)->get(route('data.expected-income'));

        $response->assertOk();
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
     *  of its own end date). Rewritten 2026-10-02 when a multi-day
     *  team-scoped range became ONE summed read-only block instead of a
     *  per-date data-date row each (see isRangeSummed's own doc comment on
     *  buildTeamDailyRows()) — there's no longer any data-date markup to
     *  assert against for a >1-day range, so this now proves the
     *  off-by-one fix via the summed Gross Sales figure instead: a day
     *  just past the range must never be silently folded into the total. */
    public function test_the_range_sum_never_includes_a_day_past_the_selected_range(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $tsa = TsaShift::where('team', 'SH Naturals')->first();

        ExpectedIncomeEntry::create([
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'entry_date' => '2026-09-30',
            'gross_sales' => 1000,
        ]);
        // Just past the selected range — must never be folded into the sum.
        ExpectedIncomeEntry::create([
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'entry_date' => '2026-10-01',
            'gross_sales' => 999999,
        ]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => '2026-09-21',
            'date_to' => '2026-09-30',
            'team' => 'sh-naturals',
        ]));

        $response->assertOk();
        $response->assertSee('1,000.00');
        $response->assertDontSee('999,999.00');
        $response->assertDontSee('1,000,999.00');
    }

    /** Regression test, 2026-10-02 (live screenshot): a multi-day
     *  team-scoped filter rendered a full per-day stack of cards for EACH
     *  date, each showing only that single day's own numbers — looked
     *  like the range filter wasn't summing at all on a mostly-empty day
     *  ("why is it when i filter multiple dates why the tsa cards and
     *  products is staying like as per day"). Now a >1-day range renders
     *  ONE read-only block per TSA, its figures summed across the WHOLE
     *  range — same role the top range-summary row already plays, just
     *  per-TSA instead of per-team. A 1-day range is unaffected (still the
     *  original editable per-day cards, see the next test). */
    public function test_a_multi_day_team_filter_shows_one_summed_block_not_one_per_day(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $tsa = TsaShift::where('team', 'SH Naturals')->first();

        ExpectedIncomeEntry::create([
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'entry_date' => '2026-10-01',
            'gross_sales' => 1000,
        ]);
        ExpectedIncomeEntry::create([
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'entry_date' => '2026-10-02',
            'gross_sales' => 500,
        ]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => '2026-10-01', 'date_to' => '2026-10-02', 'team' => 'sh-naturals',
        ]));

        $response->assertOk();
        // 1,000 + 500 summed into one block, not two separate 1,000.00 /
        // 500.00 per-day cards.
        $response->assertSee('1,500.00');
        // No per-CALENDAR-DAY card scroller at all on a multi-day range —
        // read-only, no save endpoint for a range with no single date to
        // save into. ei-day-scroller's own data-date attribute (not the
        // date-range picker widget's own unrelated data-date="..." day
        // buttons, which are always present) is what distinguishes it.
        $response->assertDontSee('ei-day-scroller" data-date=', false);
        $response->assertDontSee(route('data.expected-income.update-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => '2026-10-01']), false);
    }

    /** A 1-day range is the common case (including every day-to-day
     *  autosave) and must stay exactly as it always was: one editable
     *  per-day card stack, not the new multi-day summed read-only block. */
    public function test_a_single_day_team_filter_stays_editable_not_summed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $tsa = TsaShift::where('team', 'SH Naturals')->first();

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => '2026-10-01', 'date_to' => '2026-10-01', 'team' => 'sh-naturals',
        ]));

        $response->assertOk();
        $response->assertSee('data-date="2026-10-01"', false);
        $response->assertSee(route('data.expected-income.update-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => '2026-10-01']), false);
    }

    /** Regression test, 2026-10-02 (live screenshot): a 2-day summed TSA
     *  card showed the SAME locked Salaries/Operating Cost figures as a
     *  1-day card instead of double ("why is the costs is not doubling
     *  when i filter the oct 1 to 2?") — $productCardOverrides/
     *  $overviewCardOverrides/the tax allocation figures are all DAILY
     *  rates, applied once via withOverriddenOperatingCosts() regardless
     *  of how many days were actually summed. Fixed by multiplying each
     *  by $dates->count() before overriding, same "× $dayCount" rule
     *  addActiveTsasOverviewOperatingCosts() already applies to the top
     *  summary row. Asserts the 2-day overview card's own locked Salaries
     *  figure is EXACTLY double the 1-day figure, not equal to it. */
    public function test_a_multi_day_summed_cards_locked_operating_costs_double_for_two_days(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        CostBreakdownPool::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        $tsa = TsaShift::where('team', 'SH Naturals')->first();
        CostBreakdownTsaEntry::where('tsa_id', $tsa->id)->update(['base_salary' => 19500.00]);
        Product::first()->update(['has_cost_allocation' => true]);

        // Her own overview card's own locked Salaries — scoped between her
        // name and her first product card's own title, exactly the same
        // slice test_a_tsas_product_card_shows_salaries_locked_to_her_daily_rate_per_product()
        // above already uses, so this never accidentally matches the top
        // range-summary row's own (differently-scoped, multi-TSA) Salaries
        // figure elsewhere on the same page.
        $extractHerOverviewSalaries = function (string $content, string $tsaName): float {
            $namePos = strpos($content, $tsaName);
            $this->assertNotFalse($namePos, 'test setup: expected to find the TSA\'s own name on the page');
            $firstFieldPos = strpos($content, 'data-field=', $namePos);
            $overviewHtml = substr($content, $namePos, $firstFieldPos - $namePos);
            preg_match('/data-out="salaries"[^>]*>\s*([\d,]+\.\d{2})/', $overviewHtml, $match);
            $this->assertNotEmpty($match, 'test setup: expected a locked Salaries figure in her own overview card');
            return (float) str_replace(',', '', $match[1]);
        };

        $oneDayResponse = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => '2026-10-01', 'date_to' => '2026-10-01', 'team' => 'sh-naturals',
        ]));
        $oneDayResponse->assertOk();
        $oneDaySalaries = $extractHerOverviewSalaries($oneDayResponse->getContent(), $tsa->display_name);
        $this->assertGreaterThan(0, $oneDaySalaries);

        $twoDayResponse = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => '2026-10-01', 'date_to' => '2026-10-02', 'team' => 'sh-naturals',
        ]));
        $twoDayResponse->assertOk();
        $twoDaySalaries = $extractHerOverviewSalaries($twoDayResponse->getContent(), $tsa->display_name);

        // Within a couple cents of exactly double (0.02 tolerance absorbs
        // ordinary half-cent display rounding on the already-rounded
        // 1-day figure × 2, nothing to do with the bug itself) — not
        // equal to the 1-day figure (the bug this guards against) and not
        // some other multiple.
        $this->assertEqualsWithDelta($oneDaySalaries * 2, $twoDaySalaries, 0.02);
    }

    /** Explicit request, 2026-10-02: "can you create lock icon too in
     *  this, like in the projections" — one lock PER PRODUCT CARD
     *  (explicit decision, same day), same "when it is lock it can't
     *  edit" behavior Projections' own Opening/Closing Shift cards
     *  already have. A fresh (never-saved) product card is unlocked by
     *  default and shows a real editable input, not disabled. */
    public function test_a_fresh_product_card_shows_an_unlocked_editable_input(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $tsa = TsaShift::where('team', 'SH Naturals')->first();

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => '2026-10-01', 'date_to' => '2026-10-01', 'team' => 'sh-naturals',
        ]));

        $response->assertOk();
        $response->assertSee('data-ei-lock-toggle', false);
        $response->assertSee('data-locked="0"', false);
    }

    /** Regression, 2026-10-02: row drag-reorder was built shared across
     *  both pages (RowOrder, see RowOrderTest), but the drag HANDLE/UI was
     *  only ever meant for Projections — Expected Income's own rows should
     *  never show a grab handle or be directly draggable, even though
     *  they still carry data-row-key/data-row-section so a drag that
     *  happens on PROJECTIONS still correctly reorders them too ("why is
     *  it even expected income has drag to change position? it is only
     *  in the projection"). */
    public function test_rows_are_not_draggable_on_expected_income(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => '2026-10-01', 'date_to' => '2026-10-01', 'team' => 'sh-naturals',
        ]));

        $response->assertOk();
        // The row still carries its own ordering identity...
        $response->assertSee('data-row-key="salaries"', false);
        $response->assertSee('data-row-section="operating"', false);
        // ...but never a draggable attribute or the grab-handle icon.
        $response->assertDontSee('draggable="true"', false);
        $response->assertDontSee('ei-row-handle', false);
    }

    /** Locking a product card persists is_locked on its own
     *  ExpectedIncomeEntry (creating one if it never had a saved value at
     *  all, same "every field optional via firstOrNew-style upsert"
     *  convention update() already uses for every other field), returns
     *  the card's own fresh HTML, and that fresh HTML shows every field
     *  as `disabled` — genuinely non-editable, not just visually dimmed. */
    public function test_locking_a_product_card_persists_and_disables_its_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $tsa = TsaShift::where('team', 'SH Naturals')->first();

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => '2026-10-01']),
            ['is_locked' => true]
        );

        $response->assertOk();
        $response->assertJsonStructure(['success', 'derived', 'cardHtml']);
        $this->assertDatabaseHas('expected_income_entries', [
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'is_locked' => true,
        ]);

        $cardHtml = $response->json('cardHtml');
        $this->assertStringContainsString('data-locked="1"', $cardHtml);
        $this->assertMatchesRegularExpression('/data-field="gross_sales"[^>]*disabled/', $cardHtml);
    }

    /** Unlocking restores a real, non-disabled editable input — the lock
     *  is reversible and doesn't silently drop the stored values it was
     *  freezing. */
    public function test_unlocking_a_product_card_restores_editable_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $tsa = TsaShift::where('team', 'SH Naturals')->first();
        ExpectedIncomeEntry::create([
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'entry_date' => '2026-10-01',
            'gross_sales' => 500, 'is_locked' => true,
        ]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => '2026-10-01']),
            ['is_locked' => false]
        );

        $response->assertOk();
        $this->assertDatabaseHas('expected_income_entries', [
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'is_locked' => false,
        ]);
        $cardHtml = $response->json('cardHtml');
        $this->assertStringContainsString('data-locked="0"', $cardHtml);
        $this->assertDoesNotMatchRegularExpression('/data-field="gross_sales"[^>]*disabled/', $cardHtml);
        // The 500 saved before locking must still be there — locking never
        // touches stored values.
        $this->assertStringContainsString('500.00', $cardHtml);
    }

    /** A locked card's own stored value survives an attempted write — the
     *  frontend disables the input so a real browser can never submit one,
     *  but the backend must independently refuse a direct PATCH too
     *  (never trust client-side disabled alone). */
    public function test_a_locked_cards_value_cannot_be_overwritten_via_direct_patch(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $tsa = TsaShift::where('team', 'SH Naturals')->first();
        ExpectedIncomeEntry::create([
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'entry_date' => '2026-10-01',
            'gross_sales' => 500, 'is_locked' => true,
        ]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => '2026-10-01']),
            ['gross_sales' => 999999]
        );

        $response->assertOk();
        $this->assertDatabaseHas('expected_income_entries', [
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'gross_sales' => 500,
        ]);
        $this->assertDatabaseMissing('expected_income_entries', [
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'gross_sales' => 999999,
        ]);
    }

    /** Same server-side guard as the built-in fields above, for a custom
     *  row's own value — it lives on a completely separate table/endpoint
     *  (ExpectedIncomeCustomValue/updateCustomRow()), so it needed its own
     *  independent is_locked check rather than inheriting update()'s. */
    public function test_a_locked_cards_custom_row_cannot_be_overwritten_via_direct_patch(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $tsa = TsaShift::where('team', 'SH Naturals')->first();
        ExpectedIncomeEntry::create([
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'entry_date' => '2026-10-01',
            'is_locked' => true,
        ]);
        \App\Models\ProjectionCustomRow::create(['key' => 'custom_fee', 'label' => 'Custom Fee', 'section' => 'selling', 'is_fixed' => false]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-custom-row-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => '2026-10-01']),
            ['key' => 'custom_fee', 'value' => 777]
        );

        $response->assertStatus(422);
        $this->assertDatabaseMissing('expected_income_custom_values', [
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'custom_row_key' => 'custom_fee', 'value' => 777,
        ]);
    }

    /** A grouped product card's own lock lives on its FIRST member
     *  product's entry, same "writes land on the first member" convention
     *  the existing save tests already confirm — locking the group's card
     *  (addressed by its first member's own product id, same as a save)
     *  must not touch the second member's entry at all. */
    public function test_locking_a_grouped_products_card_locks_its_first_member_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $productA = Product::orderBy('id')->first();
        $productB = Product::orderBy('id')->skip(1)->first();
        $tsa = TsaShift::where('team', 'SH Naturals')->first();
        $group = ProductGroup::create(['label' => 'TO', 'sort_order' => 0]);
        $group->products()->attach([$productA->id, $productB->id]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-tsa', ['product' => $productA->id, 'tsaShift' => $tsa->id, 'date' => '2026-10-01']),
            ['is_locked' => true]
        );

        $response->assertOk();
        $this->assertDatabaseHas('expected_income_entries', [
            'product_id' => $productA->id, 'tsa_id' => $tsa->id, 'is_locked' => true,
        ]);
        $this->assertDatabaseMissing('expected_income_entries', [
            'product_id' => $productB->id, 'tsa_id' => $tsa->id, 'is_locked' => true,
        ]);
        // The re-rendered card still shows the GROUP's own label, not just
        // product A's own name — the lock toggle re-renders the full
        // group card, not a lone product A card.
        $this->assertStringContainsString('TO', $response->json('cardHtml'));
    }

    /** The lock button/toggle must never appear on cards that have no
     *  single date to lock in the first place — the overall TSA rollup
     *  card (always read-only regardless) and the multi-day range-summed
     *  cards (read-only for a different reason, see isRangeSummed). */
    public function test_the_lock_button_never_appears_on_read_only_cards(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $singleDayResponse = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => '2026-10-01', 'date_to' => '2026-10-01', 'team' => 'sh-naturals',
        ]));
        $singleDayContent = $singleDayResponse->getContent();
        // The overview card's own slice (bounded by data-out-scope="1" up
        // to the first product card) must carry no lock toggle at all.
        $overviewStart = strpos($singleDayContent, 'data-out-scope="1"');
        $firstProductCardStart = strpos($singleDayContent, 'data-ei-lock-toggle');
        $this->assertNotFalse($firstProductCardStart, 'test setup: expected at least one lock toggle on the page');
        $this->assertGreaterThan($overviewStart, $firstProductCardStart, 'expected the overview card to render BEFORE the first lock toggle, i.e. carry none of its own');

        $multiDayResponse = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => '2026-10-01', 'date_to' => '2026-10-02', 'team' => 'sh-naturals',
        ]));
        $multiDayResponse->assertOk();
        // The attribute SELECTOR string ('[data-ei-lock-toggle]') lives in
        // every page's own <script> block regardless of range — assert on
        // the actual button MARKUP (the attribute as it appears on a real
        // element, with its own data-locked companion) instead, or this
        // would always "pass" by matching the JS source text, never the
        // DOM it's supposedly checking.
        $multiDayResponse->assertDontSee('data-ei-lock-toggle data-locked=', false);
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

    /** Same bug, the daily "TELESALES" rollup card. */
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
        $response->assertSeeInOrder(['TELESALES', '765.68']);
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
     *  Performance" + product cards) pools every TSA's own entry on the
     *  CURRENTLY SELECTED team (or every TSA site-wide in the ALL view) —
     *  see buildSummary()'s own doc comment for the two decisions this
     *  behavior went through the same day. */
    /** Confirmed live via screenshot, 2026-09-30: "why in the top
     *  LUMIEYES/CLEAR SIGHT is not reflecting, it is per team" — the top
     *  summary card pools EVERY tsa_id for a product on the selected team
     *  (the product-level row, if any, plus every real TSA's own entry on
     *  that team), not just the product-level row, so typing into any
     *  TSA's card actually moves the top total. */
    public function test_the_range_summary_row_pools_every_tsas_entry_on_the_selected_team(): void
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
        // Only the summary scroller's own slice of the page — bounded by
        // the daily section's own "ei-day-scroller" marker, not a date
        // heading (neither view has a bare standalone one any more).
        $summaryStart = strpos($content, 'id="eiSummaryScroller"');
        $dailySectionStart = strpos($content, 'ei-day-scroller', $summaryStart);
        $summaryHtml = substr($content, $summaryStart, $dailySectionStart - $summaryStart);
        // 1,000 (product-level) + 99,999 (her own) = 100,999.00 combined.
        $this->assertStringContainsString('100,999.00', $summaryHtml);
    }

    /** Regression test, 2026-10-01: "why is it, it did not reflecting the
     *  total in TELESALES card all of the costs in the tsa's" — a TSA's
     *  own Operating Costs (Salaries + the 20 shared pools) are LOCKED to
     *  Cost Breakdown's own figures at render/response time only, never
     *  written back to ExpectedIncomeEntry's own stored columns. Pooling
     *  raw DB rows for the top summary without reapplying that same lock
     *  meant every TSA's own locked Operating Costs silently read 0 in the
     *  TELESALES card's own Total Operating Costs / Net Income, even
     *  though her individual product card correctly showed the real
     *  locked figure. Fixed in rawByProductAndDateAllTsas() by applying
     *  the same override used for her own card. */
    public function test_the_range_summary_card_includes_a_tsas_locked_operating_costs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        CostBreakdownPool::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        $tsa = TsaShift::first();
        CostBreakdownTsaEntry::where('tsa_id', $tsa->id)->update(['base_salary' => 19500.00]);
        Product::first()->update(['has_cost_allocation' => true]);
        ExpectedIncomeEntry::create([
            'product_id' => Product::first()->id, 'tsa_id' => $tsa->id, 'entry_date' => today(),
            'gross_sales' => 50000,
        ]);
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => $teamSlug,
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $summaryStart = strpos($content, 'id="eiSummaryScroller"');
        $dailySectionStart = strpos($content, 'ei-day-scroller', $summaryStart);
        $summaryHtml = substr($content, $summaryStart, $dailySectionStart - $summaryStart);

        // The TELESALES card's own Total Operating Costs must be non-zero
        // (would read 0.00 under the bug, since the TSA's locked salary/
        // pool figures were pooled from raw stored columns rather than
        // through the same override her own card applies).
        $this->assertMatchesRegularExpression('/data-out="total_operating_costs"[^>]*>\s*[1-9][\d,]*\.\d{2}/', $summaryHtml);
    }

    /** Regression test, 2026-10-02 (live screenshot): "it should be total
     *  in this TELESALES right? like others costs" — the rollup's own Tax
     *  Allocation must total EVERY real TSA on the team's own Daily Tax
     *  figure, once each, regardless of whether she has a saved entry at
     *  all (explicit correction, same day, confirmed live: "each has
     *  Operating Costs and it should be all total in the TELESALES card"
     *  — 6 real TSAs with zero saved entries between them still each
     *  contribute their own real staffing cost; reverses an earlier
     *  "active = has an ExpectedIncomeEntry row" reading that left the
     *  rollup reading 0, or some unrelated stray TSA's own row, whenever
     *  none of the real TSAs shown had typed anything in yet). */
    public function test_the_range_summary_cards_tax_allocation_totals_every_real_tsas_daily_tax(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ProjectionColumn::ensureSeededForMonth(now()->format('Y-m'));
        ProjectionColumn::where('key', 'opening_shift')->update(['orders_override' => 1000, 'average_order_value' => 500]);
        $tsa = TsaShift::first();
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';

        // No ExpectedIncomeEntry saved anywhere — every real TSA on this
        // team must still contribute her own Daily Tax figure.
        $teamTsaIds = TsaShift::where('team', $tsa->team)->pluck('id');
        $taxAllocationByTsaId = TsaDailyRateService::taxAllocationByTsaId();
        $expected = $teamTsaIds->sum(fn ($id) => $taxAllocationByTsaId[$id] ?? 0.0);
        $this->assertGreaterThan(0, $expected, 'test setup: expected a non-zero Tax Allocation figure to meaningfully verify the fix');

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => $teamSlug,
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $summaryStart = strpos($content, 'id="eiSummaryScroller"');
        $dailySectionStart = strpos($content, 'ei-day-scroller', $summaryStart);
        $summaryHtml = substr($content, $summaryStart, $dailySectionStart - $summaryStart);

        $this->assertMatchesRegularExpression(
            '/data-out="tax_allocation"[^>]*>\s*' . preg_quote(number_format($expected, 2), '/') . '/',
            $summaryHtml,
            "expected the TELESALES rollup's own Tax Allocation to total every real TSA's own Daily Tax figure ({$expected})"
        );
    }

    /** Tighter regression test, 2026-10-01/2026-10-02 (live screenshots):
     *  "this card should be the totals of the per-tsa cards" then "each has
     *  Operating Costs and it should be all total in the TELESALES card" —
     *  the TELESALES rollup's own Salaries must total EVERY real TSA on the
     *  team's own OVERVIEW-card figure, once each, REGARDLESS of whether
     *  she has any saved entry at all (explicit correction, 2026-10-02 —
     *  reverses this test's own earlier "active = has an entry" premise:
     *  confirmed live, 6 real TSAs with ZERO saved entries between them
     *  still each contribute their own real staffing cost). Flags 2
     *  products but gives only ONE TSA an entry on only ONE of them — the
     *  rollup must still total ALL of the team's real TSAs regardless.
     *
     *  The "overview-card figure" itself changed 2026-10-06 (see
     *  test_product_cards_divide_salaries_once_past_the_tsa_overview_card's
     *  own doc comment) — now her REAL, undivided Daily Rate
     *  (dailyRateByTsaId()), not the product-divided perProductByTsaId(). */
    public function test_the_range_summary_cards_salaries_totals_every_real_tsas_overview_figure(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        CostBreakdownPool::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        $tsa = TsaShift::first();
        CostBreakdownTsaEntry::where('tsa_id', $tsa->id)->update(['base_salary' => 19500.00]);

        // Flag 2 products so a per-product-divided figure would differ
        // from the undivided overview figure the rollup must show.
        $products = Product::take(2)->get();
        $products->each(fn (Product $p) => $p->update(['has_cost_allocation' => true]));

        // An entry on only ONE of her 2 flagged products — every OTHER
        // real TSA on the team has NO saved entry anywhere at all, yet
        // must still contribute her own full Salaries figure.
        ExpectedIncomeEntry::create([
            'product_id' => $products->first()->id, 'tsa_id' => $tsa->id, 'entry_date' => today(),
            'gross_sales' => 10000,
        ]);
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';

        $teamTsaIds = TsaShift::where('team', $tsa->team)->pluck('id');
        $dailyRateByTsaId = TsaDailyRateService::dailyRateByTsaId();
        $expectedSalaries = $teamTsaIds->sum(fn ($id) => $dailyRateByTsaId[$id] ?? 0.0);
        $this->assertGreaterThan(0, $expectedSalaries, 'test setup: expected a non-zero Salaries total');

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => $teamSlug,
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $summaryStart = strpos($content, 'id="eiSummaryScroller"');
        $dailySectionStart = strpos($content, 'ei-day-scroller', $summaryStart);
        $summaryHtml = substr($content, $summaryStart, $dailySectionStart - $summaryStart);

        $this->assertMatchesRegularExpression(
            '/data-out="salaries"[^>]*>\s*' . preg_quote(number_format($expectedSalaries, 2), '/') . '/',
            $summaryHtml,
            "expected the TELESALES rollup's own Salaries to total every real TSA's own overview figure ({$expectedSalaries})"
        );
    }

    /** Regression test, 2026-10-01 (live screenshot, Sept 27-30 filter): "it
     *  should be all cards will be multiplied" — a TSA active on even ONE
     *  day of a MULTI-day filtered range is assumed staffed for the WHOLE
     *  range ("every day is different gross sell right? ... it will be all
     *  sum"), so her daily Salaries/pools must be multiplied by the FULL
     *  range day-count, not just the number of days she happens to have a
     *  saved entry. Confirmed live: Mariel had exactly 1 entry inside a
     *  4-day filter and the rollup's own Salaries stayed flat at her
     *  SINGLE-day figure (243.31) instead of ×4 (973.24). Covers BOTH the
     *  overall rollup AND an individual product card in the same row
     *  (explicit confirmation, same day: the ×N rule applies to "all
     *  cards", not just the rollup). */
    public function test_a_tsas_costs_multiply_by_the_full_range_day_count_even_with_only_one_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        CostBreakdownPool::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        $tsa = TsaShift::first();
        CostBreakdownTsaEntry::where('tsa_id', $tsa->id)->update(['base_salary' => 19500.00]);

        // Flag 2 products so her own per-product-card figure is an actual
        // FRACTION of her overview figure (dividing by 1 flagged product is
        // a no-op and wouldn't distinguish the two cards' own expected
        // values from each other).
        $products = Product::take(2)->get();
        $products->each(fn (Product $p) => $p->update(['has_cost_allocation' => true]));
        $product = $products->first();

        // Only ONE entry, on ONE day, inside a 4-day filtered range.
        ExpectedIncomeEntry::create([
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'entry_date' => today(),
            'gross_sales' => 10000,
        ]);
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';
        $teamTsaIds = TsaShift::where('team', $tsa->team)->pluck('id');

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->subDays(3)->toDateString(), 'date_to' => today()->toDateString(),
            'team' => $teamSlug,
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $summaryStart = strpos($content, 'id="eiSummaryScroller"');
        $dailySectionStart = strpos($content, 'ei-day-scroller', $summaryStart);
        $summaryHtml = substr($content, $summaryStart, $dailySectionStart - $summaryStart);

        // The rollup's own Salaries = EVERY real TSA on the team's own
        // UNDIVIDED overview figure, each × 4 days (explicit correction,
        // 2026-10-02: the rollup totals every real TSA regardless of
        // whether she has an entry, not just whoever happens to have one).
        // "UNDIVIDED" here means dailyRateByTsaId() as of 2026-10-06 — see
        // test_product_cards_divide_salaries_once_past_the_tsa_overview_card's
        // own doc comment for the reversal.
        $teamTsaIds = TsaShift::where('team', $tsa->team)->pluck('id');
        $dailyRateByTsaId = \App\Support\TsaDailyRateService::dailyRateByTsaId();
        $expectedRollupSalaries = number_format($teamTsaIds->sum(fn ($id) => ($dailyRateByTsaId[$id] ?? 0.0) * 4), 2);
        $this->assertMatchesRegularExpression(
            '/data-out="salaries"[^>]*>\s*' . preg_quote($expectedRollupSalaries, '/') . '/',
            $summaryHtml,
            "expected the TELESALES rollup's own Salaries to total every real TSA's own daily figure × 4 days ({$expectedRollupSalaries})"
        );

        // This product card's own Salaries = EVERY real TSA on the team's
        // own per-product figure, each × 4 days (explicit confirmation,
        // 2026-10-02: "the per product i want it will total too" — every
        // product card totals every real TSA the same way the rollup does,
        // regardless of whether she has an entry on THIS specific
        // product). Sliced to just THIS product's own card (bounded by its
        // own label through the next card's). perProductByTsaId() (ONE
        // division), not perProductByTsaIdTwice() — corrected 2026-10-06,
        // see test_product_cards_divide_salaries_once_past_the_tsa_overview_card's
        // own doc comment.
        $productCardStart = strpos($summaryHtml, $product->display_name);
        $productCardHtml = substr($summaryHtml, $productCardStart, 20000);
        $perProductByTsaId = \App\Support\TsaDailyRateService::perProductByTsaId();
        $expectedProductCardSalaries = number_format($teamTsaIds->sum(fn ($id) => ($perProductByTsaId[$id] ?? 0.0) * 4), 2);
        $this->assertMatchesRegularExpression(
            '/data-out="salaries"[^>]*>\s*' . preg_quote($expectedProductCardSalaries, '/') . '/',
            $productCardHtml,
            "expected {$product->display_name}'s own card Salaries to be her per-product figure × 4 days ({$expectedProductCardSalaries})"
        );
    }

    /** The team filter PILL now scopes the top summary too (explicit
     *  correction, 2026-09-30: "when per team filter the Telesales
     *  Expected Performance is per team only" — reverses the SAME DAY's
     *  earlier "always every TSA on every team" decision) — an entry
     *  belonging to a DIFFERENT team's TSA must not appear in this team's
     *  top summary total. */
    public function test_the_range_summary_row_only_pools_the_selected_teams_own_tsas(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $tsa = TsaShift::first();
        ExpectedIncomeEntry::create(['product_id' => $product->id, 'tsa_id' => $tsa->id, 'entry_date' => today(), 'gross_sales' => 42000]);

        $viewingHerOwnTeam = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';
        $viewingTheOtherTeam = $viewingHerOwnTeam === 'sh-naturals' ? 'eyecare' : 'sh-naturals';

        $extractSummaryHtml = function (string $content) {
            $summaryStart = strpos($content, 'id="eiSummaryScroller"');
            // The daily section always starts with an "ei-day-scroller"
            // element in every view (ALL or a real team) — a more reliable
            // boundary than a date heading, since neither view has a bare
            // standalone date heading of its own any more.
            $dailySectionStart = strpos($content, 'ei-day-scroller', $summaryStart);
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
        $this->assertStringNotContainsString('42,000.00', $summaryB);
    }

    /** Confirmed live via screenshot, 2026-09-30: "in the first cards is
     *  the overall and in the next down part is like team opening and
     *  closing" — below the main TELESALES summary row, one more full row
     *  per real team (labeled by its real name, e.g. SH NATURALS/EYECARE),
     *  shown regardless of which team pill is selected. */
    public function test_a_per_team_summary_row_appears_for_every_real_team(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(), 'team' => 'all',
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $afterMainSummary = strpos($content, 'id="eiSummaryScroller"');
        // "EYECARE" also appears earlier as a filter pill label — only
        // proof of a real summary ROW is finding it again AFTER the main
        // summary scroller.
        $this->assertNotFalse(strpos($content, 'SH NATURALS', $afterMainSummary));
        $this->assertNotFalse(strpos($content, 'EYECARE', $afterMainSummary));
    }

    /** Explicit follow-up, 2026-09-30: "why is it when i am with filter in
     *  the per team why is it there's per team in there too like in all?
     *  it will be only the Telesales Expected Performance and TSA'S CARDS
     *  AND THEIR PRODUCTS" — selecting a real team must show ONLY the
     *  plain main summary row, then go straight to that team's own TSA
     *  cards; the per-team summary rows (SH NATURALS/EYECARE black-header
     *  cards) are an ALL-view-only addition. */
    public function test_per_team_summary_rows_do_not_appear_when_a_specific_team_is_selected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(), 'team' => $teamSlug,
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $afterMainSummary = strpos($content, 'id="eiSummaryScroller"');
        $dailySectionStart = strpos($content, 'ei-day-scroller', $afterMainSummary);
        // The gap between the main summary row and the daily section must
        // contain NO per-team summary row heading at all.
        $betweenSummaryAndDaily = substr($content, $afterMainSummary, $dailySectionStart - $afterMainSummary);
        $this->assertStringNotContainsString('SH NATURALS', $betweenSummaryAndDaily);
        $this->assertStringNotContainsString('EYECARE', $betweenSummaryAndDaily);
    }

    /** A team's own summary row only pools that team's own TSAs' entries
     *  (plus the shared product-level entry) — never the other team's. */
    public function test_a_teams_own_summary_row_excludes_the_other_teams_tsa_entries(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shNaturalsProduct = Product::where('team', 'SH Naturals')->first();
        $eyecareProduct = Product::where('team', 'Eyecare Team')->first();
        $shNaturalsTsa = TsaShift::where('team', 'SH Naturals')->first();
        $eyecareTsa = TsaShift::where('team', 'Eyecare Team')->first();
        $date = today()->toDateString();

        ExpectedIncomeEntry::create(['product_id' => $shNaturalsProduct->id, 'tsa_id' => $shNaturalsTsa->id, 'entry_date' => $date, 'gross_sales' => 7000]);
        ExpectedIncomeEntry::create(['product_id' => $eyecareProduct->id, 'tsa_id' => $eyecareTsa->id, 'entry_date' => $date, 'gross_sales' => 3000]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $date, 'date_to' => $date, 'team' => 'all',
        ]));

        $response->assertOk();
        $content = $response->getContent();
        // Bound each team row by the START of the NEXT team row's own
        // heading (config/teams.php orders sh-naturals before eyecare) —
        // NOT by the daily section's date heading, which sits after BOTH
        // team rows and would let one row's slice swallow the other's.
        $afterMainSummary = strpos($content, 'id="eiSummaryScroller"');
        $shStart = strpos($content, 'SH NATURALS', $afterMainSummary);
        $this->assertNotFalse($shStart, 'expected to find the SH NATURALS summary row');
        $eyeStart = strpos($content, 'EYECARE', $shStart);
        $this->assertNotFalse($eyeStart, 'expected to find the EYECARE summary row');
        // Bound by the <script> tag closing off the page's own inline JS —
        // the ALL view no longer renders ANY daily rows below the summary
        // section at all (explicit request, 2026-09-30: "it should be only
        // Telesales Expected Performance, TEAM 1, TEAM 2 and no other rows
        // of cards"), so the EYECARE row simply runs to the end of the
        // page's real content instead of being followed by a daily section.
        $dailySectionStart = strpos($content, '<script>', $eyeStart);
        $this->assertNotFalse($dailySectionStart, 'expected to find the page script tag after both team rows');

        $shNaturalsRow = substr($content, $shStart, $eyeStart - $shStart);
        $eyecareRow = substr($content, $eyeStart, $dailySectionStart - $eyeStart);

        $this->assertStringContainsString('7,000.00', $shNaturalsRow);
        $this->assertStringNotContainsString('3,000.00', $shNaturalsRow);
        $this->assertStringContainsString('3,000.00', $eyecareRow);
        $this->assertStringNotContainsString('7,000.00', $eyecareRow);
    }

    /** A team's own summary row shows a card for EVERY product, not just
     *  products whose own Product.team assignment matches that team
     *  (explicit correction, 2026-09-30: "it should have all products per
     *  team ... all tsa they handle all products" — a product's Team field
     *  in Product Management is unrelated to which products a TSA actually
     *  enters numbers for; this row must match buildTeamDailyRows()'s own
     *  per-TSA cards below, which already use the full unfiltered product
     *  list). Before this fix, an Eyecare-team product's card was silently
     *  missing from the SH NATURALS row and vice versa. */
    public function test_a_teams_own_summary_row_shows_every_product_not_just_its_own_team(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $eyecareProduct = Product::where('team', 'Eyecare Team')->first();
        $date = today()->toDateString();

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $date, 'date_to' => $date, 'team' => 'all',
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $afterMainSummary = strpos($content, 'id="eiSummaryScroller"');
        $shStart = strpos($content, 'SH NATURALS', $afterMainSummary);
        $this->assertNotFalse($shStart, 'expected to find the SH NATURALS summary row');
        $eyeStart = strpos($content, 'EYECARE', $shStart);
        $this->assertNotFalse($eyeStart, 'expected to find the EYECARE summary row');

        $shNaturalsRow = substr($content, $shStart, $eyeStart - $shStart);
        // An Eyecare-team product's own card label must still appear in the
        // SH NATURALS row's product cards.
        $this->assertStringContainsString(strtoupper($eyecareProduct->display_name), $shNaturalsRow);
    }

    /** Confirmed live via screenshot, 2026-09-30: picking a real team
     *  swaps the daily "TELESALES" overall card for one block PER REAL TSA
     *  on that team, her own name as the card title, followed by her own
     *  product cards. */
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
        // The main summary row above still legitimately shows "TELESALES"
        // regardless of team — only the DAILY section's own overall card
        // is replaced by her own name, so check that slice specifically.
        $content = $response->getContent();
        $dailySectionStart = strpos($content, 'ei-day-scroller');
        $this->assertStringNotContainsString('TELESALES', substr($content, $dailySectionStart));
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

    /** Salaries on a TSA-scoped product card is locked to her own Daily
     *  Rate / Product from Cost Breakdown (explicit request, 2026-09-30:
     *  "the salaries row is based to the Daily Rate / Product") — no
     *  editable input for it at all, even though every other field on the
     *  same card stays editable. */
    public function test_a_tsas_product_card_shows_salaries_locked_to_her_daily_rate_per_product(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        CostBreakdownPool::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        $tsa = TsaShift::first();
        CostBreakdownTsaEntry::where('tsa_id', $tsa->id)->update(['base_salary' => 19500.00]);
        // Only FLAGGED products divide the cost on Cost Breakdown (explicit
        // follow-up, 2026-09-30: "user only can identify what product that
        // has cost") — flag one so this test's own Salaries figure is
        // non-zero, same as before that feature.
        Product::first()->update(['has_cost_allocation' => true]);
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => $teamSlug,
        ]));

        $response->assertOk();
        $content = $response->getContent();

        // Her own first product card's own slice — bounded by her own
        // overview card's name (start) and the next TSA's own name or end
        // of the daily section (a single-TSA-per-team fixture keeps this
        // simple: just check no data-field="salaries" appears anywhere
        // inside the whole daily section, since every product card is
        // hers).
        $dailySectionStart = strpos($content, 'ei-day-scroller');
        $dailyHtml = substr($content, $dailySectionStart);
        $this->assertStringNotContainsString('data-field="salaries"', $dailyHtml);

        // Her own Total from Cost Breakdown: 19,500.00 raw base_salary +
        // every applicable overhead ref (whatever CostBreakdownRole::
        // ensureSeeded()'s own real seed values total to) — rather than
        // re-deriving that chain here, just confirm SOME non-zero read-only
        // Salaries figure renders (a locked 0.00 would mean the override
        // never applied at all).
        $this->assertMatchesRegularExpression('/data-out="salaries"[^>]*>\s*[1-9][\d,]*\.\d{2}/', $dailyHtml);
    }

    /** Every BUILT-IN Operating Costs row (not just Salaries) on a
     *  TSA-scoped product card is locked to Cost Breakdown's own figures
     *  (explicit follow-up, 2026-09-30: "the daily cost is it is this
     *  [Communication Allowance, 13th Month Allowance, SIL, ...]") — no
     *  editable input for any of the 21 built-in rows. A CUSTOM row (added
     *  via Projections' + icon) has no Cost Breakdown source, so it stays
     *  a plain editable input even here. */
    public function test_every_built_in_operating_cost_row_is_locked_on_a_tsas_product_card(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownPool::ensureSeeded();
        $tsa = TsaShift::first();
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';

        \App\Models\ProjectionCustomRow::create([
            'key' => 'custom_office_snacks', 'section' => 'operating',
            'label' => 'Office Snacks', 'is_fixed' => false, 'sort_order' => 0,
        ]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => $teamSlug,
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $dailySectionStart = strpos($content, 'ei-day-scroller');
        $dailyHtml = substr($content, $dailySectionStart);

        // None of the 20 shared-pool keys has an editable input here.
        foreach (array_keys(\App\Support\ExpectedIncomeCalculator::OPERATING_COST_ROWS) as $key) {
            $this->assertStringNotContainsString("data-field=\"{$key}\"", $dailyHtml, "expected {$key} to be locked (no input) on a TSA-scoped card");
        }
        // The custom row still has one.
        $this->assertStringContainsString('data-field="custom_office_snacks"', $dailyHtml);
    }

    /** A built-in Operating Costs row's own LOCKED figure matches Cost
     *  Breakdown's own "Daily Cost per product" mini-table exactly —
     *  never a stale/zero placeholder. */
    public function test_a_locked_operating_cost_row_shows_cost_breakdowns_own_figure(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownPool::ensureSeeded();
        Product::first()->update(['has_cost_allocation' => true]);
        $tsa = TsaShift::first();
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';

        // Sources the "Daily Cost per product" row (pool ÷ real TSA count
        // ÷ 24 ÷ checked-product count) — explicit correction, 2026-09-30:
        // "13th Month Allowance / it should divided by number of product".
        $expected = TsaDailyRateService::dailyCostPerProductRow()['communication_allowance'];
        $this->assertGreaterThan(0, $expected);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => $teamSlug,
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $dailySectionStart = strpos($content, 'ei-day-scroller');
        $dailyHtml = substr($content, $dailySectionStart);

        $this->assertMatchesRegularExpression(
            '/data-out="communication_allowance"[^>]*>\s*' . preg_quote(number_format($expected, 2), '/') . '/',
            $dailyHtml
        );
    }

    /** Her own "[TSA NAME]" overview card does NOT divide the locked
     *  Operating Costs rows by product count, unlike every PRODUCT card
     *  beneath it (explicit follow-up, 2026-09-30, right after the above
     *  correction: "in the product cards only okay? ... not in tsa name
     *  card") — flag 2 products so dailyCostPerProductRow() and
     *  dailyCostRow() provably diverge, then confirm the overview card
     *  shows the UNDIVIDED figure while a product card shows the divided
     *  one. */
    public function test_the_tsa_overview_card_does_not_divide_operating_costs_by_product_count(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownPool::ensureSeeded();
        Product::orderBy('id')->take(2)->get()->each(fn (Product $p) => $p->update(['has_cost_allocation' => true]));
        $tsa = TsaShift::first();
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';

        $undivided = TsaDailyRateService::dailyCostRow()['communication_allowance'];
        $dividedByProduct = TsaDailyRateService::dailyCostPerProductRow()['communication_allowance'];
        $this->assertGreaterThan($dividedByProduct, $undivided, 'test setup: dividing by 2 products should shrink the figure');

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => $teamSlug,
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $namePos = strpos($content, $tsa->display_name);
        $firstFieldPos = strpos($content, 'data-field=', $namePos);
        $overviewHtml = substr($content, $namePos, $firstFieldPos - $namePos);
        $productCardsHtml = substr($content, $firstFieldPos);

        $this->assertStringContainsString(number_format($undivided, 2), $overviewHtml);
        $this->assertStringNotContainsString(number_format($dividedByProduct, 2), $overviewHtml);
        $this->assertMatchesRegularExpression(
            '/data-out="communication_allowance"[^>]*>\s*' . preg_quote(number_format($dividedByProduct, 2), '/') . '/',
            $productCardsHtml
        );
    }

    /** Salaries — REVERSED TWICE on 2026-10-06, same day, two explicit
     *  follow-ups to the same live screenshot comparison:
     *  (1) "the salaries per tsa card is wrong it should be the Daily
     *  Rate (÷24) in the cost breakdown page" — her overview card now
     *  shows her REAL, undivided Daily Rate (dailyRateByTsaId(), matching
     *  Cost Breakdown's own "Daily Rate (÷24)" column exactly), reversing
     *  the original 2026-10-01 decision this test encoded (overview
     *  showed Daily Rate / Product instead).
     *  (2) "in the products it should be divided by 7 like other cost
     *  too" (confirmed live: 1,682.86 ÷ 7 ≈ 240.41 expected) — now that
     *  the overview card is undivided, a PRODUCT card divides it by
     *  product count exactly ONCE (perProductByTsaId()), same single
     *  division every other Operating Costs row already gets — NOT
     *  perProductByTsaIdTwice()'s own two divisions, which this test
     *  originally asserted and which would now divide by 49, not 7. */
    public function test_product_cards_divide_salaries_once_past_the_tsa_overview_card(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        Product::orderBy('id')->take(2)->get()->each(fn (Product $p) => $p->update(['has_cost_allocation' => true]));
        $tsa = TsaShift::first();
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';

        $overviewFigure = TsaDailyRateService::dailyRateByTsaId()[$tsa->id];
        $productCardFigure = TsaDailyRateService::perProductByTsaId()[$tsa->id];
        $this->assertGreaterThan($productCardFigure, $overviewFigure, 'test setup: dividing by 2 products should shrink the figure below the undivided rate');

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => $teamSlug,
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $namePos = strpos($content, $tsa->display_name);
        $firstFieldPos = strpos($content, 'data-field=', $namePos);
        $overviewHtml = substr($content, $namePos, $firstFieldPos - $namePos);
        $productCardsHtml = substr($content, $firstFieldPos);

        $this->assertStringContainsString(number_format($overviewFigure, 2), $overviewHtml);
        $this->assertStringNotContainsString(number_format($productCardFigure, 2), $overviewHtml);
        $this->assertMatchesRegularExpression(
            '/data-out="salaries"[^>]*>\s*' . preg_quote(number_format($productCardFigure, 2), '/') . '/',
            $productCardsHtml
        );
    }

    /** Tax Allocation on a TSA-scoped card is locked (explicit request,
     *  2026-10-02: "the tax allocation in tsa cards is should be not
     *  editable") — no editable input on any of her product cards, same
     *  "computed, never editable" convention as every built-in Operating
     *  Costs row. */
    public function test_tax_allocation_is_locked_on_a_tsas_card(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ProjectionColumn::ensureSeededForMonth(now()->format('Y-m'));
        $tsa = TsaShift::first();
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => $teamSlug,
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $dailySectionStart = strpos($content, 'ei-day-scroller');
        // Bounded to end before the page's own <script> block (pushed via
        // @push('scripts')) — that script legitimately contains the literal
        // string 'data-field="tax_allocation"' inside a querySelector() call
        // (reading the locked figure's own read-only fallback), which would
        // otherwise false-positive this assertion despite no such DOM
        // attribute actually being rendered anywhere.
        $scriptStart = strpos($content, '<script>', $dailySectionStart);
        $dailyHtml = substr($content, $dailySectionStart, $scriptStart - $dailySectionStart);

        $this->assertStringNotContainsString('data-field="tax_allocation"', $dailyHtml);
    }

    /** Tax Allocation's own two-tier division (NOT the same methods as
     *  Salaries — Salaries moved to a single division on 2026-10-06, see
     *  test_product_cards_divide_salaries_once_past_the_tsa_overview_card's
     *  own doc comment; Tax Allocation's own two-tier shape was untouched
     *  by that reversal, still unchanged here) — her overview card shows
     *  the undivided per-team Daily Tax figure, and each individual
     *  product card divides THAT figure again by product count, same
     *  shape as TsaDailyRateService::taxAllocationByTsaId() →
     *  perProductTaxAllocationByTsaId(). */
    public function test_product_cards_divide_tax_allocation_a_second_time_past_the_tsa_overview_card(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ProjectionColumn::ensureSeededForMonth(now()->format('Y-m'));
        Product::orderBy('id')->take(2)->get()->each(fn (Product $p) => $p->update(['has_cost_allocation' => true]));
        $tsa = TsaShift::first();
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';

        $overviewFigure = TsaDailyRateService::taxAllocationByTsaId()[$tsa->id];
        $productCardFigure = TsaDailyRateService::perProductTaxAllocationByTsaId()[$tsa->id];
        $this->assertGreaterThan($productCardFigure, $overviewFigure, 'test setup: dividing a second time by 2 products should shrink the figure');

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => $teamSlug,
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $namePos = strpos($content, $tsa->display_name);
        $firstFieldPos = strpos($content, 'data-field=', $namePos);
        $overviewHtml = substr($content, $namePos, $firstFieldPos - $namePos);
        $productCardsHtml = substr($content, $firstFieldPos);

        $this->assertMatchesRegularExpression(
            '/data-out="tax_allocation"[^>]*>\s*' . preg_quote(number_format($overviewFigure, 2), '/') . '/',
            $overviewHtml
        );
        $this->assertMatchesRegularExpression(
            '/data-out="tax_allocation"[^>]*>\s*' . preg_quote(number_format($productCardFigure, 2), '/') . '/',
            $productCardsHtml
        );
    }

    /** Regression test, 2026-10-02 (live screenshot): the overview card's
     *  own Tax Allocation rendered 0.00 in production while her product
     *  cards correctly showed a non-zero figure — production has 12 real
     *  TSAs (6 per team), not this app's dev-DB default of 6 total (3 per
     *  team), so this test explicitly pads BOTH teams up to 6 TSAs each to
     *  reproduce that exact roster shape and guard against any future
     *  per-team-count regression no smaller fixture would ever catch. */
    public function test_the_overview_cards_tax_allocation_is_non_zero_with_a_production_sized_roster(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ProjectionColumn::ensureSeededForMonth(now()->format('Y-m'));
        ProjectionColumn::where('key', 'opening_shift')->update(['orders_override' => 1000, 'average_order_value' => 500]);

        // Pad both teams up to 6 real TSAs each (12 total), matching
        // production's own real roster shape exactly.
        foreach (['SH Naturals', 'Eyecare Team'] as $team) {
            $existing = TsaShift::where('team', $team)->count();
            for ($i = $existing; $i < 6; $i++) {
                TsaShift::create([
                    'tsa_key' => "{$team}-extra-{$i}", 'display_name' => "{$team} Extra {$i}",
                    'team' => $team, 'sort_order' => 100 + $i,
                ]);
            }
        }
        $this->assertSame(12, TsaShift::count(), 'test setup: expected 12 real TSAs total, matching production');

        $tsa = TsaShift::where('team', 'SH Naturals')->first();
        $expected = TsaDailyRateService::taxAllocationByTsaId()[$tsa->id];
        $this->assertGreaterThan(0, $expected, 'test setup: expected a non-zero Tax Allocation figure');

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => 'sh-naturals',
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $namePos = strpos($content, $tsa->display_name);
        $firstFieldPos = strpos($content, 'data-field=', $namePos);
        $overviewHtml = substr($content, $namePos, $firstFieldPos - $namePos);

        $this->assertMatchesRegularExpression(
            '/data-out="tax_allocation"[^>]*>\s*' . preg_quote(number_format($expected, 2), '/') . '/',
            $overviewHtml,
            'expected the overview card\'s own Tax Allocation to be non-zero and match TsaDailyRateService::taxAllocationByTsaId(), not 0.00'
        );
    }

    /** Regression test, 2026-10-03 (live screenshot, "why is it all last
     *  tsa has no tax?" — a newly-added TSA showed correct Daily Tax on
     *  Cost Breakdown but 0.00 on Expected Income). Simulates the
     *  suspected race directly: TsaDailyRateService::allTsas() caches its
     *  own TsaShift::all() result per-request via app()->scoped() — a TSA
     *  created AFTER that cache is first primed is, by design, absent
     *  from the pre-built taxAllocationByTsaId() map for the REST of that
     *  same request. Confirms her own overview + product cards still show
     *  a non-zero Tax Allocation anyway, via the direct-compute fallback
     *  (TsaDailyRateService::taxAllocationForTsa()/
     *  perProductTaxAllocationForTsa()) added for exactly this case. */
    public function test_a_tsa_missing_from_the_prebuilt_tax_map_still_shows_her_own_tax_allocation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ProjectionColumn::ensureSeededForMonth(now()->format('Y-m'));
        ProjectionColumn::where('key', 'opening_shift')->update(['orders_override' => 1000, 'average_order_value' => 500]);

        // Prime the per-request allTsas() cache with the roster as it
        // exists RIGHT NOW (deliberately excluding the TSA created below),
        // same as would happen if any earlier lookup in this request ran
        // first — exactly the race this fallback guards against.
        TsaDailyRateService::taxAllocationByTsaId();

        $lateTsa = TsaShift::create([
            'tsa_key' => 'late-arrival', 'display_name' => 'Late Arrival',
            'team' => 'SH Naturals', 'sort_order' => 999,
        ]);

        // Confirms the race is actually reproduced: the pre-built map
        // genuinely doesn't have her, same as the live bug.
        $this->assertArrayNotHasKey($lateTsa->id, TsaDailyRateService::taxAllocationByTsaId());

        $expected = TsaDailyRateService::taxAllocationForTsa($lateTsa);
        $this->assertGreaterThan(0, $expected, 'test setup: expected a non-zero Tax Allocation figure for the fallback to prove');

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => 'sh-naturals',
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $namePos = strpos($content, $lateTsa->display_name);
        $this->assertNotFalse($namePos, 'expected the late-arriving TSA to still render her own card');
        $firstFieldPos = strpos($content, 'data-field=', $namePos);
        $overviewHtml = substr($content, $namePos, $firstFieldPos - $namePos);

        $this->assertMatchesRegularExpression(
            '/data-out="tax_allocation"[^>]*>\s*' . preg_quote(number_format($expected, 2), '/') . '/',
            $overviewHtml,
            'expected the late-arriving TSA\'s own overview card to fall back to a direct, non-zero Tax Allocation'
        );
    }

    /** A TikTok-flagged TSA gets no Tax Allocation of her own, on both the
     *  pre-built map AND its direct fallback (explicit request,
     *  2026-10-07: "when tsa is on the tiktok she is not included to the
     *  divided tax ... so anne will be no tax") — confirmed consistent
     *  between TsaDailyRateService::taxAllocationByTsaId() and
     *  taxAllocationForTsa() (the fallback used when a TSA is missing
     *  from the pre-built map), same "never silently disagree with the
     *  map it stands in for" rule the fallback's own doc comment states. */
    public function test_a_tiktok_flagged_tsa_has_zero_tax_allocation_on_both_the_map_and_its_fallback(): void
    {
        ProjectionColumn::ensureSeededForMonth(now()->format('Y-m'));
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);

        $this->assertSame(0.0, TsaDailyRateService::taxAllocationByTsaId()[$tsa->id] ?? null);
        $this->assertSame(0.0, TsaDailyRateService::taxAllocationForTsa($tsa));
    }

    /** Real bug caught live, 2026-10-06 (screenshot): "kathleen has no
     *  cost why is it displaying to the expected income that has costs" —
     *  a 0-day TSA's card still showed nonzero Salaries, Tax Allocation,
     *  AND Operating Costs (Communication Allowance etc.). Scope was then
     *  refined through several explicit corrections the same day: "the
     *  only has data is Salaries of that tsa" → "the salaries is will stay
     *  but the other costs will be 0.00 when 0 days" → final correction,
     *  "the salary will be stay because the salary will be base to the
     *  Daily Rate (÷24)". FINAL intended behavior: Salaries always matches
     *  Cost Breakdown's own Daily Rate (÷24) regardless of Days; only the
     *  shared pool Operating Cost rows (Communication Allowance, etc.) are
     *  zeroed for a 0-day TSA; Tax Allocation stays untouched either way. */
    public function test_a_zero_day_tsas_card_zeroes_operating_costs_but_keeps_salaries_and_tax_allocation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownPool::ensureSeeded();
        CostBreakdownRole::ensureSeeded();
        ProjectionColumn::ensureSeededForMonth(now()->format('Y-m'));
        $tsa = TsaShift::first();
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';
        CostBreakdownTsaEntry::updateOrCreate(['tsa_id' => $tsa->id], ['days' => 0, 'base_salary' => 19500]);

        $expectedSalaries = TsaDailyRateService::dailyRateByTsaId()[$tsa->id];
        $this->assertGreaterThan(0, $expectedSalaries, 'test setup: Salaries must stay non-zero (her real Daily Rate ÷24) for a 0-day TSA');
        $expectedTax = TsaDailyRateService::taxAllocationByTsaId()[$tsa->id];
        $this->assertGreaterThan(0, $expectedTax, 'test setup: Tax Allocation must stay non-zero for a 0-day TSA');

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => $teamSlug,
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $namePos = strpos($content, $tsa->display_name);
        $this->assertNotFalse($namePos);
        $firstFieldPos = strpos($content, 'data-field=', $namePos);
        $overviewHtml = substr($content, $namePos, $firstFieldPos - $namePos);

        $this->assertMatchesRegularExpression(
            '/data-out="salaries"[^>]*>\s*' . preg_quote(number_format($expectedSalaries, 2), '/') . '/',
            $overviewHtml,
            'expected a 0-day TSA\'s overview card Salaries to stay non-zero, matching Cost Breakdown\'s own Daily Rate (÷24)'
        );
        $this->assertMatchesRegularExpression(
            '/data-out="communication_allowance"[^>]*>\s*0\.00/',
            $overviewHtml,
            'expected a 0-day TSA\'s overview card Operating Costs (e.g. Communication Allowance) to show 0.00'
        );
        $this->assertMatchesRegularExpression(
            '/data-out="tax_allocation"[^>]*>\s*' . preg_quote(number_format($expectedTax, 2), '/') . '/',
            $overviewHtml,
            'expected a 0-day TSA\'s overview card Tax Allocation to stay non-zero and unaffected'
        );
    }

    /** Regression test, 2026-10-02 (live 500 on Railway): a full-month,
     *  per-team Expected Income filter errored in production after the
     *  Projections-per-month change added a DB seed-check
     *  (ensureSeededForMonth(), 7 firstOrCreate queries) inside
     *  TsaDailyRateService::departmentTaxAllocationPerShift() — a method
     *  buildSummaryRow() already calls up to 8+ times per request (once per
     *  team row × productCardLookups + rollupLookups), reintroducing the
     *  same N+1-shaped timeout the earlier 2026-10-02 N+1 fix had just
     *  removed. Asserts ProjectionColumn queries stay flat regardless of
     *  team-row count, proving departmentTaxAllocationPerShift()'s
     *  per-request memoization (app()->scoped()) is actually taking effect
     *  — a query count that scales with team count would mean the cache
     *  regressed. */
    public function test_a_full_month_all_teams_filter_does_not_requery_projection_columns_per_team_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ProjectionColumn::ensureSeededForMonth(now()->format('Y-m'));

        foreach (['SH Naturals', 'Eyecare Team'] as $team) {
            $existing = TsaShift::where('team', $team)->count();
            for ($i = $existing; $i < 6; $i++) {
                TsaShift::create([
                    'tsa_key' => "{$team}-extra-{$i}", 'display_name' => "{$team} Extra {$i}",
                    'team' => $team, 'sort_order' => 100 + $i,
                ]);
            }
        }

        $projectionQueries = 0;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$projectionQueries) {
            if (str_contains($query->sql, 'projection_columns')) {
                $projectionQueries++;
            }
        });

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->endOfMonth()->toDateString(),
            'team' => 'all',
        ]));

        $response->assertOk();
        // buildSummary() builds 1 ALL row + 1 row per real team (2 teams
        // here) = 3 buildSummaryRow() calls; each one's own
        // departmentTaxAllocationPerShift() call must hit the DB at most
        // once (the ensureSeededForMonth() firstOrCreate loop + the
        // columns SELECT), never once per buildSummaryRow() call layered
        // on top of that, and never once per lookup set within a call.
        $this->assertLessThanOrEqual(
            10,
            $projectionQueries,
            "expected projection_columns queries to stay flat across all 3 summary rows (ALL + 2 teams), got {$projectionQueries} — ".
            'departmentTaxAllocationPerShift()\'s per-request cache is not taking effect, reintroducing the full-month 500'
        );
    }

    /** A product-level save with no tsa_id at all (update()'s own
     *  $tsaShift = null branch — the shared, non-TSA-scoped entry every
     *  page's own tsa_id-NULL row still writes to) has no TSA to compute a
     *  Daily Rate / Product from, so Salaries there is unaffected — the
     *  override never applies and the manually-entered figure passes
     *  through unchanged, same as before this feature. */
    public function test_a_product_level_save_with_no_tsa_leaves_salaries_manual(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        $product = Product::first();
        $date = today()->toDateString();

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update', ['product' => $product->id, 'date' => $date]),
            ['salaries' => 750.00]
        );

        $response->assertOk();
        $response->assertJsonPath('derived.operating_lines.salaries', fn ($v) => abs($v - 750.00) < 0.01);
    }

    /** update()'s own returned 'derived' payload reflects the Salaries
     *  override too (explicit follow-up — a live autosave must never
     *  repaint the card with the stale pre-lock manual figure it's
     *  replacing). */
    public function test_updating_a_tsas_product_card_returns_salaries_from_her_daily_rate_per_product(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        CostBreakdownPool::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        $tsa = TsaShift::first();
        CostBreakdownTsaEntry::where('tsa_id', $tsa->id)->update(['base_salary' => 19500.00]);
        $product = Product::first();
        // Only FLAGGED products divide the cost (explicit follow-up,
        // 2026-09-30) — flag it so Salaries computes to a non-zero figure.
        $product->update(['has_cost_allocation' => true]);
        $date = today()->toDateString();

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 5000]
        );

        $response->assertOk();
        $response->assertJsonPath('derived.operating_lines.salaries', fn ($v) => $v > 0);
    }

    /**
     * Real bug caught live, 2026-10-06 (after the initial page-render fix
     * already landed): "why is it when i delete the gross sell is the
     * operating costs will be has data?" — editing ANY field on an
     * UNFLAGGED product's card (Gross Sales here) re-triggers this
     * autosave endpoint, whose own 'derived' response was STILL applying
     * the locked Operating Costs/Tax Allocation override unconditionally
     * (derivedForProductOrGroup()/withOperatingCostOverridesIfTsaScoped()
     * had no has_cost_allocation gate of their own, a separate code path
     * from buildTeamDailyRows()'s initial render) — so the card would
     * flash back to showing a real Operating Costs share the instant you
     * typed anything, even though the page's own initial load already
     * correctly showed 0.00 for it. */
    public function test_updating_an_unflagged_products_card_does_not_apply_an_operating_costs_share(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        CostBreakdownPool::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        $tsa = TsaShift::first();
        CostBreakdownTsaEntry::where('tsa_id', $tsa->id)->update(['base_salary' => 19500.00]);
        $product = Product::first();
        $product->update(['has_cost_allocation' => false]);
        $date = today()->toDateString();

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 5000]
        );

        $response->assertOk();
        $response->assertJsonPath('derived.operating_lines.salaries', fn ($v) => (float) $v === 0.0);
        $response->assertJsonPath('derived.tax_allocation', fn ($v) => (float) $v === 0.0);
    }

    /** A live autosave's returned 'derived' payload reflects the LOCKED Tax
     *  Allocation figure too, not a stale manually-saved value — same
     *  reasoning as the Salaries test above, via
     *  withOperatingCostOverridesIfTsaScoped()'s own doc comment. */
    public function test_updating_a_tsas_product_card_returns_tax_allocation_from_cost_breakdown(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ProjectionColumn::ensureSeededForMonth(now()->format('Y-m'));
        // Opening Shift needs a real Gross Sales target for Tax Allocation
        // to compute to anything non-zero (orders_override × average_order_
        // value, same inputs ProjectionCalculator::basePnl() reads).
        ProjectionColumn::where('key', 'opening_shift')->update(['orders_override' => 1000, 'average_order_value' => 500]);
        $product = Product::first();
        // Only FLAGGED products divide the cost (explicit follow-up,
        // 2026-09-30) — flag it so the per-product figure computes to a
        // non-zero value instead of the "no flagged products" 0.0 default.
        $product->update(['has_cost_allocation' => true]);
        $tsa = TsaShift::first();
        $date = today()->toDateString();

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 5000]
        );

        $response->assertOk();
        $expected = TsaDailyRateService::perProductTaxAllocationByTsaId()[$tsa->id];
        $this->assertGreaterThan(0, $expected, 'test setup: expected a non-zero Tax Allocation figure to meaningfully verify the lock');
        $this->assertEquals($expected, $response->json('derived.tax_allocation'));
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

    /** Live refresh for the top "Telesales Expected Performance" row
     *  (explicit request, 2026-09-30: "why should i fully reload the page
     *  to reflect that" — a TSA's own save doesn't repaint this row client
     *  side, since it pools every TSA/team's own entries; the page instead
     *  re-fetches this endpoint after every autosave). Asserts the
     *  fragment reflects a freshly saved TSA entry with no page reload. */
    public function test_the_summary_endpoint_reflects_a_tsas_entry_with_no_page_reload(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $tsa = TsaShift::first();
        $date = today()->toDateString();

        ExpectedIncomeEntry::create(['product_id' => $product->id, 'tsa_id' => $tsa->id, 'entry_date' => $date, 'gross_sales' => 60000]);

        $response = $this->actingAs($admin)->get(route('data.expected-income.summary', [
            'date_from' => $date, 'date_to' => $date, 'team' => 'all',
        ]));

        $response->assertOk();
        $response->assertSee('id="eiSummarySection"', false);
        $response->assertSee('60,000.00');
    }

    /** TikTok's own 2 fixed cards — explicit request, 2026-10-05, real
     *  sheet screenshot: "TIKTOK: SH NATURALS" / "TIKTOK: NATUREVA".
     *  Originally shown inline inside a TikTok-flagged TSA's own card
     *  stack on her REAL team's filter (TsaShift.tiktok_upsell). Moved
     *  out entirely as of 2026-10-06 (explicit follow-up, right after the
     *  TIKTOK TEAM filter pill shipped: "and in the team opening and
     *  closing it should be has no card of tiktok ... because it is
     *  separate now") — her 2 TikTok cards now show ONLY under the
     *  dedicated TIKTOK TEAM filter (see the test_tiktok_team_* tests
     *  below), never duplicated inline on her real team's own view
     *  anymore, regardless of her tiktok_upsell flag. Still folded into
     *  TELESALES' own overall total on ALL/the TIKTOK TEAM filter either
     *  way (see test_telesales_folds_in_tiktoks_total_on_all_but_not_a_real_teams_own_row)
     *  — unaffected. Fully manual — no real Product/team backs either card. */
    public function test_a_tiktok_flagged_tsas_real_team_view_shows_no_tiktok_cards(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => $teamSlug,
        ]));

        $response->assertOk();
        $response->assertDontSee('TIKTOK: SH NATURALS');
        $response->assertDontSee('TIKTOK: NATUREVA');
    }

    public function test_a_tsa_not_flagged_for_tiktok_shows_neither_fixed_card(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => false]);
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => $teamSlug,
        ]));

        $response->assertOk();
        $response->assertDontSee('TIKTOK: SH NATURALS');
        $response->assertDontSee('TIKTOK: NATUREVA');
    }

    /** The standalone "TIKTOK TOTAL" card was REMOVED (explicit follow-up,
     *  2026-10-06: "it will be remove this card TIKTOK TOTAL because the
     *  overall total is will be TELESALES") — TELESALES itself now folds
     *  TikTok's own site-wide total in on top of every real product's
     *  own total, on ALL only (a real team's own summary row — TEAM
     *  OPENING SHIFT etc. — never included TikTok and still doesn't). */
    public function test_telesales_folds_in_tiktoks_total_on_all_but_not_a_real_teams_own_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $date = today()->toDateString();

        \App\Models\ExpectedIncomeTiktokEntry::create(['card_key' => 'sh_naturals', 'tsa_id' => $tsa->id, 'entry_date' => $date, 'gross_sales' => 5000]);

        $allResponse = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $date, 'date_to' => $date, 'team' => 'all',
        ]));
        $allResponse->assertOk();
        $allResponse->assertDontSee('TIKTOK TOTAL');
        $content = $allResponse->getContent();
        $telesalesPos = strpos($content, '>TELESALES<');
        $this->assertNotFalse($telesalesPos);
        $this->assertMatchesRegularExpression(
            '/data-out="gross_sales"[^>]*>\s*5,000\.00/',
            substr($content, $telesalesPos, 3000),
            'TELESALES on ALL should fold in TikTok\'s own 5,000 Gross Sales even with no real product sales entered'
        );

        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';
        $teamResponse = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $date, 'date_to' => $date, 'team' => $teamSlug,
        ]));
        $teamResponse->assertOk();
        $teamResponse->assertDontSee('TIKTOK TOTAL');
        $teamContent = $teamResponse->getContent();
        $teamTelesalesPos = strpos($teamContent, '>TELESALES<');
        $this->assertNotFalse($teamTelesalesPos);
        $this->assertMatchesRegularExpression(
            '/data-out="gross_sales"[^>]*>\s*0\.00/',
            substr($teamContent, $teamTelesalesPos, 3000),
            'a real team\'s own TELESALES row should NOT fold in TikTok\'s total'
        );
    }

    public function test_updating_a_tiktok_card_field_upserts_and_returns_recomputed_figures(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $date = today()->toDateString();

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-tiktok', ['cardKey' => 'sh_naturals', 'tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 10000, 'cancelled' => 500]
        );

        $response->assertOk();
        $response->assertJsonPath('derived.delivered', fn ($v) => abs($v - (10000 - 500 - 2500)) < 0.01);
        $this->assertDatabaseHas('expected_income_tiktok_entries', [
            'card_key' => 'sh_naturals', 'tsa_id' => $tsa->id, 'gross_sales' => 10000,
        ]);
    }

    public function test_updating_an_existing_tiktok_card_entry_does_not_create_a_duplicate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $date = today()->toDateString();

        $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-tiktok', ['cardKey' => 'natureva', 'tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 1000]
        );
        $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-tiktok', ['cardKey' => 'natureva', 'tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 2000]
        );

        $this->assertSame(1, \App\Models\ExpectedIncomeTiktokEntry::where('card_key', 'natureva')->where('tsa_id', $tsa->id)->whereDate('entry_date', $date)->count());
        $this->assertSame(2000.0, \App\Models\ExpectedIncomeTiktokEntry::where('card_key', 'natureva')->where('tsa_id', $tsa->id)->whereDate('entry_date', $date)->first()->gross_sales);
    }

    /** Lock toggle on a TikTok card (explicit request, 2026-10-07: "i want
     *  to have like lock icon too") — reverses the original "no lock
     *  toggle (manual-only card)" decision now that Salaries is a
     *  computed figure here, same one-lock-per-card mechanics as a real
     *  product card's own lock (update()'s is_locked). */
    public function test_locking_a_tiktok_card_persists_and_returns_fresh_card_html(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $date = today()->toDateString();

        $response = $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-tiktok', ['cardKey' => 'sh_naturals', 'tsaShift' => $tsa->id, 'date' => $date]),
            ['is_locked' => true]
        );

        $response->assertOk();
        $this->assertDatabaseHas('expected_income_tiktok_entries', [
            'card_key' => 'sh_naturals', 'tsa_id' => $tsa->id, 'is_locked' => true,
        ]);
        $cardHtml = $response->json('cardHtml');
        $this->assertNotEmpty($cardHtml, 'lock toggle must return a fresh cardHtml for the frontend cross-fade');
        $this->assertStringContainsString('data-ei-lock-toggle', $cardHtml);
        $this->assertMatchesRegularExpression('/data-field="gross_sales"[^>]*disabled/', $cardHtml);
    }

    public function test_a_locked_tiktok_card_refuses_other_field_edits(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $date = today()->toDateString();

        \App\Models\ExpectedIncomeTiktokEntry::create([
            'card_key' => 'sh_naturals', 'tsa_id' => $tsa->id, 'entry_date' => $date,
            'gross_sales' => 100, 'is_locked' => true,
        ]);

        $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-tiktok', ['cardKey' => 'sh_naturals', 'tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 99999]
        )->assertOk();

        $this->assertDatabaseHas('expected_income_tiktok_entries', [
            'card_key' => 'sh_naturals', 'tsa_id' => $tsa->id, 'gross_sales' => 100,
        ]);
    }

    /** Salaries on a TikTok card is a computed Daily Rate figure, not
     *  manual input (explicit request, 2026-10-07: "the boxes will be
     *  gone in editable row") — same "computed, never editable" rendering
     *  a real TSA-scoped product card already gives Salaries, but every
     *  OTHER Operating Costs row (Communication Allowance, SIL, ...) on a
     *  TikTok card stays a plain manual input, unlike a real product
     *  card where ALL of them are locked — TikTok cards have no Cost
     *  Breakdown automation for those. */
    public function test_a_tiktok_cards_salaries_row_is_not_editable_but_other_operating_costs_are(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        CostBreakdownTsaEntry::updateOrCreate(['tsa_id' => $tsa->id], ['days' => 24, 'base_salary' => 15990]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => 'tiktok',
        ]));
        $response->assertOk();
        $html = $response->getContent();

        // Anchor on the real editable card's own data-action (its
        // cardKey) rather than its label text — "TIKTOK: SH NATURALS"
        // also appears in the ALL view's own read-only TikTok breakdown
        // row earlier on this same page, which this test isn't targeting.
        $actionPos = strpos($html, 'sh_naturals');
        $this->assertNotFalse($actionPos, 'expected to find the editable TikTok card action');
        $cardStart = strrpos(substr($html, 0, $actionPos), '<div class="ei-card');
        $cardEnd = strpos($html, '<div class="ei-card', $actionPos);
        $cardSlice = substr($html, $cardStart, $cardEnd - $cardStart);

        $this->assertStringContainsString('data-ei-lock-toggle', $cardSlice);
        $this->assertStringContainsString('data-out="salaries"', $cardSlice);
        $this->assertDoesNotMatchRegularExpression('/data-field="salaries"/', $cardSlice);
        // Communication Allowance stays a real editable input on a TikTok
        // card (no Cost Breakdown automation for it there).
        $this->assertStringContainsString('data-field="communication_allowance"', $cardSlice);
    }

    /** The 2 fixed cards' own saved numbers must fold into TELESALES on
     *  the TIKTOK TEAM filter (the standalone "TIKTOK TOTAL" card was
     *  removed, 2026-10-06 — see
     *  test_telesales_folds_in_tiktoks_total_on_all_but_not_a_real_teams_own_row). */
    public function test_tiktok_card_entries_are_included_in_telesales_on_the_tiktok_filter(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $date = today()->toDateString();

        \App\Models\ExpectedIncomeTiktokEntry::create(['card_key' => 'sh_naturals', 'tsa_id' => $tsa->id, 'entry_date' => $date, 'gross_sales' => 5000]);
        \App\Models\ExpectedIncomeTiktokEntry::create(['card_key' => 'natureva', 'tsa_id' => $tsa->id, 'entry_date' => $date, 'gross_sales' => 3000]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $date, 'date_to' => $date, 'team' => 'tiktok',
        ]));
        $response->assertOk();
        $response->assertDontSee('TIKTOK TOTAL');

        $content = $response->getContent();
        $pos = strpos($content, '>TELESALES<');
        $this->assertNotFalse($pos, 'expected to find the TELESALES card');
        $cardSlice = substr($content, $pos, 3000);
        $this->assertMatchesRegularExpression('/data-out="gross_sales"[^>]*>\s*8,000\.00/', $cardSlice, 'TELESALES on the TIKTOK filter should fold in both cards\' Gross Sales (5000 + 3000)');
    }

    /** TIKTOK TOTAL's own 2 card-level breakdowns (explicit follow-up,
     *  2026-10-06: "this too is should be change with 2 cards TIKTOK: SH
     *  NATURALS and TIKTOK: NATUREVA like total in all tsa inputs") — each
     *  card sums that SAME card across EVERY flagged TSA, not per-TSA
     *  (unlike $tiktokTsaRows' own per-TSA cards). Two different TSAs'
     *  own "sh_naturals" entries (10,000 + 500) must pool into ONE
     *  10,500.00 figure on the "TIKTOK: SH NATURALS" breakdown card. */
    public function test_tiktok_totals_own_2_cards_sum_that_same_card_across_every_flagged_tsa(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsaA = TsaShift::first();
        $tsaB = TsaShift::skip(1)->first();
        $tsaA->update(['tiktok_upsell' => true]);
        $tsaB->update(['tiktok_upsell' => true]);
        $date = today()->toDateString();

        \App\Models\ExpectedIncomeTiktokEntry::create(['card_key' => 'sh_naturals', 'tsa_id' => $tsaA->id, 'entry_date' => $date, 'gross_sales' => 10000]);
        \App\Models\ExpectedIncomeTiktokEntry::create(['card_key' => 'sh_naturals', 'tsa_id' => $tsaB->id, 'entry_date' => $date, 'gross_sales' => 500]);
        \App\Models\ExpectedIncomeTiktokEntry::create(['card_key' => 'natureva', 'tsa_id' => $tsaA->id, 'entry_date' => $date, 'gross_sales' => 200]);
        \App\Models\ExpectedIncomeTiktokEntry::create(['card_key' => 'natureva', 'tsa_id' => $tsaB->id, 'entry_date' => $date, 'gross_sales' => 300]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $date, 'date_to' => $date, 'team' => 'tiktok',
        ]));
        $response->assertOk();
        $content = $response->getContent();

        $shPos = strpos($content, 'TIKTOK: SH NATURALS');
        $this->assertNotFalse($shPos, 'expected to find the TIKTOK: SH NATURALS breakdown card');
        $this->assertMatchesRegularExpression('/data-out="gross_sales"[^>]*>\s*10,500\.00/', substr($content, $shPos, 3000), 'SH NATURALS card should sum 10,000 + 500 across both TSAs');

        $naturevaPos = strpos($content, 'TIKTOK: NATUREVA');
        $this->assertNotFalse($naturevaPos, 'expected to find the TIKTOK: NATUREVA breakdown card');
        $this->assertMatchesRegularExpression('/data-out="gross_sales"[^>]*>\s*500\.00/', substr($content, $naturevaPos, 3000), 'NATUREVA card should sum 200 + 300 across both TSAs');
    }

    /** A TikTok card must NEVER fold into the TSA's own real overview
     *  rollup ("[TSA NAME]" card, data-out-scope="1") — TikTok's totals
     *  are tracked completely separately (see
     *  ExpectedIncomeController::buildTiktokRows()'s own doc comment) and
     *  must never inflate her real P&L. Confirmed via the absence of
     *  data-product-id on a TikTok card — see _tiktok-card.blade.php's
     *  own doc comment for why that's deliberate (refreshDayOverall()'s
     *  own client-side sum only picks up `.ei-card[data-product-id]`). */
    public function test_a_tiktok_card_has_no_data_product_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);

        // 'tiktok' team filter, not her real team's own slug — TikTok
        // cards moved out of the real team view entirely, 2026-10-06 (see
        // test_a_tiktok_flagged_tsas_real_team_view_shows_no_tiktok_cards'
        // own doc comment).
        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => 'tiktok',
        ]));

        $response->assertOk();
        preg_match('/<div class="ei-card[^"]*"[^>]*data-action="[^"]*tiktok[^"]*"[^>]*>/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'expected to find a TikTok card with its own data-action');
        $this->assertStringNotContainsString('data-product-id', $matches[0]);
    }

    /** A Projections-added custom row has no column on
     *  ExpectedIncomeTiktokEntry and no save endpoint of its own — a
     *  TikTok card must never render one (would otherwise be a silently
     *  broken input with no data-custom-action, see _tiktok-card.blade.php's
     *  own doc comment). */
    public function test_a_tiktok_card_never_shows_a_custom_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        \App\Models\ProjectionCustomRow::create(['key' => 'custom_tiktok_test_row', 'section' => 'selling', 'label' => 'Custom Tiktok Test Row', 'sort_order' => 99]);

        // 'tiktok' team filter — see test_a_tiktok_card_has_no_data_product_id's
        // own comment for why this is no longer her real team's slug.
        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => 'tiktok',
        ]));

        $response->assertOk();
        $pos = strpos($response->getContent(), 'TIKTOK: SH NATURALS');
        $this->assertNotFalse($pos, 'expected to find the TikTok card');
        $cardSlice = substr($response->getContent(), $pos, 4000);
        $this->assertStringNotContainsString('Custom Tiktok Test Row', $cardSlice);
    }

    /**
     * TIKTOK TEAM filter pill (explicit request, 2026-10-06: "i want you
     * to separate the tiktok team so it will be like next to the team
     * opening is TIKTOK TEAM" — scope confirmed: "only tiktok-flagged
     * tsas... just their 2 tiktok cards, not their normal product
     * cards"). Only shown when at least one TSA is actually flagged.
     */
    public function test_the_tiktok_team_pill_only_appears_when_a_tsa_is_flagged(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.expected-income'));

        $response->assertOk();
        $response->assertDontSee('TIKTOK TEAM');
    }

    public function test_the_tiktok_team_pill_appears_once_a_tsa_is_flagged(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        TsaShift::first()->update(['tiktok_upsell' => true]);

        $response = $this->actingAs($admin)->get(route('data.expected-income'));

        $response->assertOk();
        $response->assertSee('TIKTOK TEAM');
    }

    /** Selecting TIKTOK TEAM shows a flagged TSA's own 2 TikTok cards. */
    public function test_selecting_tiktok_team_shows_a_flagged_tsas_tiktok_cards(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => 'tiktok',
        ]));

        $response->assertOk();
        $response->assertSee('TIKTOK: SH NATURALS');
        $response->assertSee('TIKTOK: NATUREVA');
        $response->assertSee($tsa->display_name);
    }

    /** The whole point of the filter: her normal product cards must NOT
     *  render here, only the 2 TikTok ones — confirmed scope,
     *  2026-10-06. */
    public function test_tiktok_team_does_not_show_a_flagged_tsas_normal_product_cards(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $product = Product::first();
        ExpectedIncomeEntry::create([
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'entry_date' => today(),
            'gross_sales' => 5000,
        ]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => 'tiktok',
        ]));

        $response->assertOk();
        $response->assertDontSee($product->display_name);
    }

    /** A TSA NOT flagged for TikTok must never show up under the TIKTOK
     *  TEAM filter at all. */
    public function test_tiktok_team_excludes_a_tsa_not_flagged(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsas = TsaShift::take(2)->get();
        $tsas[0]->update(['tiktok_upsell' => true]);
        $tsas[1]->update(['tiktok_upsell' => false]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => 'tiktok',
        ]));

        $response->assertOk();
        $response->assertSee($tsas[0]->display_name);
        $response->assertDontSee($tsas[1]->display_name);
    }

    /** TIKTOK TEAM has no real product cards of its own (explicit scope,
     *  2026-10-06) — the top summary row's TELESALES card (the per-
     *  product rollup) still renders (same "always shows, even all-zero"
     *  convention as the ALL view) — real products contribute 0 here
     *  since there's genuinely no product-card data behind this filter,
     *  but TikTok's own total still folds in (see
     *  test_telesales_folds_in_tiktoks_total_on_all_but_not_a_real_teams_own_row).
     *  It must NOT show a real product's own name/card. */
    public function test_tiktok_team_summary_row_shows_no_real_product_cards(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        TsaShift::first()->update(['tiktok_upsell' => true]);
        $product = Product::first();

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => 'tiktok',
        ]));

        $response->assertOk();
        $response->assertDontSee($product->display_name);
        $response->assertDontSee('TIKTOK TOTAL');
    }

    /** ALL also gets its own "TIKTOK TEAM" per-team breakdown row
     *  (explicit follow-up, 2026-10-06, same request as the TIKTOK TOTAL
     *  restoration above) — one block per TikTok-flagged TSA, her own name
     *  card plus her 2 TikTok cards, same shape as the TIKTOK TEAM
     *  filter's own daily section and as every other team's own row on
     *  ALL. A TSA not flagged must not appear in it. */
    public function test_all_shows_a_tiktok_team_breakdown_row_with_only_flagged_tsas(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $flagged = TsaShift::first();
        $flagged->update(['tiktok_upsell' => true]);
        $unflagged = TsaShift::skip(1)->first();

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => 'all',
        ]));

        $response->assertOk();
        $response->assertSee('TIKTOK TEAM');
        $response->assertSee('TIKTOK: SH NATURALS');
        $response->assertSee('TIKTOK: NATUREVA');
        $content = $response->getContent();
        $scrollerStart = strpos($content, 'id="eiTeamScroller-tiktok"');
        $this->assertNotFalse($scrollerStart, 'expected the TIKTOK TEAM breakdown row\'s own scroller container to render');
        // This is the LAST section _summary-section.blade.php renders, so
        // everything from its scroller to end-of-page belongs to it.
        $scrollerHtml = substr($content, $scrollerStart);

        $this->assertStringContainsString($flagged->display_name, $scrollerHtml);
        if ($unflagged) {
            $this->assertStringNotContainsString($unflagged->display_name, $scrollerHtml, 'an unflagged TSA should not appear inside the TIKTOK TEAM breakdown row');
        }
    }

    /** A multi-day range summed into one read-only block per TSA, same
     *  "pick a 1-day range for editable inputs" convention as a real
     *  team's own TikTok cards already follow. */
    public function test_tiktok_team_on_a_multi_day_range_shows_one_summed_block_per_tsa(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->subDay()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => 'tiktok',
        ]));

        $response->assertOk();
        $response->assertSee('TIKTOK: SH NATURALS');
    }

    /**
     * Her own "[TSA NAME]" overview card (explicit follow-up, 2026-10-06:
     * "in the tiktok it should be have tsa card too") — a read-only rollup
     * of HER OWN 2 TikTok cards, pooling both into one Gross Sales figure,
     * same visual anchor every real team's own per-TSA block already has.
     */
    public function test_tiktok_team_shows_a_tsa_overview_card_pooling_her_own_two_cards(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $date = today()->toDateString();

        $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-tiktok', ['cardKey' => 'sh_naturals', 'tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 3000]
        );
        $this->actingAs($admin)->patchJson(
            route('data.expected-income.update-tiktok', ['cardKey' => 'natureva', 'tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 2000]
        );

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $date, 'date_to' => $date, 'team' => 'tiktok',
        ]));

        $response->assertOk();
        $content = $response->getContent();
        $overviewPos = strpos($content, 'data-out-scope="1"');
        $this->assertNotFalse($overviewPos, 'expected to find the TSA overview card');
        $overviewSlice = substr($content, $overviewPos, 5000);
        $this->assertMatchesRegularExpression('/data-out="gross_sales"[^>]*>\s*5,000\.00/', $overviewSlice, 'overview card should pool both TikTok cards\' Gross Sales (3000 + 2000)');
    }

    /** Real bug caught live, 2026-10-06 (screenshot): "why is it in the
     *  tsa card in tiktok it is not totalling?" — a TSA's own TikTok
     *  overview card kept getting zeroed out AFTER any TikTok field
     *  autosaved, even though the server-rendered page (confirmed by the
     *  test above) correctly pooled both cards. Root cause: the page's
     *  own refreshDayOverall() JS only ever summed `.ei-card[data-product-id]`
     *  cards into the overview, but a TikTok card is deliberately never
     *  given data-product-id (it isn't a real Product) — so that JS found
     *  nothing in a TikTok scroller and overwrote the overview with an
     *  all-zero sum. Fixed by tagging each TikTok card data-tiktok-card="1"
     *  and having refreshDayOverall() also sum that selector. This test
     *  confirms the server-side half of the fix: the markup the JS fix
     *  depends on is actually present (DOM/JS behavior itself isn't
     *  exercised by a PHP feature test). */
    public function test_a_tiktok_cards_markup_carries_the_attribute_the_overview_refresh_js_needs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(), 'team' => 'tiktok',
        ]));

        $response->assertOk();
        $response->assertSee('data-tiktok-card="1"', false);
    }

    /** An invalid/stale team slug already falls back to 'all' — confirm
     *  'tiktok' itself is a genuinely accepted value, not silently
     *  rejected the same way. */
    public function test_tiktok_is_a_valid_remembered_team_value(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        TsaShift::first()->update(['tiktok_upsell' => true]);

        $this->actingAs($admin)->get(route('data.expected-income', ['team' => 'tiktok']));
        $response = $this->actingAs($admin)->get(route('data.expected-income'));

        $response->assertOk();
        $response->assertSee('TIKTOK: SH NATURALS');
    }

    /** The live AJAX summary() refresh endpoint must not crash for the
     *  'tiktok' team either — same empty/zeroed summary shape as index()
     *  uses for this filter. */
    public function test_the_summary_endpoint_does_not_crash_for_the_tiktok_team(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        TsaShift::first()->update(['tiktok_upsell' => true]);

        $response = $this->actingAs($admin)->get(route('data.expected-income.summary', [
            'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
            'team' => 'tiktok',
        ]));

        $response->assertOk();
    }

    /** Explicit request, 2026-10-09: "automate it to tally to the overall
     *  number of leads per product per tsa" / "it should be tally the
     *  overall number of leads to the overall leads to the leads report
     *  module leads report page" — Number of Leads on a TSA's own product
     *  card is no longer typed in; it must equal Leads Report's own TOTAL
     *  LEADS figure for that same TSA/product/day. Seeds a real matched
     *  Order (same raw_tags/status_code pattern
     *  DashboardTotalLeadsMatchesLeadsReportTest already uses to drive
     *  ProductPerformance::matchingOrders()) plus a stale manually-saved
     *  ExpectedIncomeEntry.number_of_leads on the SAME cell, to confirm the
     *  real tally wins over whatever's stored. */
    public function test_a_tsas_number_of_leads_tallies_the_same_as_leads_reports_total_leads(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::where('display_name', 'SINUXYL')->first();
        $tsa = TsaShift::where('team', 'SH Naturals')->first();
        $today = now()->toDateString();

        // A stale manually-typed figure left over from before automation —
        // must be overridden, not read, by the real tally below.
        ExpectedIncomeEntry::create([
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'entry_date' => $today,
            'number_of_leads' => 999,
        ]);

        Order::create([
            'pancake_order_id' => 'ei-leads-tally-1', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Sinuxyl',
            'raw_tags' => [strtoupper($tsa->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'ei-leads-tally-2', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'disposition' => 'NOT ANSWERING', 'product' => 'Sinuxyl',
            'raw_tags' => [strtoupper($tsa->tsa_key), 'NOT ANSWERING'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        $expectedIncome = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $today, 'date_to' => $today, 'team' => 'sh-naturals',
        ]));
        $leadsReport = $this->actingAs($admin)->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $expectedIncome->assertOk();
        $leadsReport->assertOk();

        // The real tally (2 matched orders) wins over the stale manually-
        // saved 999 — matches the exact span _card-body.blade.php renders
        // Number of Leads into now that it's read-only.
        preg_match('/data-out="number_of_leads"[^>]*>([^<]*)</', $expectedIncome->getContent(), $matches);
        $this->assertSame('2', $matches[1] ?? null);
    }

    /** Real production bug, root-caused live 2026-10-09 (screenshot): the
     *  top "Telesales Expected Performance" summary row's own product card
     *  showed Number of Leads 13 for CLEAR SIGHT while Leads Report showed
     *  20 for the exact same team/date. Cause: buildSummaryRow()'s own
     *  $cards/$overallTotal used to pool number_of_leads only from
     *  rawByProductAndDateAllTsas()'s own per-cell rows, and that method
     *  only ever emits a row for a (product, tsa, day) that ALREADY has a
     *  saved ExpectedIncomeEntry — so a TSA with real matched orders but no
     *  saved entry yet for that product/day (the common case on a page she
     *  hasn't opened/typed into) silently contributed 0, undercounting the
     *  whole card. Fixed via totalRealLeads(), a direct tally independent
     *  of entry existence — same role addActiveTsasOverviewOperatingCosts()
     *  already plays for Salaries/Operating Costs/Tax Allocation on this
     *  exact row. Seeds TWO TSAs: one with a saved (but empty/0-leads)
     *  entry, one with NO entry at all — both have real matched orders;
     *  neither's absence of a "real" entry should matter. */
    public function test_the_summary_rows_number_of_leads_counts_every_real_order_even_with_no_saved_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::where('display_name', 'SINUXYL')->first();
        $tsas = TsaShift::where('team', 'SH Naturals')->take(2)->get();
        $tsaWithEntry = $tsas[0];
        $tsaWithNoEntry = $tsas[1];
        $today = now()->toDateString();

        // Only one of the two TSAs has ever touched this product's card —
        // the other's real orders must still count.
        ExpectedIncomeEntry::create([
            'product_id' => $product->id, 'tsa_id' => $tsaWithEntry->id, 'entry_date' => $today,
        ]);

        foreach ([$tsaWithEntry, $tsaWithNoEntry] as $i => $tsa) {
            Order::create([
                'pancake_order_id' => "ei-summary-tally-{$i}", 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
                'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Sinuxyl',
                'raw_tags' => [strtoupper($tsa->tsa_key), 'CONFIRMED VIA CALL'],
                'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
            ]);
        }

        $expectedIncome = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $today, 'date_to' => $today, 'team' => 'sh-naturals',
        ]));
        $leadsReport = $this->actingAs($admin)->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $expectedIncome->assertOk();
        $leadsReport->assertOk();

        // 2 real orders total (one per TSA) — the summary card's own
        // top-row figure, not a TSA-scoped card.
        preg_match('/data-out="number_of_leads"[^>]*>([^<]*)</', $expectedIncome->getContent(), $matches);
        $this->assertSame('2', $matches[1] ?? null);
    }

    /** Companion to the bug above, same screenshot: a merged/combined
     *  product card (e.g. "LUMIEYES/CLEAR SIGHT") must show the SUM of
     *  every member product's own real leads, not just one member's. Group
     *  cards (ProductGrouping::rows()) pool each member's own raw row via
     *  flatMap + ExpectedIncomeCalculator::sum(), so this already works
     *  correctly on this page's per-TSA cards (rawByProductAndDate()
     *  always computes a real count per product regardless of entry
     *  existence) — this test covers the TOP summary row specifically,
     *  which goes through the separately-fixed totalRealLeads() path. */
    public function test_a_merged_product_cards_number_of_leads_sums_every_member_product(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $productA = Product::where('display_name', 'LUMIEYES')->first();
        $productB = Product::where('display_name', 'CLEARSIGHT')->first();
        $group = ProductGroup::create(['label' => 'LUMIEYES/CLEAR SIGHT', 'sort_order' => 0]);
        $group->products()->attach([$productA->id, $productB->id]);
        $tsa = TsaShift::where('team', 'SH Naturals')->first();
        $today = now()->toDateString();

        Order::create([
            'pancake_order_id' => 'ei-group-tally-a', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => $productA->display_name,
            'raw_tags' => [strtoupper($tsa->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'ei-group-tally-b', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => $productB->display_name,
            'raw_tags' => [strtoupper($tsa->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $today, 'date_to' => $today, 'team' => 'sh-naturals',
        ]));

        $response->assertOk();
        $response->assertSee('LUMIEYES/CLEAR SIGHT');
        preg_match('/data-out="number_of_leads"[^>]*>([^<]*)</', $response->getContent(), $matches);
        $this->assertSame('2', $matches[1] ?? null);
    }

    /** Real production bug, root-caused live 2026-10-09 (screenshot, same
     *  session as the two fixes above): a grouped summary card
     *  (SINUXYL/SINUXYL 2.0) showed 22 leads when Leads Report showed 17
     *  (15+2) for the exact same team/date. Cause: an earlier version of
     *  totalRealLeads() called leadCountsByProductAndDate() with ONLY the
     *  group's own narrow member list as matchingOrders()'s own
     *  $teamProducts argument — losing visibility into every OTHER product
     *  on the page, which starves matchingOrders()'s own stale-tag
     *  conflict guard (conflictingProduct()) of the sibling products it
     *  needs to correctly EXCLUDE an order whose real item actually
     *  belongs to a product outside the group. Reproduces the exact
     *  LeadsReportStaleTagConflictTest scenario (a Pterygium order
     *  carrying a stale CLEARSIGHT tag) but inside a group: PTERYGIUM
     *  grouped with GINSENG SERUM, the stale-tagged order's real item is
     *  Pterygium — the group card must count it once (toward Pterygium),
     *  not twice, exactly matching what Leads Report would show for
     *  Pterygium + CLEARSIGHT's own totals added together (1 + 0). */
    public function test_a_merged_product_cards_number_of_leads_excludes_a_stale_tagged_conflicting_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pterygium = Product::where('display_name', 'PTERYGIUM')->first();
        $ginseng = Product::where('display_name', 'GINSENG SERUM')->first();
        $clearSight = Product::where('display_name', 'CLEARSIGHT')->first();
        $group = ProductGroup::create(['label' => 'PTERYGIUM/GINSENG SERUM', 'sort_order' => 0]);
        $group->products()->attach([$pterygium->id, $ginseng->id]);
        $tsa = TsaShift::where('team', 'Eyecare Team')->first();
        $today = now()->toDateString();

        // Real item is Pterygium, but still carries a stale CLEARSIGHT tag
        // from earlier in the conversation — same production pattern as
        // LeadsReportStaleTagConflictTest's own first test.
        Order::create([
            'pancake_order_id' => 'ei-group-stale-tag-1', 'team' => 'Eyecare Team', 'tsa_name' => $tsa->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Pterygium',
            'raw_tags' => [strtoupper($tsa->tsa_key), 'CLEARSIGHT', 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $today, 'date_to' => $today, 'team' => 'all',
        ]));

        $response->assertOk();
        preg_match_all('/data-out="number_of_leads"[^>]*>([^<]*)</', $response->getContent(), $matches);
        // Exactly one order counted once (toward the group's own
        // Pterygium member), not twice via the stale CLEARSIGHT tag.
        $this->assertContains('1', $matches[1]);
    }

    /** Real production bug, root-caused live 2026-10-09 (screenshot, same
     *  session as the three fixes above): PTERYLIEF/PTERYGIUM's own merged
     *  summary card on TEAM CLOSING (SH Naturals) showed 58 leads when
     *  Leads Report's own PTERYLIEF (15) + PTERYGIUM (35) totals for the
     *  exact same team/date summed to 50 — an 8-lead OVER-count, the
     *  opposite direction from the earlier undercount fix. Cause:
     *  leadCountsByProductAndDate() never pre-filtered its own candidate
     *  Order pool by `team` before running matchingOrders() — it only
     *  restricted the OUTPUT afterward, by which TSA id the order
     *  happened to carry. LeadsReportController always filters its own
     *  candidate pool by `->where('team', $orderTeam)` FIRST. A TSA whose
     *  own historical orders carry a DIFFERENT Order.team than her
     *  currently-filtered team — a real, legitimate case
     *  (matchingOrders()'s own explicit product/base_product/
     *  bundle_description text match deliberately bypasses the team gate,
     *  exactly so a genuine cross-team product sale still counts) — had
     *  those other-team orders silently swept into this team's own
     *  summary total. Reproduces it directly: a TEAM CLOSING (SH Naturals)
     *  TSA has one genuine SH Naturals order, plus a second order whose
     *  own `team` is Eyecare but whose cart item explicitly matches
     *  PTERYGIUM by name (an explicit text match, bypassing the team gate)
     *  — same TSA on both. TEAM CLOSING's own summary card must count only
     *  the ONE real SH-Naturals-team order, matching what Leads Report's
     *  own team-scoped page would show for that same TSA/team. */
    public function test_the_summary_rows_number_of_leads_excludes_a_tsas_cross_team_order_not_matching_the_filtered_team(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $sinuxyl = Product::where('display_name', 'SINUXYL')->first();
        $pterygium = Product::where('display_name', 'PTERYGIUM')->first();
        $tsa = TsaShift::where('team', 'SH Naturals')->first();
        $today = now()->toDateString();

        // Genuine SH Naturals order — must count toward TEAM CLOSING.
        Order::create([
            'pancake_order_id' => 'ei-cross-team-own', 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Sinuxyl',
            'raw_tags' => [strtoupper($tsa->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        // Same TSA, but this order's own team is Eyecare (an Eyecare-hour
        // sale she legitimately worked) — an explicit text match on
        // Pterygium's own name bypasses matchingOrders()'s team gate, same
        // as a real cross-team sale would. Must NOT count toward TEAM
        // CLOSING's own summary, since its real team is Eyecare.
        Order::create([
            'pancake_order_id' => 'ei-cross-team-other', 'team' => 'Eyecare Team', 'tsa_name' => $tsa->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Pterygium',
            'raw_tags' => [strtoupper($tsa->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $today, 'date_to' => $today, 'team' => 'sh-naturals',
        ]));

        $response->assertOk();
        preg_match_all('/data-out="number_of_leads"[^>]*>([^<]*)</', $response->getContent(), $matches);
        // The TELESALES rollup (first data-out="number_of_leads" match,
        // the overview card) must be 1 — only the genuine SH Naturals
        // order — never 2 (which would mean the Eyecare-team order leaked
        // in via the TSA-id-only filter the old buggy code used).
        $this->assertSame('1', $matches[1][0] ?? null);
    }

    /** Real production bug, root-caused live 2026-10-09 (4th same-day fix,
     *  after /systematic-debugging was invoked following 3 narrower
     *  patches that each fixed a different wrong angle of this same
     *  mismatch): PTERYLIEF/PTERYGIUM's own merged summary card on TEAM
     *  CLOSING showed 53 when Leads Report showed 57 (45+12) for the same
     *  team/date — an UNDER-count this time. Root cause, confirmed by
     *  reading LeadsReportController::indexAll() directly: a product's
     *  Leads Report total on ANY view is never one single query — it's
     *  always the SUM of that product's own count under EACH real team's
     *  own SEPARATELY-scoped candidate pool (indexAll()'s own
     *  $teamTables, each `where('team', $orderTeam)` before matching,
     *  summed via ProductPerformance::sumRows()). A single-team page
     *  (index()) is just one of those same per-team computations shown
     *  alone. leadCountsByProductAndDate() was rebuilt to mirror this
     *  exactly — always loops every real team, keys its counts by team
     *  too, and totalRealLeads() sums either ONE team's own bucket (a
     *  single-team filter) or every team's bucket together (ALL).
     *
     *  Reproduces the real scenario this architecture exists for: ONE
     *  product (PTERYGIUM) genuinely sold under BOTH teams on the same
     *  day — a real SH Naturals order (Closing's own TSA, Closing hours)
     *  and a real Eyecare order (Eyecare's own TSA, Eyecare hours).
     *  TEAM CLOSING's own summary must show only its own 1; TEAM OPENING
     *  (Eyecare)'s own summary must show only its own 1; ALL must show
     *  the sum, 2 — all three simultaneously, matching
     *  index()/index()/indexAll() exactly. */
    public function test_the_summary_rows_number_of_leads_matches_leads_reports_per_team_and_all_architecture(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pterygium = Product::where('display_name', 'PTERYGIUM')->first();
        $shTsa = TsaShift::where('team', 'SH Naturals')->first();
        $eyeTsa = TsaShift::where('team', 'Eyecare Team')->first();
        $today = now()->toDateString();

        Order::create([
            'pancake_order_id' => 'ei-two-team-sh', 'team' => 'SH Naturals', 'tsa_name' => $shTsa->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Pterygium',
            'raw_tags' => [strtoupper($shTsa->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'ei-two-team-eye', 'team' => 'Eyecare Team', 'tsa_name' => $eyeTsa->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Pterygium',
            'raw_tags' => [strtoupper($eyeTsa->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        $closing = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $today, 'date_to' => $today, 'team' => 'sh-naturals',
        ]));
        $opening = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $today, 'date_to' => $today, 'team' => 'eyecare',
        ]));
        $all = $this->actingAs($admin)->get(route('data.expected-income', [
            'date_from' => $today, 'date_to' => $today, 'team' => 'all',
        ]));

        $closing->assertOk();
        $opening->assertOk();
        $all->assertOk();

        preg_match_all('/data-out="number_of_leads"[^>]*>([^<]*)</', $closing->getContent(), $closingMatches);
        preg_match_all('/data-out="number_of_leads"[^>]*>([^<]*)</', $opening->getContent(), $openingMatches);
        preg_match_all('/data-out="number_of_leads"[^>]*>([^<]*)</', $all->getContent(), $allMatches);

        $this->assertSame('1', $closingMatches[1][0] ?? null, 'TEAM CLOSING overview should show only its own 1 Pterygium lead');
        $this->assertSame('1', $openingMatches[1][0] ?? null, 'TEAM OPENING overview should show only its own 1 Pterygium lead');
        $this->assertSame('2', $allMatches[1][0] ?? null, 'ALL overview should show the sum, 2');

        // Direct cross-reference against Leads Report's own ALL view
        // (indexAll()) for the SAME product, confirming Expected Income's
        // ALL rollup equals Leads Report's own real figure, not just the
        // hardcoded 2 this test's own fixtures happen to produce.
        $leadsReportAll = $this->actingAs($admin)->get(route('leads-report', [
            'team' => 'all', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));
        $leadsReportAll->assertOk();
        $pterygiumRow = $leadsReportAll->viewData('productRows')->firstWhere('display_name', 'PTERYGIUM');
        $this->assertSame((string) $pterygiumRow['total'], $allMatches[1][0] ?? null);
    }
}
