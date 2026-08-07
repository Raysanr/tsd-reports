<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request: make every tab's TSA/amount figures fully accurate to
 * real Pancake POS. Investigating the TSA Leaderboard's 18-vs-20 gap (see
 * DashboardTsaLeaderboardReturnedUpsellTest) turned up the same bug in three
 * more places on the Dashboard alone: Total Cross-Sell Sales, Top Upsell
 * Products, and Team Comparison all counted `is_upsell = true` only, missing
 * orders that were genuine upsells later returned/cancelled in Pancake
 * (is_returned_upsell is set instead — see Order::isRealUpsell()'s own doc
 * comment). Fixed via one shared definition (Order::isRealUpsell() /
 * Order::scopeRealUpsell()) so these can't drift apart from each other, or
 * from TSA Performance/Leads Report, again.
 */
class DashboardReturnedUpsellAccuracyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function liveUpsell(string $id, string $team, float $amount, ?string $product = null): void
    {
        Order::create([
            'pancake_order_id'   => $id,
            'team'               => $team,
            'tsa_name'           => 'Gemma',
            'is_upsell'          => true,
            'is_returned_upsell' => false,
            'amount'             => $amount,
            'product'            => $product,
            'status_code'        => 2,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);
    }

    private function returnedUpsell(string $id, string $team, float $amount, ?string $product = null): void
    {
        Order::create([
            'pancake_order_id'   => $id,
            'team'               => $team,
            'tsa_name'           => 'Gemma',
            'is_upsell'          => false,
            'is_returned_upsell' => true,
            'amount'             => $amount,
            'product'            => $product,
            'status_code'        => 4,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);
    }

    public function test_total_cross_sell_sales_includes_later_returned_upsells(): void
    {
        $this->liveUpsell('tcs-1', 'SH Naturals', 800.0);
        $this->returnedUpsell('tcs-2', 'SH Naturals', 1200.0);

        $response = $this->get(route('dashboard', ['team' => 'sh-naturals']));

        $response->assertOk();
        $response->assertViewHas('stats', function ($stats) {
            return $stats['total_orders'] === 2 && (float) $stats['total_sales'] === 2000.0;
        });
    }

    public function test_top_upsell_products_includes_later_returned_upsells(): void
    {
        $this->liveUpsell('top-1', 'SH Naturals', 800.0, 'Sinuxyl Nasal Inhaler');
        $this->returnedUpsell('top-2', 'SH Naturals', 1200.0, 'Sinuxyl Nasal Inhaler');

        $response = $this->get(route('dashboard', ['team' => 'sh-naturals']));

        $response->assertOk();
        $response->assertViewHas('topProducts', function ($topProducts) {
            $row = $topProducts->firstWhere('product', 'Sinuxyl Nasal Inhaler');
            return $row && (int) $row->upsell_count === 2 && (float) $row->total_sales === 2000.0;
        });
    }

    public function test_team_comparison_includes_later_returned_upsells(): void
    {
        $this->liveUpsell('tc-1', 'SH Naturals', 800.0);
        $this->returnedUpsell('tc-2', 'SH Naturals', 1200.0);

        $response = $this->get(route('dashboard', ['team' => 'all']));

        $response->assertOk();
        $response->assertViewHas('teamComparison', function ($teamComparison) {
            $row = $teamComparison->firstWhere('name', 'SH Naturals');
            return $row && $row['upsell_count'] === 2 && (float) $row['revenue'] === 2000.0;
        });
    }

    public function test_charts_sales_series_includes_later_returned_upsells(): void
    {
        $this->liveUpsell('chart-1', 'SH Naturals', 800.0);
        $this->returnedUpsell('chart-2', 'SH Naturals', 1200.0);

        $response = $this->get(route('charts', ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]));

        $response->assertOk();
        $response->assertViewHas('salesSeries', function ($salesSeries) {
            $todaysSale = end($salesSeries['SH Naturals']);
            return (float) $todaysSale === 2000.0;
        });
    }
}
