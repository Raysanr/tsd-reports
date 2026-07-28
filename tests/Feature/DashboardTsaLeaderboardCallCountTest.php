<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirmed in production (2026-07-28): Katherine showed "42 calls" on the
 * Dashboard's Today's TSA Leaderboard but "41 Total Called Leads" on her own
 * TSA Performance page — a raw COUNT(*) of every order tagged to a TSA is
 * NOT the same thing as calls actually made; one lead was still "Call in
 * progress", with no concluded disposition yet. Same reasoning, and the
 * same fix, as Hourly Activity's "calls per hour, not raw lead volume".
 * See DashboardController's $tsaLeaderboard, which now reads
 * ProductPerformance::tally()'s total_called instead of COUNT(*).
 */
class DashboardTsaLeaderboardCallCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_leaderboard_call_count_excludes_a_lead_with_no_concluded_disposition_yet(): void
    {
        $shift = TsaShift::where('team', 'Eyecare Team')->first();

        Order::create([
            'pancake_order_id'   => 'called-1',
            'team'               => 'Eyecare Team',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'is_upsell'          => false,
            'status_code'        => 1,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        // No disposition yet — a lead that arrived but hasn't been called/
        // dispositioned, same as the real "Call in progress" case that
        // exposed this bug.
        Order::create([
            'pancake_order_id'   => 'not-yet-called-1',
            'team'               => 'Eyecare Team',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => null,
            'is_upsell'          => false,
            'status_code'        => 1,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('tsaLeaderboard', function ($leaderboard) use ($shift) {
            $row = $leaderboard->firstWhere('tsa_name', $shift->tsa_key);
            return $row && $row->total_calls === 1;
        });
    }
}
