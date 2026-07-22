<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TsaPerformanceExcessCateredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /**
     * Regression test for the Excess/Catered definition (changed 2026-07-22):
     * Catered = Answered + Unanswered (i.e. total_called — a lead only counts once
     * it has an actual recognized call outcome), and Excess = Total - Catered, so
     * every row adds up visibly with no third "in progress" bucket. This is
     * stricter than the previous tag-based definition: a lead a TSA has claimed but
     * hasn't finished dispositioning yet (null disposition, or a non-terminal tag
     * like "Call in Progress") now falls into Excess, not Catered — same formula on
     * both ProductPerformance::tally() and this controller's own per-TSA copy.
     */
    public function test_catered_is_answered_plus_unanswered_excess_is_the_remainder(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        $today = now()->toDateString();

        // [pancake_order_id => [tsa_name, disposition]]
        $orders = [
            // Genuinely untouched, no TSA at all → Excess
            'excess-unassigned'  => [null,            null],
            // A TSA claimed it, but no disposition yet — still Excess under the new
            // stricter definition, unlike the old tag-based one where this counted
            // as Catered.
            'excess-null-disp'   => [$shift->tsa_key, null],
            // "Call in Progress" isn't one of the 13 recognized dispositions either
            // → still Excess, same reasoning as above.
            'excess-cip'         => [$shift->tsa_key, 'Call in Progress (Sinuxyl)'],
            // Real, concluded outcomes → Catered
            'catered-confirmed'  => [$shift->tsa_key, 'CONFIRMED VIA CALL'],
            'catered-not-answer' => [$shift->tsa_key, 'NOT ANSWERING'],
        ];

        foreach ($orders as $id => [$tsaName, $disposition]) {
            Order::create([
                'pancake_order_id'   => $id,
                'team'               => 'SH Naturals',
                'tsa_name'           => $tsaName,
                'disposition'        => $disposition,
                'is_upsell'          => false,
                'status_code'        => 1,
                'pancake_created_at' => now(),
                'synced_at'          => now(),
            ]);
        }

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date' => $today]));

        $response->assertOk();
        // 5 total: 2 catered (real outcomes), 3 excess (unclaimed + unresolved)
        $response->assertViewHas('totals', function ($totals) {
            return $totals['total'] === 5 && $totals['catered'] === 2 && $totals['excess'] === 3;
        });
    }
}
