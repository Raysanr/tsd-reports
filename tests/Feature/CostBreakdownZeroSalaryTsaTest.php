<?php

namespace Tests\Feature;

use App\Models\CostBreakdownTsaEntry;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\CostBreakdownCalculator;
use App\Support\CostBreakdownMonths;
use App\Support\RequestMemo;
use App\Support\TsaDailyRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A TSA with 0 base salary (e.g. just added) carries no Total, Daily Rate
 *  or tax; the shift's tax is split among TSAs with a salary only
 *  (explicit request, 2026-10-23). */
class CostBreakdownZeroSalaryTsaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tsa_without_salary_carries_no_total_or_tax_and_teammates_split_it(): void
    {
        $this->assertSame(0.0, CostBreakdownCalculator::tsaTotal(0, [10341.13, 4833.33, 5714.29]));
        $this->assertEqualsWithDelta(40388.75, CostBreakdownCalculator::tsaTotal(19500, [10341.13, 4833.33, 5714.29]), 0.001);

        $team = TsaShift::where('team', 'SH Naturals')->where('tiktok_upsell', false)->get();
        $this->assertGreaterThan(1, $team->count(), 'test setup: need a real team');
        $month = CostBreakdownMonths::current();
        foreach ($team as $t) {
            CostBreakdownTsaEntry::updateOrCreate(['month' => $month, 'tsa_id' => $t->id], ['base_salary' => 19500, 'days' => 30]);
        }
        $new = TsaShift::create(['tsa_key' => 'jhun', 'display_name' => 'Jhun Ematong', 'team' => 'SH Naturals', 'sort_order' => 999]);
        CostBreakdownTsaEntry::updateOrCreate(['month' => $month, 'tsa_id' => $new->id], ['base_salary' => 0, 'days' => 0]);
        RequestMemo::flush();

        $tax = TsaDailyRateService::taxAllocationByTsaId();
        $this->assertSame(0.0, $tax[$new->id]);
        $this->assertSame(0.0, TsaDailyRateService::taxAllocationForTsa($new));
        $this->assertSame(0.0, TsaDailyRateService::dailyRateByTsaId()[$new->id]);

        // the paid teammates split the whole shift tax between themselves
        $perShift = TsaDailyRateService::departmentTaxAllocationPerShift();
        $teamDaily = collect($team)->sum(fn ($t) => $tax[$t->id]);
        $this->assertEqualsWithDelta($perShift / 24, $teamDaily, 0.01);

        // Cost Breakdown page shows 0.00 Monthly Tax for him
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->get(route('data.cost-breakdown'))->assertOk();
    }

    /** A TSA added in a later month doesn't appear in, or change, an earlier
     *  month (2026-10-24). */
    public function test_a_tsa_added_next_month_does_not_change_last_month(): void
    {
        $month = CostBreakdownMonths::current();
        $last = \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->subMonth()->format('Y-m');
        $next = \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->addMonth();

        $new = TsaShift::create(['tsa_key' => 'later', 'display_name' => 'Added Later', 'team' => 'SH Naturals', 'sort_order' => 999]);
        $new->forceFill(['created_at' => $next->copy()->startOfMonth()->addDays(4)])->saveQuietly();
        $existing = TsaShift::where('tsa_key', '!=', 'later')->first();
        $existing->forceFill(['created_at' => $next->copy()->subMonths(3)])->saveQuietly();

        $none = collect();
        $this->assertFalse(TsaDailyRateService::belongsToMonth($new->fresh(), $month, $none));
        $this->assertFalse(TsaDailyRateService::belongsToMonth($new->fresh(), $last, $none));
        $this->assertTrue(TsaDailyRateService::belongsToMonth($new->fresh(), $next->format('Y-m'), $none));
        $this->assertTrue(TsaDailyRateService::belongsToMonth($existing->fresh(), $month, $none));
        // an entry for the month keeps them in it, whatever their added date
        $this->assertTrue(TsaDailyRateService::belongsToMonth($new->fresh(), $month, collect([$new->id => true])));

        // not on this month's roster, so they don't change its splits
        RequestMemo::flush();
        $this->assertFalse(TsaDailyRateService::roster()->contains('id', $new->id));
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->get(route('data.cost-breakdown', ['month' => $month]))->assertOk()->assertDontSee('Added Later');
    }
}
