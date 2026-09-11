<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real production shape (2026-09-07): Angel Margallo, Team Closing (SH
 * Naturals), showed 11 upsells on TSA Performance's own pages for 2026-09-06
 * instead of the real 14 — the missing 3 (orders #1364719/#1364674/#1364669)
 * were leads she genuinely closed that landed with Order.team = Eyecare
 * ("the team closing is can cater the opening leads"). Confirmed via three
 * explicit follow-up requests the same day: "so is if its fixed the angel
 * should be like 14?", "so like angel too should be 14 in the upsell w/
 * confirmation like that not 11 only", "even in the individual tsa
 * performance it should be accurate."
 *
 * Superseded in part 2026-09-11 (explicit request: "it should be like the
 * closing start is 3pm") — the HOURLY breakdown specifically is now bounded
 * to each team's own real window (TeamShiftWindow, see
 * TsaPerformanceController::index()'s own $startHour/$endHour), so Angel's
 * 3 cross-team leads (10am/11am/12pm, before Closing's 3pm start) no longer
 * get their own out-of-window hour-block row there — only her 11 own-window
 * upsells do. The flat summary/ALL view/individual page/Dashboard
 * Leaderboard are untouched by that change and still read 14 everywhere,
 * since none of them go through the hourly-bound loop; this test now checks
 * that split explicitly instead of asserting all five agree on the same 14.
 */
class TsaPerformanceCrossTeamCreditMatchesEverywhereTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_a_closing_tsas_cross_team_catered_leads_show_the_same_total_everywhere(): void
    {
        $angel = TsaShift::where('team', 'SH Naturals')->first();
        $date  = '2026-09-06';

        // Her 11 real Closing-hour upsells, spread across a couple of hours
        // so the hourly breakdown has more than one block to check.
        for ($i = 0; $i < 6; $i++) {
            Order::create([
                'pancake_order_id'   => "angel-own-team-am-{$i}",
                'team'               => 'SH Naturals',
                'tsa_name'           => $angel->tsa_key,
                'is_upsell'          => true,
                'amount'             => 700.0,
                'status_code'        => 2,
                'pancake_created_at' => "{$date} 16:00:00",
                'synced_at'          => now(),
            ]);
        }
        for ($i = 0; $i < 5; $i++) {
            Order::create([
                'pancake_order_id'   => "angel-own-team-pm-{$i}",
                'team'               => 'SH Naturals',
                'tsa_name'           => $angel->tsa_key,
                'is_upsell'          => true,
                'amount'             => 700.0,
                'status_code'        => 2,
                'pancake_created_at' => "{$date} 18:00:00",
                'synced_at'          => now(),
            ]);
        }

        // 3 Opening-hour leads she genuinely closed — Order.team follows the
        // lead's own hour window (Eyecare), not who closed it.
        foreach (['1364719', '1364674', '1364669'] as $i => $orderId) {
            Order::create([
                'pancake_order_id'   => $orderId,
                'team'               => 'Eyecare Team',
                'tsa_name'           => $angel->tsa_key,
                'is_upsell'          => true,
                'amount'             => 500.0,
                'status_code'        => 2,
                'pancake_created_at' => "{$date} " . (10 + $i) . ":00:00",
                'synced_at'          => now(),
            ]);
        }

        $expectedCount = 14;
        $expectedSales = 6 * 700.0 + 5 * 700.0 + 3 * 500.0; // 9200.0

        // TSA Performance — single-team flat summary.
        $shResponse = $this->get(route('tsa-performance', [
            'team' => 'sh-naturals', 'date_from' => $date, 'date_to' => $date,
        ]));
        $shResponse->assertOk();
        $shFlatRow = $shResponse->viewData('tsaRows')->firstWhere('tsa_key', $angel->tsa_key);
        $this->assertNotNull($shFlatRow);
        $this->assertSame($expectedCount, $shFlatRow['upsell_confirmation']);
        $this->assertSame($expectedSales, (float) $shFlatRow['upsell_sales']);

        // TSA Performance — single-team hourly breakdown: sum her row across
        // every hour block on the page. Deliberately LESS than $expectedCount
        // (2026-09-11) — her 3 cross-team leads at 10/11/12 fall before
        // Closing's own 3pm window start, so they count in the flat summary
        // above but not here; only her 11 own-window upsells (4pm/6pm) do.
        $hourBlocks = $shResponse->viewData('hourBlocks');
        $hourlyUpsellTotal = 0;
        $hourlySalesTotal  = 0.0;
        foreach ($hourBlocks as $block) {
            foreach ($block['rows'] as $row) {
                if ($row['tsa_key'] === $angel->tsa_key) {
                    $hourlyUpsellTotal += $row['upsell_confirmation'];
                    $hourlySalesTotal  += $row['upsell_sales'];
                }
            }
        }
        $expectedHourlyCount = 11;
        $expectedHourlySales = 6 * 700.0 + 5 * 700.0; // 7700.0 — the 3 cross-team leads excluded
        $this->assertSame($expectedHourlyCount, $hourlyUpsellTotal);
        $this->assertSame($expectedHourlySales, $hourlySalesTotal);

        // TSA Performance — ALL view.
        $allResponse = $this->get(route('tsa-performance', ['team' => 'all', 'date_from' => $date, 'date_to' => $date]));
        $allResponse->assertOk();
        $allRow = $allResponse->viewData('tsaRows')->firstWhere('tsa_key', $angel->tsa_key);
        $this->assertNotNull($allRow);
        $this->assertSame($expectedCount, $allRow['upsell_confirmation']);
        $this->assertSame($expectedSales, (float) $allRow['upsell_sales']);

        // TSA Performance — individual drill-down page (showTsa()), already
        // correct beforehand — regression guard only.
        $showResponse = $this->get(route('tsa-performance.individual', [
            'team' => 'sh-naturals', 'tsaKey' => $angel->tsa_key,
            'date_from' => $date, 'date_to' => $date,
        ]));
        $showResponse->assertOk();
        $summary = $showResponse->viewData('summary');
        $this->assertSame($expectedCount, $summary['upsell_confirmation']);
        $this->assertSame($expectedSales, (float) $summary['upsell_sales']);

        // Dashboard Leaderboard, filtered to her own team.
        $dashboardResponse = $this->get(route('dashboard', ['team' => 'sh-naturals', 'date_from' => $date, 'date_to' => $date]));
        $dashboardResponse->assertOk();
        $leaderboardRow = $dashboardResponse->viewData('tsaLeaderboard')->firstWhere('tsa_name', $angel->tsa_key);
        $this->assertNotNull($leaderboardRow);
        $this->assertSame($expectedCount, $leaderboardRow->upsell_count);
        $this->assertSame($expectedSales, (float) $leaderboardRow->upsell_sales);
    }
}
