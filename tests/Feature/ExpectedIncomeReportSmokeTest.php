<?php

namespace Tests\Feature;

use App\Models\CostBreakdownPool;
use App\Models\CostBreakdownRole;
use App\Models\CostBreakdownTsaEntry;
use App\Models\ExpectedIncomeEntry;
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
     *  team's own OVERVIEW-card figure (her TOTAL ÷ 24 ÷ flagged-product-
     *  count, undivided a second time), once each, REGARDLESS of whether
     *  she has any saved entry at all (explicit correction, 2026-10-02 —
     *  reverses this test's own earlier "active = has an entry" premise:
     *  confirmed live, 6 real TSAs with ZERO saved entries between them
     *  still each contribute their own real staffing cost). Flags 2
     *  products but gives only ONE TSA an entry on only ONE of them — the
     *  rollup must still total ALL of the team's real TSAs regardless. */
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
        $perProductByTsaId = TsaDailyRateService::perProductByTsaId();
        $expectedSalaries = $teamTsaIds->sum(fn ($id) => $perProductByTsaId[$id] ?? 0.0);
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
        $teamTsaIds = TsaShift::where('team', $tsa->team)->pluck('id');
        $perProductByTsaId = \App\Support\TsaDailyRateService::perProductByTsaId();
        $expectedRollupSalaries = number_format($teamTsaIds->sum(fn ($id) => ($perProductByTsaId[$id] ?? 0.0) * 4), 2);
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
        // own label through the next card's).
        $productCardStart = strpos($summaryHtml, $product->display_name);
        $productCardHtml = substr($summaryHtml, $productCardStart, 20000);
        $perProductByTsaIdTwice = \App\Support\TsaDailyRateService::perProductByTsaIdTwice();
        $expectedProductCardSalaries = number_format($teamTsaIds->sum(fn ($id) => ($perProductByTsaIdTwice[$id] ?? 0.0) * 4), 2);
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

    /** Salaries goes through the SAME two-tier division the pool rows
     *  already follow (explicit correction, 2026-10-01: "the 243.31 is
     *  the TSA card and in the products, it should be 243.31 / 7 like the
     *  other costs") — her overview card shows Daily Rate / Product
     *  (e.g. 243.31), and each individual product card divides THAT
     *  figure again by product count (e.g. 243.31 ÷ 7 ≈ 34.76), same
     *  shape as dailyCostRow() → dailyCostPerProductRow(). */
    public function test_product_cards_divide_salaries_a_second_time_past_the_tsa_overview_card(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        Product::orderBy('id')->take(2)->get()->each(fn (Product $p) => $p->update(['has_cost_allocation' => true]));
        $tsa = TsaShift::first();
        $teamSlug = $tsa->team === 'SH Naturals' ? 'sh-naturals' : 'eyecare';

        $overviewFigure = TsaDailyRateService::perProductByTsaId()[$tsa->id];
        $productCardFigure = TsaDailyRateService::perProductByTsaIdTwice()[$tsa->id];
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

    /** Tax Allocation goes through the SAME two-tier division Salaries
     *  already follows — her overview card shows the undivided per-team
     *  Daily Tax figure, and each individual product card divides THAT
     *  figure again by product count, same shape as
     *  TsaDailyRateService::perProductByTsaId() → perProductByTsaIdTwice(). */
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
}
