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
        // the sheets"). The CEO's own 10,341.13 reference figure is purely
        // informational and never added in.
        $response->assertJsonPath('total', fn ($v) => abs($v - 50000) < 0.01);
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
        $tsa = TsaShift::first();

        // Base Salary IS her real full monthly total directly (no separate
        // bonus field anywhere — explicit follow-up, 2026-09-29).
        $response = $this->actingAs($admin)->patchJson(
            route('data.cost-breakdown.update-tsa-entry', $tsa),
            ['base_salary' => 40388.75]
        );

        $response->assertOk();
        $this->assertDatabaseHas('cost_breakdown_tsa_entries', [
            'tsa_id' => $tsa->id,
            'base_salary' => 40388.75,
        ]);
        // 40,388.75 ÷ 24 = 1,682.86 daily — matching the real sheet's own
        // numbers exactly.
        $response->assertJsonPath('dailyRate', fn ($v) => abs($v - 1682.86) < 0.01);
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
