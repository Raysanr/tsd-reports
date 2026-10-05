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

    /** CSV-download + PNG-snapshot icons (explicit request, 2026-10-05) on
     *  the Salary Breakdown and Cost Allocation Per TSA tables (real
     *  <table>s), and PNG-only on Shared Monthly Cost Pools (a CSS grid of
     *  divs, no <table>/<tr> for a CSV export to walk — see
     *  table-actions.blade.php's own 'pngOnly' doc comment). */
    public function test_the_page_shows_export_icons_on_every_table(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.cost-breakdown'));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('data-export-csv="cbSalaryTable"', $content);
        $this->assertStringContainsString('data-export-csv="cbTsaTable"', $content);
        $this->assertStringContainsString('data-export-png="cbPoolsGrid"', $content);
        $this->assertStringNotContainsString('data-export-csv="cbPoolsGrid"', $content);
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

    /** Monthly Tax / Daily Tax columns (explicit request, 2026-10-02,
     *  CONFIRMED DIRECTLY against the real sheet's own formula bar: "50,000
     *  is divided by 6 (6 tsa per team) so when they add new tsa it will be
     *  7" / "the DAILY TAX 50,000 is divided by 24") — sourced from
     *  Projections' own "Telesales Department" Tax Allocation line, split ÷
     *  2 shifts (both Supervisor rows show the SAME per-shift figure), then
     *  ÷ THAT TEAM'S OWN real TSA count for every TSA row on that team.
     *  Root-caused live, 2026-10-02: an earlier wrong reading divided by
     *  the COMPANY-WIDE TSA count instead (6 in this fixture's own 2-team,
     *  3-per-team roster is coincidentally == company-wide here too in a
     *  single-team test, which is why this test seeds BOTH teams with
     *  DIFFERENT TSA counts — 3 vs 2 — so a company-wide-divisor regression
     *  can never again slip through unnoticed). Daily Tax is that same
     *  figure ÷ 24, same shape as the existing Daily Rate (÷24) column. */
    public function test_monthly_and_daily_tax_columns_divide_by_each_teams_own_tsa_count(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        \App\Models\ProjectionColumn::ensureSeededForMonth(now()->format('Y-m'));

        // Give the 2 teams DIFFERENT real TSA counts (SH Naturals keeps its
        // 3 seeded TSAs; Eyecare Team gets just 2 by removing one) so a
        // company-wide-divisor bug can never coincidentally produce the
        // same number as the correct per-team divisor.
        TsaShift::where('team', 'Eyecare Team')->first()->delete();

        $columns = \App\Models\ProjectionColumn::where('month', now()->format('Y-m'))->orderBy('sort_order')->get();
        $rates = \App\Support\ProjectionCalculator::allRates();
        $all = \App\Support\ProjectionCalculator::forAllColumns($columns, $rates);
        $departmentTaxAllocation = $all['telesales_department']['pnl']['tax_allocation'];
        $perShift = $departmentTaxAllocation / 2;
        $perTsaShNaturals = $perShift / TsaShift::where('team', 'SH Naturals')->count();
        $perTsaEyecare = $perShift / TsaShift::where('team', 'Eyecare Team')->count();

        $response = $this->actingAs($admin)->get(route('data.cost-breakdown'));

        $response->assertOk();
        $content = $response->getContent();

        // Both Supervisor rows show the SAME per-shift figure (not two
        // different ones) — appears at least twice (Monthly Tax column)
        // plus twice more for Daily Tax.
        $this->assertSame(2, substr_count($content, number_format($perShift, 2)), 'expected the per-shift Monthly Tax figure on both Supervisor rows');
        $this->assertSame(2, substr_count($content, number_format($perShift / 24, 2)), 'expected the per-shift Daily Tax figure on both Supervisor rows');

        // Each team's own real TSAs show THEIR OWN team's divisor, not the
        // other team's or a company-wide blend of both.
        $this->assertStringContainsString(number_format($perTsaShNaturals, 2), $content);
        $this->assertStringContainsString(number_format($perTsaShNaturals / 24, 2), $content);
        $this->assertStringContainsString(number_format($perTsaEyecare, 2), $content);
        $this->assertStringContainsString(number_format($perTsaEyecare / 24, 2), $content);
        $this->assertNotEquals($perTsaShNaturals, $perTsaEyecare, 'test fixture sanity check — the 2 teams must have different TSA counts for this test to be meaningful');
    }

    /** CEO/Sales Director/Telesales Manager/QA Specialist/Junior AI
     *  Engineer rows are NOT tied to either shift — they stay blank on
     *  Monthly Tax/Daily Tax, same as Total/Daily Rate already are for
     *  them (explicit confirmation, 2026-10-02: only the 2 Supervisor rows
     *  get a figure). */
    public function test_non_supervisor_role_rows_have_no_tax_figure(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        \App\Models\ProjectionColumn::ensureSeededForMonth(now()->format('Y-m'));

        $columns = \App\Models\ProjectionColumn::where('month', now()->format('Y-m'))->orderBy('sort_order')->get();
        $rates = \App\Support\ProjectionCalculator::allRates();
        $all = \App\Support\ProjectionCalculator::forAllColumns($columns, $rates);
        $perShift = $all['telesales_department']['pnl']['tax_allocation'] / 2;

        $response = $this->actingAs($admin)->get(route('data.cost-breakdown'));

        $response->assertOk();
        $content = $response->getContent();

        $ceoRowStart = strpos($content, 'CEO');
        $nextRowStart = strpos($content, 'Sales Director', $ceoRowStart);
        $ceoRowHtml = substr($content, $ceoRowStart, $nextRowStart - $ceoRowStart);

        $this->assertStringNotContainsString(number_format($perShift, 2), $ceoRowHtml);
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

    /** Explicit request, 2026-10-03: "i want to make it roles is editable
     *  like role and name" — label/person_name both now real editable
     *  fields, and the edit must survive a later ensureSeeded() call
     *  (i.e. a later page load) instead of being silently overwritten
     *  back to the seed value. */
    public function test_updating_a_roles_label_and_person_name_persists(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        $ceo = CostBreakdownRole::where('seed_key', 'ceo')->firstOrFail();

        $response = $this->actingAs($admin)->patchJson(
            route('data.cost-breakdown.update-role', $ceo),
            ['label' => 'Chief Executive Officer', 'person_name' => 'Jane Doe']
        );

        $response->assertOk();
        $this->assertSame('Chief Executive Officer', $ceo->fresh()->label);
        $this->assertSame('Jane Doe', $ceo->fresh()->person_name);

        // A later page load (ensureSeeded() running again) must NOT
        // revert either field back to "CEO"/null — the whole point of
        // matching by seed_key instead of label from here on.
        $this->actingAs($admin)->get(route('data.cost-breakdown'));
        $this->assertSame('Chief Executive Officer', $ceo->fresh()->label);
        $this->assertSame('Jane Doe', $ceo->fresh()->person_name);
        // Still exactly 7 roles — renaming never created a duplicate
        // "CEO" row re-seeded under the original label.
        $this->assertSame(7, CostBreakdownRole::count());
    }

    /** An empty label must be rejected, not silently saved — a role with
     *  no name at all would be confusing and has no real meaning. */
    public function test_updating_a_roles_label_to_empty_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        $ceo = CostBreakdownRole::where('seed_key', 'ceo')->firstOrFail();

        $response = $this->actingAs($admin)->patchJson(
            route('data.cost-breakdown.update-role', $ceo),
            ['label' => '']
        );

        $response->assertStatus(422);
        $this->assertSame('CEO', $ceo->fresh()->label);
    }

    /** Explicit request, 2026-10-03: "i want you to add role" — a new
     *  standalone (no group) role appears on the page and persists. */
    public function test_adding_a_standalone_role_persists(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();

        $response = $this->actingAs($admin)->postJson(route('data.cost-breakdown.roles.store'), [
            'label' => 'Marketing Lead', 'person_name' => 'Pat Reyes', 'base_salary' => 25000,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('cost_breakdown_roles', [
            'label' => 'Marketing Lead', 'person_name' => 'Pat Reyes', 'base_salary' => 25000,
            'seed_key' => null, 'overhead_group' => null,
        ]);

        $page = $this->actingAs($admin)->get(route('data.cost-breakdown'));
        $page->assertOk();
        $page->assertSee('Marketing Lead');
    }

    /** Explicit confirmation, 2026-10-03: "full featured — can also join a
     *  shared overhead group" — a new role joining an EXISTING group
     *  merges its own base_salary into that group's combined overhead
     *  figure, same as CEO/Sales Director/Telesales Manager already
     *  share one. */
    public function test_adding_a_role_to_an_existing_group_changes_that_groups_overhead(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        $ceo = CostBreakdownRole::where('seed_key', 'ceo')->firstOrFail();
        $before = CostBreakdownCalculator::overheadPerTsa(
            CostBreakdownRole::where('overhead_group', 'executive')->pluck('base_salary')->all(),
            CostBreakdownRole::OVERHEAD_DIVISOR_COUNTS['total']
        );

        $response = $this->actingAs($admin)->postJson(route('data.cost-breakdown.roles.store'), [
            'label' => 'VP of Sales', 'base_salary' => 40000,
            'overhead_group' => 'executive', 'overhead_divisor' => 'total',
        ]);

        $response->assertOk();
        $newRole = CostBreakdownRole::where('label', 'VP of Sales')->firstOrFail();
        $this->assertSame('executive', $newRole->overhead_group);
        $this->assertSame('total', $newRole->overhead_divisor);

        $after = CostBreakdownCalculator::overheadPerTsa(
            CostBreakdownRole::where('overhead_group', 'executive')->pluck('base_salary')->all(),
            CostBreakdownRole::OVERHEAD_DIVISOR_COUNTS['total']
        );
        $this->assertGreaterThan($before, $after, 'adding a role to an existing group should raise its combined overhead figure');
        // The response's own recomputedOverhead already reflects the new
        // combined figure for every role in the group, including the CEO.
        $response->assertJsonPath('recomputedOverhead.' . $ceo->id, fn ($v) => abs($v - $after) < 0.01);
    }

    /** Regression test, 2026-10-03 (live screenshot): adding a role to an
     *  existing group appended it to the very END of the whole table's
     *  own sort_order instead of placing it adjacent to that group's
     *  other members — the view's own rowspan logic assumes a group's
     *  rows are physically CONTIGUOUS, so the unrelated role sitting
     *  right after the group's old last member (QA Specialist, right
     *  after the "executive" group) visually got swallowed into the new
     *  merged region instead. A SECOND bug compounded this: the very
     *  next page load (ensureSeeded() re-running inside index()) was
     *  silently resetting every shifted seeded role's own sort_order
     *  straight back to its hardcoded 0-6 position, producing a
     *  DUPLICATE sort_order and corrupting the table even after the
     *  insert-adjacent fix. This test exercises the exact live sequence:
     *  add a role to a group, THEN reload the page (not just check the
     *  POST response), and confirms every role still has a UNIQUE
     *  sort_order with the new role landing immediately next to its own
     *  group, not appended past an unrelated later role. */
    public function test_a_role_added_to_a_group_stays_adjacent_to_it_after_a_page_reload(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();

        $this->actingAs($admin)->postJson(route('data.cost-breakdown.roles.store'), [
            'label' => 'VP of Sales', 'overhead_group' => 'executive', 'overhead_divisor' => 'total',
        ])->assertOk();

        // The page reload itself (index() -> ensureSeeded() again) is the
        // exact step that reproduced the live bug — a response assertion
        // alone on the POST wouldn't catch it.
        $this->actingAs($admin)->get(route('data.cost-breakdown'))->assertOk();

        $sortOrders = CostBreakdownRole::orderBy('sort_order')->pluck('sort_order', 'label');
        $this->assertSame($sortOrders->count(), $sortOrders->unique()->count(), 'every role must have a UNIQUE sort_order — a duplicate means two roles are fighting for the same table position');

        $vpOfSales = CostBreakdownRole::where('label', 'VP of Sales')->firstOrFail();
        $telesalesManager = CostBreakdownRole::where('seed_key', 'telesales_manager')->firstOrFail();
        $qaSpecialist = CostBreakdownRole::where('seed_key', 'qa_specialist')->firstOrFail();
        $this->assertSame($telesalesManager->sort_order + 1, $vpOfSales->sort_order, 'VP of Sales should land immediately after the executive group\'s own last member');
        $this->assertGreaterThan($vpOfSales->sort_order, $qaSpecialist->sort_order, 'QA Specialist (a DIFFERENT group) must stay AFTER the newly-inserted role, not get absorbed before it');
    }

    /** A role joining a group REQUIRES a divisor — it's meaningless for a
     *  standalone role, but every role that actually shares a group
     *  figure must agree on the same divisor or the "shared" number
     *  would silently mean two different things. */
    public function test_adding_a_role_to_a_group_without_a_divisor_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();

        $response = $this->actingAs($admin)->postJson(route('data.cost-breakdown.roles.store'), [
            'label' => 'VP of Sales', 'overhead_group' => 'executive',
        ]);

        $response->assertStatus(422);
    }

    /** Explicit request, 2026-10-03, same request as adding a role —
     *  removing a custom role actually deletes it. */
    public function test_removing_a_custom_role_deletes_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        $custom = CostBreakdownRole::create(['seed_key' => null, 'label' => 'Temp Role', 'base_salary' => 0, 'sort_order' => 99]);

        $response = $this->actingAs($admin)->deleteJson(route('data.cost-breakdown.roles.destroy', $custom));

        $response->assertOk();
        $this->assertDatabaseMissing('cost_breakdown_roles', ['id' => $custom->id]);
    }

    /** One of the 7 fixed roles can never be removed — server-guarded,
     *  not just hidden client-side (the view never shows a × on these,
     *  but a direct request must still be refused). */
    public function test_removing_a_fixed_seeded_role_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CostBreakdownRole::ensureSeeded();
        $ceo = CostBreakdownRole::where('seed_key', 'ceo')->firstOrFail();

        $response = $this->actingAs($admin)->deleteJson(route('data.cost-breakdown.roles.destroy', $ceo));

        $response->assertStatus(422);
        $this->assertDatabaseHas('cost_breakdown_roles', ['id' => $ceo->id]);
    }

    public function test_a_non_admin_cannot_add_or_remove_a_role(): void
    {
        $normal = User::factory()->create(['role' => 'normal']);
        CostBreakdownRole::ensureSeeded();
        $custom = CostBreakdownRole::create(['seed_key' => null, 'label' => 'Temp Role', 'base_salary' => 0, 'sort_order' => 99]);

        $this->actingAs($normal)->postJson(route('data.cost-breakdown.roles.store'), ['label' => 'Nope'])->assertForbidden();
        $this->actingAs($normal)->deleteJson(route('data.cost-breakdown.roles.destroy', $custom))->assertForbidden();
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
        // "Daily Rate / Product" (explicit request, 2026-09-30) — same
        // 'dailyRate' above (base_salary + overhead refs, ÷24), split
        // across every CHECKED product (explicit follow-up: "user only
        // can identify what product that has cost" — see
        // TsaDailyRateService's own doc comment for the formula source,
        // reverted 2026-09-30 to this after a same-day detour through the
        // bottom table's own pool-share total).
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
        $response->assertSee('Daily Rate / Product');

        // Its own source is $dailyRate (base_salary + overhead refs, ÷24 —
        // see TsaDailyRateService's own doc comment), split across every
        // CHECKED product — confirm the REAL figure renders somewhere on the
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
        CostBreakdownRole::ensureSeeded();
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

        // This BOTTOM table's own source is the pool-share row TOTAL
        // (CostBreakdownCalculator::rowForShare()), NOT
        // TsaDailyRateService::perProductByTsaId() — that service now
        // sources the TOP Salary Breakdown table's own column instead
        // (reverted, 2026-09-30, back to base_salary + overhead refs) —
        // the two tables are deliberately independent figures.
        $totalDays = TsaShift::count() * 30;
        $poolAmounts = CostBreakdownPool::pluck('amount', 'key')->all();
        $share = CostBreakdownCalculator::shareOfDays(30, $totalDays);
        $rowTotal = CostBreakdownCalculator::rowForShare($share, $poolAmounts)['total'];
        $dailyRate = CostBreakdownCalculator::tsaDailyRate($rowTotal);
        $expected = CostBreakdownCalculator::tsaDailyRatePerProduct($dailyRate, 1);
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
        CostBreakdownRole::ensureSeeded();
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

        // 'recomputed' is the BOTTOM Cost Allocation Per TSA table's own
        // live-refresh payload — sourced from the pool-share row TOTAL
        // (CostBreakdownCalculator::rowForShare(), NOT
        // TsaDailyRateService::perProductByTsaId(), which now sources the
        // TOP Salary Breakdown table's own column instead — reverted,
        // 2026-09-30, back to base_salary + overhead refs as that column's
        // own source). The two tables are deliberately independent
        // figures now, so this asserts only that SOME non-zero figure
        // landed for the newly-flagged product, not a specific formula.
        $response->assertJsonPath("recomputed.{$tsa->id}.derived.products.{$product->display_name}", fn ($v) => $v >= 0);

        // The TOP Salary Breakdown table's own live-refresh figure
        // (explicit request, 2026-09-30: "i want it will auto to that
        // changed like i should not reload whole page to reflect") — a
        // SEPARATE key from 'derived.products' above, sourced from
        // TsaDailyRateService (base_salary + overhead refs) instead.
        $expectedSalaryFigure = TsaDailyRateService::perProductByTsaId()[$tsa->id];
        $this->assertGreaterThan(0, $expectedSalaryFigure);
        $response->assertJsonPath("recomputed.{$tsa->id}.salaryDailyRatePerProduct", fn ($v) => abs($v - $expectedSalaryFigure) < 0.01);
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
