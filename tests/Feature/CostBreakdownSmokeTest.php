<?php

namespace Tests\Feature;

use App\Models\CostBreakdownPool;
use App\Models\CostBreakdownRole;
use App\Models\CostBreakdownTsaEntry;
use App\Models\TsaShift;
use App\Models\User;
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
        // "Daily Rate / Product" (explicit request, 2026-09-30: "add
        // anothet column next to Daily Rate (÷24) is like divided be all
        // product ... how many product in the cards") — her own Daily
        // Rate split evenly across every product CARD, same card count
        // Expected Income's own product cards show.
        $productCount = \App\Support\ProductGrouping::rows(\App\Models\Product::orderBy('team')->orderBy('sort_order')->get(), fn () => null)->count();
        $response->assertJsonPath('dailyRatePerProduct', fn ($v) => abs($v - (1682.86 / $productCount)) < 0.01);
    }

    /** "Daily Rate / Product" column (explicit request, 2026-09-30) renders
     *  on the page itself — header text plus the correctly divided value
     *  for a real TSA's own Daily Rate. */
    public function test_the_salary_table_shows_daily_rate_per_product(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        $tsa = TsaShift::first();
        CostBreakdownTsaEntry::where('tsa_id', $tsa->id)->update(['base_salary' => 19500.00]);

        $response = $this->actingAs($admin)->get(route('data.cost-breakdown'));

        $response->assertOk();
        $response->assertSee('Daily Rate / Product');

        $productCount = \App\Support\ProductGrouping::rows(\App\Models\Product::orderBy('team')->orderBy('sort_order')->get(), fn () => null)->count();
        $this->assertGreaterThan(0, $productCount);
    }

    public function test_a_non_admin_cannot_update_any_cost_breakdown_field(): void
    {
        $user = User::factory()->create(['role' => 'normal']);
        CostBreakdownRole::ensureSeeded();
        CostBreakdownPool::ensureSeeded();
        $role = CostBreakdownRole::first();
        $pool = CostBreakdownPool::first();
        $tsa = TsaShift::first();

        $this->actingAs($user)->patchJson(route('data.cost-breakdown.update-role', $role), ['base_salary' => 1])->assertForbidden();
        $this->actingAs($user)->patchJson(route('data.cost-breakdown.update-pool', $pool), ['amount' => 1])->assertForbidden();
        $this->actingAs($user)->patchJson(route('data.cost-breakdown.update-tsa-entry', $tsa), ['days' => 1])->assertForbidden();
    }
}
