<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirmed live: Team Comparison showed "106 calls today" for SH Naturals on
 * a day with 0 upsells and (per the user) no actual operation — reading as
 * "TSAs worked all day and closed nothing," when the real story was 0 calls
 * actually made and 106 raw leads that arrived and sat undispositioned all
 * day. total_calls was a raw COUNT(*) of every order created that day, not
 * ProductPerformance::tally()'s total_called (answered + unanswered) — the
 * same distinction already fixed for the TSA Leaderboard and Hourly Activity
 * (see DashboardTsaLeaderboardCallCountTest), just missed here.
 */
class DashboardTeamComparisonCallCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_team_comparison_call_count_excludes_leads_with_no_concluded_disposition(): void
    {
        Order::create([
            'pancake_order_id'   => 'called-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => 'Gemma',
            'disposition'        => 'CONFIRMED VIA CALL',
            'is_upsell'          => false,
            'status_code'        => 1,
            'pancake_created_at' => '2026-07-31 10:00:00',
            'synced_at'          => now(),
        ]);

        // Raw leads that arrived but were never worked — no disposition at
        // all, same as a genuine "no one worked today" backlog day.
        foreach (range(1, 5) as $i) {
            Order::create([
                'pancake_order_id'   => "not-yet-called-{$i}",
                'team'               => 'SH Naturals',
                'tsa_name'           => null,
                'disposition'        => null,
                'is_upsell'          => false,
                'status_code'        => 1,
                'pancake_created_at' => '2026-07-31 11:00:00',
                'synced_at'          => now(),
            ]);
        }

        $response = $this->get(route('dashboard', [
            'team' => 'all', 'date_from' => '2026-07-31', 'date_to' => '2026-07-31',
        ]));

        $response->assertOk();
        $response->assertViewHas('teamComparison', function ($rows) {
            $shNaturals = $rows->firstWhere('name', 'SH Naturals');
            // 6 orders exist that day, but only 1 was ever actually called —
            // total_calls must reflect that 1, not the raw count of 6.
            return $shNaturals && $shNaturals['total_calls'] === 1;
        });
    }
}
