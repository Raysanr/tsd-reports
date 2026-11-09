<?php

namespace Tests\Feature;

use App\Models\CostBreakdownPool;
use App\Models\CostBreakdownRole;
use App\Models\User;
use App\Support\CostBreakdownMonths;
use App\Support\CostBreakdownVariant;
use App\Support\TsaDailyRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Actual Cost Breakdown (2026-11-09): the Cost Breakdown page on its own
 *  rows — starts as a copy of the budget figures, and nothing typed there
 *  reaches the budget Cost Breakdown or the pages that read it. */
class ActualCostBreakdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 12:00:00');
        CostBreakdownMonths::seed('2026-11');
        CostBreakdownPool::where('month', '2026-11')->where('key', 'rent')->update(['amount' => 9000]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actual(\Closure $callback): mixed
    {
        return CostBreakdownVariant::using(CostBreakdownVariant::ACTUAL, $callback);
    }

    public function test_page_renders_and_starts_as_a_copy_of_the_budget(): void
    {
        $this->get(route('data.actual-cost-breakdown'))->assertOk()->assertSee('Actual Cost Breakdown');

        $this->assertSame(9000.0, $this->actual(fn () => (float) CostBreakdownPool::where('month', '2026-11')->where('key', 'rent')->value('amount')));
        $this->assertSame(
            CostBreakdownRole::where('month', '2026-11')->count(),
            $this->actual(fn () => CostBreakdownRole::where('month', '2026-11')->count())
        );
    }

    public function test_editing_actual_never_changes_the_budget(): void
    {
        $this->get(route('data.actual-cost-breakdown'))->assertOk();
        $actualRent = $this->actual(fn () => CostBreakdownPool::where('month', '2026-11')->where('key', 'rent')->firstOrFail());
        $budgetDailyRent = TsaDailyRateService::withDate('2026-11-09', fn () => TsaDailyRateService::dailyCostRow()['rent']);

        $this->patchJson(route('data.actual-cost-breakdown.update-pool', $actualRent), ['amount' => 15000])->assertOk();

        $this->assertSame(15000.0, $this->actual(fn () => (float) $actualRent->fresh()->amount));
        $this->assertSame(9000.0, (float) CostBreakdownPool::where('month', '2026-11')->where('key', 'rent')->value('amount'));
        $this->assertSame($budgetDailyRent, TsaDailyRateService::withDate('2026-11-09', fn () => TsaDailyRateService::dailyCostRow()['rent']));
    }

    public function test_a_budget_row_cannot_be_edited_through_the_actual_routes(): void
    {
        $this->get(route('data.actual-cost-breakdown'))->assertOk();
        $budgetRent = CostBreakdownPool::where('month', '2026-11')->where('key', 'rent')->firstOrFail();

        $this->patchJson(route('data.actual-cost-breakdown.update-pool', $budgetRent), ['amount' => 1])->assertNotFound();
        $this->assertSame(9000.0, (float) $budgetRent->fresh()->amount);
    }

    public function test_locks_and_activity_are_separate_from_the_budget(): void
    {
        $this->get(route('data.actual-cost-breakdown'))->assertOk();
        $this->patchJson(route('data.actual-cost-breakdown.toggle-lock', 'pools'), ['locked' => true])->assertOk();

        $this->assertTrue($this->actual(fn () => CostBreakdownMonths::isLocked('pools', '2026-11')));
        $this->assertFalse(CostBreakdownMonths::isLocked('pools', '2026-11'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'actual_cost_breakdown.table_locked']);
    }

    public function test_adding_a_month_redirects_to_the_actual_page(): void
    {
        $this->get(route('data.actual-cost-breakdown'))->assertOk();
        $this->post(route('data.actual-cost-breakdown.months.store'), ['month' => '2026-12'])
            ->assertRedirect(route('data.actual-cost-breakdown', ['month' => '2026-12']));

        $this->assertTrue($this->actual(fn () => CostBreakdownMonths::exists('2026-12')));
        $this->assertFalse(CostBreakdownMonths::exists('2026-12'));
    }

    public function test_normal_users_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'normal']))
            ->get(route('data.actual-cost-breakdown'))->assertForbidden();
    }
}
