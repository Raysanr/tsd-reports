<?php

namespace Tests\Feature;

use App\Models\CostBreakdownPool;
use App\Models\CostBreakdownRole;
use App\Models\CostBreakdownTsaEntry;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\CostBreakdownCalculator;
use App\Support\TsaDailyRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TSD Data Management — Cost Breakdown (explicit request, 2026-09-29: "add
 * new page in data management (COST BREAKDOWN) ... like this sheets", a
 * real "TSD Payroll" spreadsheet). Smoke-level only — confirms the page
 * renders and every auto-save endpoint persists/recomputes correctly; the
 * derived formulas themselves are independently verified against the real
 * sheet's own raw CSV export in CostBreakdownCalculatorTest.
 */
class CostBreakdownSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_the_report_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.cost-breakdown'));

        $response->assertOk();
        $response->assertSee('CEO');
        $response->assertSee('TOTAL SALARY OF TSD');
        $response->assertSee('Cost Allocation Per TSA');
    }

    /** Explicit follow-up, 2026-09-29: "the supervisor of opening and
     *  closing is in the rows of their TSA's" — each shift's own
     *  Supervisor renders immediately above her own team's real TSAs, in
     *  ONE continuous table, not as separate sections. */
    public function test_each_supervisor_is_immediately_followed_by_her_own_teams_tsas(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.cost-breakdown'));

        $response->assertOk();
        // Eyecare Team = Opening (TeamShiftWindow's own OPENING_TEAM) —
        // its own real TSAs (Julie, Joana, Marisol) appear after the
        // Opening Supervisor, before the Closing Supervisor's own name.
        $response->assertSeeInOrder([
            'Telesales Supervisor (Opening Shift)',
            'Julie',
            'Telesales Supervisor (Closing Shift)',
            'Gemma De Guzman',
        ]);
    }

    /** The CEO/Sales Director/Telesales Manager group's own overhead-per-
     *  TSA figure (confirmed a LIVE FORMULA in the real sheet, 2026-09-29:
     *  "look at this formula ... it is all divided of all 12 tsa" — their
     *  own combined base_salary ÷ the sheet's OWN fixed 12-TSA headcount,
     *  explicit decision 2026-09-29 to always match the sheet's own
     *  numbers rather than this app's own smaller real roster) must never
     *  be duplicated onto the rows it visually spans — those render with
     *  NO overhead figure of their own at all (a real HTML rowspan covers
     *  them instead). */
    public function test_the_executive_groups_overhead_figure_is_not_duplicated_onto_the_rows_it_spans(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();

        $response = $this->actingAs($admin)->get(route('data.cost-breakdown'));

        $response->assertOk();
        // (44,095.59 + 19,998.00 + 60,000.00) ÷ 12 (sheet's own fixed
        // total headcount) = 10,341.13, matching the sheet exactly.
        $expected = number_format((44095.59 + 19998.00 + 60000.00) / 12, 2);
        // Appears exactly once on the page (the CEO's own rowspan cell),
        // not once per row it visually spans.
        $content = $response->getContent();
        $this->assertSame(1, substr_count($content, $expected));
    }

    public function test_a_non_admin_cannot_view_the_report_page(): void
    {
        $tsaUser = User::factory()->create(['role' => 'normal']);

        $response = $this->actingAs($tsaUser)->get(route('data.cost-breakdown'));

        $response->assertForbidden();
    }

    public function test_page_self_heals_when_tables_are_empty(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::query()->delete();
        CostBreakdownPool::query()->delete();
        CostBreakdownTsaEntry::query()->delete();

        $response = $this->actingAs($admin)->get(route('data.cost-breakdown'));

        $response->assertOk();
        $this->assertSame(7, CostBreakdownRole::count());
        $this->assertSame(21, CostBreakdownPool::count());
    }

    /** Regression test, 2026-09-29: a dev database that already had these
     *  7 roles from before team/overhead_group existed (or before their
     *  own seed values changed) silently stayed stuck on the OLD values
     *  forever — ensureSeeded()'s earlier "only touch an empty table"
     *  version never re-synced an already-existing row. Confirms
     *  ensureSeeded() now re-syncs those structural fields even when the
     *  table is already populated, without touching base_salary (the one
     *  field a real admin edit could be sitting in). */
    public function test_ensure_seeded_resyncs_structural_fields_on_an_already_populated_table(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();

        // Simulate the exact stale state found live: overhead_group/team
        // reset to their old pre-fix values, and a real admin edit sitting
        // in base_salary that must survive the resync untouched.
        $ceo = CostBreakdownRole::where('label', 'CEO')->firstOrFail();
        $ceo->update(['overhead_group' => null, 'base_salary' => 99999.99]);
        $openingSupervisor = CostBreakdownRole::where('label', 'Telesales Supervisor (Opening Shift)')->firstOrFail();
        $openingSupervisor->update(['team' => null]);

        $response = $this->actingAs($admin)->get(route('data.cost-breakdown'));

        $response->assertOk();
        $this->assertSame('executive', $ceo->fresh()->overhead_group);
        $this->assertEquals(99999.99, $ceo->fresh()->base_salary);
        $this->assertSame('Eyecare Team', $openingSupervisor->fresh()->team);
    }

    public function test_updating_a_roles_field_persists_and_returns_its_total(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        $ceo = CostBreakdownRole::where('label', 'CEO')->firstOrFail();

        $response = $this->actingAs($admin)->patchJson(
            route('data.cost-breakdown.update-role', $ceo),
            ['base_salary' => 50000]
        );

        $response->assertOk();
        $this->assertEquals(50000, $ceo->fresh()->base_salary);
        // Base Salary IS the role's own Total now (no separate bonus field
        // anywhere — explicit follow-up, 2026-09-29: "there's no bonus on
        // the sheets"). Her own overhead-per-TSA reference figure is purely
        // informational and never added in.
        $response->assertJsonPath('total', fn ($v) => abs($v - 50000) < 0.01);
    }

    /** Explicit follow-up, 2026-09-29: "look at this formula ... it is all
     *  divided of all 12 tsa" — the CEO/Sales Director/Telesales Manager's
     *  own overhead-per-TSA figure is a LIVE FORMULA (their combined
     *  base_salary ÷ the sheet's OWN fixed 12-TSA headcount, explicit
     *  decision 2026-09-29 to match the sheet exactly), so editing the
     *  CEO's own base_salary must recompute and return every role's own
     *  freshly-updated overhead figure, not just her own row. */
    public function test_updating_a_roles_base_salary_recomputes_its_whole_overhead_groups_figure(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        $ceo = CostBreakdownRole::where('label', 'CEO')->firstOrFail();
        $salesDirector = CostBreakdownRole::where('label', 'Sales Director')->firstOrFail();
        $telesalesManager = CostBreakdownRole::where('label', 'Telesales Manager')->firstOrFail();

        $response = $this->actingAs($admin)->patchJson(
            route('data.cost-breakdown.update-role', $ceo),
            ['base_salary' => 50000]
        );

        $response->assertOk();
        // 50,000 (just-edited) + Sales Director's own + Telesales
        // Manager's own base salaries ÷ 12 (sheet's own fixed total
        // headcount) — both the CEO's own row AND the Sales Director's own
        // row (same overhead_group, no base_salary of her own changed)
        // must show the SAME freshly-recomputed figure.
        $expected = (50000 + $salesDirector->base_salary + $telesalesManager->base_salary) / 12;
        $response->assertJsonPath("recomputedOverhead.{$ceo->id}", fn ($v) => abs($v - $expected) < 0.01);
        $response->assertJsonPath("recomputedOverhead.{$salesDirector->id}", fn ($v) => abs($v - $expected) < 0.01);
    }

    public function test_updating_a_pools_amount_recomputes_every_tsas_own_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownPool::ensureSeeded();
        // Every real TSA in the app's own roster starts at the default 30
        // days (recomputeAllTsaRows()'s own `?? 30` fallback) whether or
        // not she has a real CostBreakdownTsaEntry row yet — confirmed
        // live here so this test isn't assuming only 2 TSAs exist.
        $tsaCount = TsaShift::count();
        $tsaA = TsaShift::first();

        $pool = CostBreakdownPool::where('key', 'communication_allowance')->firstOrFail();

        $response = $this->actingAs($admin)->patchJson(
            route('data.cost-breakdown.update-pool', $pool),
            ['amount' => 1200]
        );

        $response->assertOk();
        $this->assertEquals(1200, $pool->fresh()->amount);
        // Every TSA has an equal 30-day share, so each gets 1200 ÷ tsaCount.
        $expectedShare = 1200 / $tsaCount;
        $response->assertJsonPath("recomputed.{$tsaA->id}.derived.communication_allowance", fn ($v) => abs($v - $expectedShare) < 0.01);
    }

    public function test_a_tsa_with_fewer_days_gets_a_smaller_share_and_everyone_else_gets_more(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownPool::ensureSeeded();
        $tsas = TsaShift::orderBy('id')->get();
        foreach ($tsas as $tsa) {
            CostBreakdownTsaEntry::create(['tsa_id' => $tsa->id, 'days' => 30]);
        }
        $tsaA = $tsas->first();
        $tsaB = $tsas->skip(1)->first();
        $otherCount = $tsas->count() - 1;

        // tsaA only worked 10 days this month, everyone else stays at 30.
        $response = $this->actingAs($admin)->patchJson(
            route('data.cost-breakdown.update-tsa-entry', $tsaA),
            ['days' => 10]
        );

        $response->assertOk();
        $this->assertSame(10, CostBreakdownTsaEntry::where('tsa_id', $tsaA->id)->first()->days);

        // Total days now 10 + (30 × the other TSAs). tsaA's share drops to
        // 10/total; every OTHER TSA's own share rises to 30/total (up from
        // her original 30/(30×tsaCount) share).
        $totalDays = 10 + (30 * $otherCount);
        $response->assertJsonPath("recomputed.{$tsaA->id}.share", fn ($v) => abs($v - (10 / $totalDays)) < 0.001);
        $response->assertJsonPath("recomputed.{$tsaB->id}.share", fn ($v) => abs($v - (30 / $totalDays)) < 0.001);
    }

    public function test_updating_a_tsas_salary_fields_persists_and_returns_totals(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        CostBreakdownPool::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        $tsa = TsaShift::first();

        // base_salary is her RAW base only (explicit reversal, 2026-09-30,
        // from the user's own formula-bar screenshot: "=D5+D9+D12+C13") —
        // her own returned 'total' is base_salary + every applicable
        // overhead ref (executive + support + her own team's Supervisor),
        // confirmed exact: 19,500.00 + (10,341.13 + 4,833.33 + 5,714.29) =
        // 40,388.75, matching Julie Francisco's own real sheet total.
        $response = $this->actingAs($admin)->patchJson(
            route('data.cost-breakdown.update-tsa-entry', $tsa),
            ['base_salary' => 19500.00]
        );

        $response->assertOk();
        $this->assertDatabaseHas('cost_breakdown_tsa_entries', [
            'tsa_id' => $tsa->id,
            'base_salary' => 19500.00,
        ]);
        $response->assertJsonPath('total', fn ($v) => abs($v - 40388.75) < 0.01);
        // Daily rate divides her own TOTAL (not her raw base) by 24 —
        // 40,388.75 ÷ 24 = 1,682.86, matching the real sheet's own numbers.
        $response->assertJsonPath('dailyRate', fn ($v) => abs($v - 1682.86) < 0.01);
        // "Daily Rate / Product" (explicit request, 2026-09-30) — its own
        // source is the BOTTOM cost-allocation table's row TOTAL (sum of
        // her % share of every shared pool), NOT the 'total'/'dailyRate'
        // above (base_salary + overhead refs) — confirmed against the real
        // sheet's own xlsx formulas (root-caused after an earlier version
        // of this used the wrong total — see TsaDailyRateService's own doc
        // comment). Editing base_salary alone never moves this figure at
        // all, since it has zero bearing on the pool-share total.
        $expected = TsaDailyRateService::perProductByTsaId()[$tsa->id];
        $response->assertJsonPath('dailyRatePerProduct', fn ($v) => abs($v - $expected) < 0.01);
    }

    /** "Daily Rate / Product" column (explicit request, 2026-09-30) renders
     *  on the page itself — header text plus the correctly divided value
     *  for a real TSA's own Daily Rate. */
    public function test_the_salary_table_shows_daily_rate_per_product(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        CostBreakdownPool::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        $tsa = TsaShift::first();
        CostBreakdownTsaEntry::where('tsa_id', $tsa->id)->update(['base_salary' => 19500.00]);
        // Only FLAGGED products divide the cost (explicit follow-up,
        // 2026-09-30: "user only can identify what product that has
        // cost") — nothing is flagged by default, so flag one here to
        // exercise the real figure this test actually checks.
        Product::orderBy('team')->orderBy('sort_order')->first()->update(['has_cost_allocation' => true]);

        $response = $this->actingAs($admin)->get(route('data.cost-breakdown'));

        $response->assertOk();
        // Header renamed, 2026-09-30, to make explicit that only CHECKED
        // products divide the cost (explicit follow-up: "user only can
        // identify what product that has cost").
        $response->assertSee('Daily Rate (÷24) / product that has check');

        // Its own source is the pool-share total (see
        // TsaDailyRateService's own doc comment), not base_salary + her
        // overhead refs — confirm the REAL figure renders somewhere on the
        // page, not just that the column header text exists.
        $expected = TsaDailyRateService::perProductByTsaId()[$tsa->id];
        $this->assertGreaterThan(0, $expected);
        $response->assertSee(number_format($expected, 2));
    }

    /** The Cost Allocation Per TSA table's own product columns (explicit
     *  request, 2026-09-30: "add the products ... analyze the formula in
     *  the sheets") — one column per real product (the app's own roster,
     *  not the sheet's static list), each showing that TSA's own Daily
     *  Rate ÷ product count, confirmed against the real sheet's own xlsx
     *  formulas (AA45=Z45/7, AB45=Z45/7 for Julie Francisco: every product
     *  column repeats the SAME figure). */
    public function test_the_cost_allocation_table_shows_one_column_per_real_product(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownPool::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        $tsa = TsaShift::first();
        $product = Product::orderBy('team')->orderBy('sort_order')->first();
        // Only FLAGGED products divide the cost (explicit follow-up,
        // 2026-09-30) — flag this one so its own column carries a real
        // figure to assert against below.
        $product->update(['has_cost_allocation' => true]);

        $response = $this->actingAs($admin)->get(route('data.cost-breakdown'));

        $response->assertOk();
        $response->assertSee(strtoupper($product->display_name));

        $expected = TsaDailyRateService::perProductByTsaId()[$tsa->id];
        $this->assertGreaterThan(0, $expected);

        $content = $response->getContent();
        // Bound by the BOTTOM cost-allocation table's own row marker
        // (data-tsa-cost-row) specifically — the TOP salary table now also
        // carries a plain data-tsa-id on its own rows (added so its own
        // "Daily Rate / Product" cell can be live-refreshed), and that row
        // comes FIRST in the page, so a bare "data-tsa-id" search would
        // match the wrong table entirely.
        $rowStart = strpos($content, "data-tsa-cost-row data-tsa-id=\"{$tsa->id}\"");
        $rowEnd = strpos($content, '</tr>', $rowStart);
        $rowHtml = substr($content, $rowStart, $rowEnd - $rowStart);

        // Every FLAGGED product column shows the SAME figure for a given
        // TSA (the sheet's own formula never varies per product) — only
        // ONE product is flagged in this test, so exactly one
        // data-out="product" cell should carry it (an unflagged product's
        // own column stays blank instead).
        preg_match_all('/data-out="product"[^>]*>\s*' . preg_quote(number_format($expected, 2), '/') . '/', $rowHtml, $matches);
        $this->assertCount(1, $matches[0]);
    }

    /** "Daily Cost per product" / "Daily Cost" mini-table (explicit
     *  request, 2026-09-30, real sheet screenshot) renders above the
     *  per-TSA rows, using the app's own REAL TSA count as the divisor
     *  (explicit correction, 2026-09-30: "the 12 is number of the tsa"). */
    public function test_the_daily_cost_mini_table_renders_with_the_real_tsa_count(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownPool::ensureSeeded();
        $pool = CostBreakdownPool::where('key', 'communication_allowance')->firstOrFail();
        $tsaCount = TsaShift::count();

        $response = $this->actingAs($admin)->get(route('data.cost-breakdown'));

        $response->assertOk();
        $response->assertSee('Daily Cost per product');
        $response->assertSee('Daily Cost');

        $expected = CostBreakdownCalculator::dailyCostRow([$pool->key => $pool->amount], $tsaCount)[$pool->key];
        $this->assertGreaterThan(0, $expected);
        $response->assertSee(number_format($expected, 2));
    }

    /** An unflagged product's own column shows a genuinely BLANK cell
     *  (explicit follow-up, 2026-09-30: "user only can identify what
     *  product that has cost") — never a typed 0.00, same "never a typed
     *  0" state as the real sheet's own unfilled columns. */
    public function test_an_unflagged_products_own_column_is_blank(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownPool::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        $tsa = TsaShift::first();
        $unflagged = Product::orderBy('team')->orderBy('sort_order')->first();
        // Deliberately left unflagged (has_cost_allocation defaults false).

        $response = $this->actingAs($admin)->get(route('data.cost-breakdown'));

        $response->assertOk();
        $content = $response->getContent();
        $rowStart = strpos($content, "data-tsa-cost-row data-tsa-id=\"{$tsa->id}\"");
        $rowEnd = strpos($content, '</tr>', $rowStart);
        $rowHtml = substr($content, $rowStart, $rowEnd - $rowStart);

        $this->assertMatchesRegularExpression(
            '/data-out="product" data-product-label="' . preg_quote($unflagged->display_name, '/') . '"[^>]*>\s*</',
            $rowHtml
        );
    }

    /** Toggling a product's own "has cost" checkbox (explicit request,
     *  2026-09-30) persists the flag and returns every real TSA's own
     *  freshly-recomputed product columns — the divisor itself changed. */
    public function test_toggling_a_products_has_cost_checkbox_persists_and_recomputes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownPool::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        $product = Product::orderBy('team')->orderBy('sort_order')->first();
        $tsa = TsaShift::first();

        $response = $this->actingAs($admin)->patchJson(
            route('data.cost-breakdown.update-product-has-cost-allocation', $product),
            ['has_cost_allocation' => true]
        );

        $response->assertOk();
        $this->assertTrue($product->fresh()->has_cost_allocation);
        $response->assertJsonPath('hasCostAllocation', true);

        $expected = TsaDailyRateService::perProductByTsaId()[$tsa->id];
        $this->assertGreaterThan(0, $expected);
        $response->assertJsonPath("recomputed.{$tsa->id}.derived.products.{$product->display_name}", fn ($v) => abs($v - $expected) < 0.01);
    }

    public function test_a_non_admin_cannot_update_any_cost_breakdown_field(): void
    {
        $user = User::factory()->create(['role' => 'normal']);
        CostBreakdownRole::ensureSeeded();
        CostBreakdownPool::ensureSeeded();
        $role = CostBreakdownRole::first();
        $pool = CostBreakdownPool::first();
        $tsa = TsaShift::first();
        $product = Product::first();

        $this->actingAs($user)->patchJson(route('data.cost-breakdown.update-role', $role), ['base_salary' => 1])->assertForbidden();
        $this->actingAs($user)->patchJson(route('data.cost-breakdown.update-pool', $pool), ['amount' => 1])->assertForbidden();
        $this->actingAs($user)->patchJson(route('data.cost-breakdown.update-tsa-entry', $tsa), ['days' => 1])->assertForbidden();
        $this->actingAs($user)->patchJson(route('data.cost-breakdown.update-product-has-cost-allocation', $product), ['has_cost_allocation' => true])->assertForbidden();
    }
}
