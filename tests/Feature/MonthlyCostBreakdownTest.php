<?php

namespace Tests\Feature;

use App\Models\CostBreakdownPool;
use App\Models\CostBreakdownRole;
use App\Models\CostBreakdownTsaEntry;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\CostBreakdownMonths;
use App\Support\TsaDailyRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Explicit request (2026-10-15): Cost Breakdown gets a monthly picker like
 * Projections, "can add cost breakdown too for this month" — a new month
 * starts as a copy of the latest earlier one, and Expected Income / DSPPR /
 * Summary Sales use each day's own month (and that day's TSA roster).
 */
class MonthlyCostBreakdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        CostBreakdownMonths::seed('2026-10');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function addMonth(string $month): void
    {
        $this->post(route('data.cost-breakdown.months.store'), ['month' => $month])
            ->assertRedirect(route('data.cost-breakdown', ['month' => $month]));
    }

    private function rentOn(string $date): float
    {
        return TsaDailyRateService::withDate($date, fn () => TsaDailyRateService::dailyCostRow()['rent']);
    }

    public function test_adding_a_month_copies_the_latest_earlier_month(): void
    {
        CostBreakdownPool::where('month', '2026-10')->where('key', 'rent')->update(['amount' => 9000]);
        $this->addMonth('2026-11');

        $this->assertSame(['2026-10', '2026-11'], CostBreakdownMonths::existing());
        $this->assertSame(9000.0, (float) CostBreakdownPool::where('month', '2026-11')->where('key', 'rent')->value('amount'));
        $this->assertSame(
            CostBreakdownRole::where('month', '2026-10')->count(),
            CostBreakdownRole::where('month', '2026-11')->count()
        );
        $this->assertSame(
            CostBreakdownTsaEntry::where('month', '2026-10')->count(),
            CostBreakdownTsaEntry::where('month', '2026-11')->count()
        );
    }

    public function test_editing_one_month_never_changes_another(): void
    {
        $this->addMonth('2026-11');
        $novRent = CostBreakdownPool::where('month', '2026-11')->where('key', 'rent')->firstOrFail();

        $this->patchJson(route('data.cost-breakdown.update-pool', $novRent), ['amount' => 12000])->assertOk();

        $this->assertSame(12000.0, (float) $novRent->fresh()->amount);
        $this->assertNotSame(12000.0, (float) CostBreakdownPool::where('month', '2026-10')->where('key', 'rent')->value('amount'));
        $this->assertNotEquals($this->rentOn('2026-10-31'), $this->rentOn('2026-11-01'), 'each day uses its own month');
    }

    public function test_a_month_without_its_own_set_uses_the_latest_earlier_one(): void
    {
        $this->addMonth('2026-11');

        $this->assertSame('2026-10', CostBreakdownMonths::effective('2026-09'), 'before the first month → the earliest');
        $this->assertSame('2026-11', CostBreakdownMonths::effective('2026-12'), 'after → the latest earlier month');
    }

    public function test_a_month_without_its_own_set_is_read_only_on_the_page(): void
    {
        $html = $this->get(route('data.cost-breakdown', ['month' => '2026-12']))->assertOk()->getContent();

        $this->assertStringContainsString('id="cbFallbackBanner"', $html);
        $this->assertMatchesRegularExpression('/<fieldset[^>]*disabled/', $html);

        $tsa = TsaShift::firstOrFail();
        $this->patchJson(route('data.cost-breakdown.update-tsa-entry', $tsa), ['days' => 10])->assertStatus(422);
    }

    public function test_the_current_month_is_editable(): void
    {
        $html = $this->get(route('data.cost-breakdown', ['month' => '2026-10']))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="cbFallbackBanner"', $html);
        $this->assertDoesNotMatchRegularExpression('/<fieldset[^>]*disabled/', $html);
    }

    /** "it is removed by today and for example tomorrow the cost will be
     *  divided to the remaining tsa" — exact per day, even inside a range. */
    public function test_shared_costs_split_among_the_remaining_tsas_from_the_day_after_a_removal(): void
    {
        $before = TsaDailyRateService::withDate('2026-10-15', fn () => TsaDailyRateService::activeTsaCount());
        TsaShift::firstOrFail()->delete(); // removed today

        $this->assertSame($before, TsaDailyRateService::withDate('2026-10-15', fn () => TsaDailyRateService::activeTsaCount()), 'today still counts her');
        $this->assertSame($before - 1, TsaDailyRateService::withDate('2026-10-16', fn () => TsaDailyRateService::activeTsaCount()), 'tomorrow: the remaining TSAs');

        $range = TsaDailyRateService::sumOverDates(
            collect([Carbon::parse('2026-10-15'), Carbon::parse('2026-10-16')]),
            fn () => TsaDailyRateService::dailyCostRow()['rent']
        );
        $this->assertEqualsWithDelta($this->rentOn('2026-10-15') + $this->rentOn('2026-10-16'), $range, 0.001);
        $this->assertGreaterThan($this->rentOn('2026-10-15'), $this->rentOn('2026-10-16'), 'fewer TSAs → bigger share each');
    }

    public function test_expected_income_range_equals_the_sum_of_its_days_across_a_month_change(): void
    {
        $this->addMonth('2026-11');
        CostBreakdownPool::where('month', '2026-11')->where('key', 'rent')->update(['amount' => 20000]);

        $salariesAndRent = function (string $from, string $to) {
            $total = $this->get(route('data.expected-income', ['team' => 'all', 'date_from' => $from, 'date_to' => $to]))
                ->assertOk()->viewData('summaryOverallTotal');

            return [(float) $total['operating_lines']['salaries'], (float) $total['operating_lines']['rent']];
        };

        [$s1, $r1] = $salariesAndRent('2026-10-31', '2026-10-31');
        [$s2, $r2] = $salariesAndRent('2026-11-01', '2026-11-01');
        [$sr, $rr] = $salariesAndRent('2026-10-31', '2026-11-01');

        $this->assertEqualsWithDelta($s1 + $s2, $sr, 0.01);
        $this->assertEqualsWithDelta($r1 + $r2, $rr, 0.01);
        $this->assertNotEqualsWithDelta($r1, $r2, 0.01, 'November uses its own Rent');
    }

    /** "Cost Allocation Per TSA" product checkboxes are per month (2026-10-24). */
    public function test_cost_allocation_checkboxes_are_per_month(): void
    {
        $product = \App\Models\Product::where('is_hidden', false)->orderBy('sort_order')->first();
        \App\Models\CostBreakdownProductFlag::create(['month' => '2026-10', 'product_id' => $product->id, 'has_cost_allocation' => true]);
        $count = fn (string $date) => TsaDailyRateService::withDate($date, fn () => TsaDailyRateService::productCount());
        $octCount = $count('2026-10-20');

        // a new month starts with a copy of October's checkboxes
        $this->addMonth('2026-11');
        $this->assertTrue((bool) \App\Models\CostBreakdownProductFlag::where('month', '2026-11')->where('product_id', $product->id)->value('has_cost_allocation'));
        $this->assertSame($octCount, $count('2026-11-20'));

        // unticking it while viewing November leaves October alone
        $this->get(route('data.cost-breakdown', ['month' => '2026-11']))->assertOk();
        $this->patchJson(route('data.cost-breakdown.update-product-has-cost-allocation', $product), ['has_cost_allocation' => false])->assertOk();
        $this->assertSame($octCount - 1, $count('2026-11-20'));
        $this->assertSame($octCount, $count('2026-10-20'));
        $this->assertTrue(TsaDailyRateService::withDate('2026-10-20', fn () => $product->fresh()->has_cost_allocation));
        $this->assertFalse(TsaDailyRateService::withDate('2026-11-20', fn () => $product->fresh()->has_cost_allocation));
    }
}
