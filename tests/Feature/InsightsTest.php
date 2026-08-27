<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\InsightsGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request, 2026-08-26: "smart reports... cards with messages,"
 * confirmed admin-only, confirmed "i want to make it all accurate." Every
 * test here is really testing accuracy/noise-avoidance — that a card only
 * fires when there's genuinely enough signal, using this app's own existing
 * metric formulas (ProductPerformance::tally()/rates(), RtsReportController's
 * own is_returned_upsell split).
 *
 * Explicit follow-up request, 2026-08-27: TSD Reports data only (Analytics/
 * TSA Performance/Leads Report/RTS Report) — Call Tracker's own Lead-
 * assignment concepts (overdue-call backlog, daily-cap capacity) were
 * dropped from InsightsGenerator entirely, so there's nothing to test here
 * for either any more. Same request added the date picker (generate() now
 * takes a $referenceDate) and the day-over-day/week-over-week trend cards.
 */
class InsightsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'pancake_order_id'    => (string) random_int(1000000, 9999999),
            'team'                => 'SH Naturals',
            'tsa_name'            => 'Gemma',
            'disposition'         => null,
            'is_upsell'           => false,
            'status_code'         => 1,
            'amount'              => 500,
            'pancake_created_at'  => now(),
            'pancake_inserted_at' => now(),
            'synced_at'           => now(),
        ], $overrides));
    }

    // ---- Access control -----------------------------------------------

    public function test_a_normal_user_cannot_reach_insights(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'normal']));

        $this->get(route('insights'))->assertForbidden();
    }

    public function test_a_tsa_cannot_reach_insights(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $this->actingAs(User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]));

        $this->get(route('insights'))->assertForbidden();
    }

    public function test_an_admin_can_reach_insights(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('insights'))->assertOk();
    }

    public function test_the_sidebar_link_is_admin_only(): void
    {
        $this->actingAs($this->admin());
        $this->get(route('dashboard'))->assertSee(route('insights'), false);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $this->actingAs(User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]));
        $this->get(route('dashboard'))->assertDontSee(route('insights'), false);
    }

    // ---- TSA trend cards -------------------------------------------------

    public function test_flags_a_tsa_whose_conversion_rate_dropped_well_below_their_own_baseline(): void
    {
        // 3 baseline days at 50% conversion (2 upsell / 2 confirmed-via-call
        // each = 4 answered), then today at 0% (4 confirmed-via-call, 0
        // upsell) — a 50pp drop, well past the 15pp threshold.
        foreach ([4, 3, 2] as $daysAgo) {
            for ($i = 0; $i < 2; $i++) {
                $this->order(['is_upsell' => true, 'pancake_created_at' => now()->subDays($daysAgo), 'pancake_inserted_at' => now()->subDays($daysAgo)]);
                $this->order(['disposition' => 'CONFIRMED VIA CALL', 'pancake_created_at' => now()->subDays($daysAgo), 'pancake_inserted_at' => now()->subDays($daysAgo)]);
            }
        }
        for ($i = 0; $i < 4; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL']);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['severity'] === 'warning'
            && str_contains($c['message'], 'Gemma De Guzman')
            && str_contains($c['message'], '0%')));
    }

    public function test_does_not_flag_a_tsa_with_too_little_history_to_call_it_a_trend(): void
    {
        // Only 1 baseline day (needs 3+) — a real drop, but not enough
        // history to call it a trend rather than a fluke.
        $this->order(['is_upsell' => true, 'pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        $this->order(['is_upsell' => true, 'pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        $this->order(['disposition' => 'CONFIRMED VIA CALL', 'pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        for ($i = 0; $i < 4; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL']);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertFalse($cards->contains(fn ($c) => str_contains($c['message'], 'Gemma De Guzman') && $c['category'] === 'TSA performance'));
    }

    public function test_does_not_flag_a_tsa_with_too_few_answered_calls_today_to_trust_the_rate(): void
    {
        foreach ([4, 3, 2] as $daysAgo) {
            for ($i = 0; $i < 2; $i++) {
                $this->order(['is_upsell' => true, 'pancake_created_at' => now()->subDays($daysAgo), 'pancake_inserted_at' => now()->subDays($daysAgo)]);
                $this->order(['disposition' => 'CONFIRMED VIA CALL', 'pancake_created_at' => now()->subDays($daysAgo), 'pancake_inserted_at' => now()->subDays($daysAgo)]);
            }
        }
        // Today: only 1 answered call (below MIN_ANSWERED_FOR_RATE = 3).
        $this->order(['disposition' => 'CONFIRMED VIA CALL']);

        $cards = (new InsightsGenerator())->generate();

        $this->assertFalse($cards->contains(fn ($c) => str_contains($c['message'], 'Gemma De Guzman') && $c['category'] === 'TSA performance'));
    }

    public function test_flags_todays_top_performer(): void
    {
        // Gemma: 100% conversion today (3 upsell, 0 confirmed-via-call).
        for ($i = 0; $i < 3; $i++) {
            $this->order(['is_upsell' => true]);
        }
        // Mariel: 0% conversion today.
        for ($i = 0; $i < 3; $i++) {
            $this->order(['tsa_name' => 'Mariel', 'disposition' => 'CONFIRMED VIA CALL']);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['severity'] === 'positive'
            && str_contains($c['message'], 'Gemma De Guzman')
            && str_contains($c['message'], 'top performer')));
    }

    // ---- Target metrics --------------------------------------------------

    public function test_flags_a_tsa_who_misses_daily_targets(): void
    {
        // 8 answered (confirmed via call), 12 not-answering — catered = 20
        // (meets the min-volume gate, misses the 75 target), pick_up_rate =
        // 8/20 = 40% (misses 60%), no upsells at all — upselling_rate = 0%
        // (misses 60%) and Qty Orders = 0 (misses 23). AOV is skipped (no
        // upsells to average).
        for ($i = 0; $i < 8; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL']);
        }
        for ($i = 0; $i < 12; $i++) {
            $this->order(['disposition' => 'NOT ANSWERING']);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['category'] === 'Target metrics'
            && str_contains($c['message'], 'Gemma De Guzman')
            && str_contains($c['message'], 'Upselling Rate')
            && str_contains($c['message'], 'Pick-up Rate')
            && str_contains($c['message'], 'Catered Leads')
            && str_contains($c['message'], 'Qty Orders')
            && !str_contains($c['message'], 'AOV')
            // All 12 unanswered are Not Answering — the dominant-cause
            // reason (explicit request, 2026-08-27: "there is many leads
            // that is unanswered that's why that got down the pick up
            // rate") should name it right on the Pick-up Rate miss.
            && str_contains($c['message'], 'driven by Not Answering (12 of 12 unanswered)')));
    }

    public function test_omits_the_pick_up_rate_reason_when_no_single_cause_dominates(): void
    {
        // Unanswered split evenly across 3 categories (4 each, 12 total) —
        // no single one reaches the 40% "dominant cause" bar, so the
        // Pick-up Rate miss stays a plain number with no "driven by" phrase.
        for ($i = 0; $i < 8; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL']);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->order(['disposition' => 'NOT ANSWERING']);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->order(['disposition' => 'INVALID NUMBER']);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->order(['disposition' => 'UNATTENDED']);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['category'] === 'Target metrics'
            && str_contains($c['message'], 'Gemma De Guzman')
            && str_contains($c['message'], 'Pick-up Rate')
            && !str_contains($c['message'], 'driven by')));
    }

    public function test_the_pick_up_rate_reason_also_names_the_dominant_product(): void
    {
        // Real EOD report, 2026-08-27: "majority ng unanswered leads ay
        // galing sa TO leads" — 10 of 12 unanswered tagged SINUXYL (83%,
        // past the 40% dominance bar) alongside the disposition reason.
        for ($i = 0; $i < 8; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL']);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->order(['disposition' => 'NOT ANSWERING', 'raw_tags' => ['SINUXYL']]);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->order(['disposition' => 'NOT ANSWERING']);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['category'] === 'Target metrics'
            && str_contains($c['message'], 'Gemma De Guzman')
            && str_contains($c['message'], 'driven by Not Answering (12 of 12 unanswered)')
            && str_contains($c['message'], 'mostly SINUXYL orders')));
    }

    public function test_does_not_flag_a_tsa_who_hits_every_target(): void
    {
        // 36 upsells (₱1000 each — AOV ₱1000 ≥ target ₱800), 18 confirmed-
        // via-call (upselling_rate = 36/(36+18) = 66.7% ≥ 60%), 24 not-
        // answering. catered = 36+18+24 = 78 ≥ 75. pick_up_rate =
        // 54/78 = 69.2% ≥ 60%. Qty Orders = 36 ≥ 23.
        for ($i = 0; $i < 36; $i++) {
            $this->order(['is_upsell' => true, 'amount' => 1000]);
        }
        for ($i = 0; $i < 18; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL']);
        }
        for ($i = 0; $i < 24; $i++) {
            $this->order(['disposition' => 'NOT ANSWERING']);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertFalse($cards->contains(fn ($c) => $c['category'] === 'Target metrics' && str_contains($c['message'], 'Gemma De Guzman')));
    }

    public function test_ignores_target_metrics_for_a_tsa_with_too_little_volume(): void
    {
        // Only 5 catered leads (below MIN_CATERED_FOR_TARGET_CHECK = 20),
        // despite missing every target on paper — not enough volume yet to
        // call it a real shortfall (could just be early in the shift).
        for ($i = 0; $i < 5; $i++) {
            $this->order(['disposition' => 'NOT ANSWERING']);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertFalse($cards->contains(fn ($c) => $c['category'] === 'Target metrics'));
    }

    // ---- Bottom performer --------------------------------------------

    public function test_flags_todays_bottom_performer(): void
    {
        // Gemma: 20% conversion (1 upsell / 5 answered) — genuinely low,
        // below the 40% floor. Mariel: 100% (comparison peer).
        $this->order(['is_upsell' => true]);
        for ($i = 0; $i < 4; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL']);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->order(['tsa_name' => 'Mariel', 'is_upsell' => true]);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['category'] === 'TSA performance'
            && $c['severity'] === 'warning'
            && str_contains($c['message'], 'Gemma De Guzman')
            && str_contains($c['message'], 'lowest')));
    }

    public function test_does_not_flag_a_bottom_performer_when_the_worst_rate_is_still_healthy(): void
    {
        // Gemma: 50% — worst of the two, but above BOTTOM_PERFORMER_MAX_RATE
        // (40%), so not a real problem, just relatively behind a strong peer.
        for ($i = 0; $i < 2; $i++) {
            $this->order(['is_upsell' => true]);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL']);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->order(['tsa_name' => 'Mariel', 'is_upsell' => true]);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertFalse($cards->contains(fn ($c) => $c['category'] === 'TSA performance' && str_contains($c['message'], 'lowest')));
    }

    // ---- Cancelled upsells ---------------------------------------------

    public function test_flags_a_tsa_with_a_real_cancellation_pattern(): void
    {
        // Real EOD report, 2026-08-27: "24 upsells sa EOD, ngunit naging 19
        // final upsells dahil sa mga cancel upsell." 19 net + 5 cancelled =
        // 24 gross, share = 5/24 = 20.8% ≥ 15%.
        for ($i = 0; $i < 19; $i++) {
            $this->order(['is_upsell' => true]);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->order(['is_cancelled_upsell' => true, 'cancelled_upsell_amount' => 800]);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['category'] === 'Cancellations'
            && str_contains($c['message'], 'Gemma De Guzman')
            && str_contains($c['message'], '24 upsells')
            && str_contains($c['message'], '5 were later cancelled')
            && str_contains($c['message'], '19 net')));
    }

    public function test_does_not_flag_cancellations_below_the_minimum_count(): void
    {
        // Only 2 cancelled — below MIN_CANCELLED_UPSELLS = 3.
        for ($i = 0; $i < 19; $i++) {
            $this->order(['is_upsell' => true]);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->order(['is_cancelled_upsell' => true, 'cancelled_upsell_amount' => 800]);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertFalse($cards->contains(fn ($c) => $c['category'] === 'Cancellations'));
    }

    public function test_does_not_flag_cancellations_below_the_share_threshold(): void
    {
        // 3 cancelled of 103 gross = 2.9%, below the 15% share bar — an
        // isolated handful against a large healthy volume, not a pattern.
        for ($i = 0; $i < 100; $i++) {
            $this->order(['is_upsell' => true]);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->order(['is_cancelled_upsell' => true, 'cancelled_upsell_amount' => 800]);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertFalse($cards->contains(fn ($c) => $c['category'] === 'Cancellations'));
    }

    // ---- Zero-sales product ---------------------------------------------

    public function test_flags_a_product_with_leads_but_no_upsells(): void
    {
        // Real EOD report action-plan goal, 2026-08-27: "Pagkakaroon ng
        // sales sa bawat product na hawak ng team."
        for ($i = 0; $i < 8; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL', 'raw_tags' => ['SINUXYL']]);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['category'] === 'Product'
            && str_contains($c['message'], 'SINUXYL')
            && str_contains($c['message'], '8 leads')
            && str_contains($c['message'], 'zero confirmed upsells')));
    }

    public function test_does_not_flag_a_product_with_too_few_leads(): void
    {
        // Only 3 leads — below MIN_LEADS_FOR_ZERO_SALES_CHECK = 5.
        for ($i = 0; $i < 3; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL', 'raw_tags' => ['SINUXYL']]);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertFalse($cards->contains(fn ($c) => $c['category'] === 'Product' && str_contains($c['message'], 'SINUXYL')));
    }

    public function test_does_not_flag_a_product_that_had_at_least_one_upsell(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL', 'raw_tags' => ['SINUXYL']]);
        }
        $this->order(['is_upsell' => true, 'raw_tags' => ['SINUXYL']]);

        $cards = (new InsightsGenerator())->generate();

        $this->assertFalse($cards->contains(fn ($c) => $c['category'] === 'Product' && str_contains($c['message'], 'SINUXYL')));
    }

    // ---- RTS rate -------------------------------------------------------

    public function test_flags_a_tsa_whose_rts_rate_is_notably_above_their_teams_average(): void
    {
        // 3 comparable SH Naturals TSAs (Gemma, Mariel, Kathleen) — Gemma's
        // RTS rate is way above the other two, who have none at all.
        $this->order(['tsa_name' => 'Gemma', 'is_upsell' => true, 'is_returned_upsell' => true, 'returned_upsell_amount' => 800, 'amount' => 800]);
        $this->order(['tsa_name' => 'Mariel', 'is_upsell' => true, 'status_code' => 3, 'amount' => 800]);
        $this->order(['tsa_name' => 'Kathleen', 'is_upsell' => true, 'status_code' => 3, 'amount' => 800]);

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['category'] === 'Returns' && str_contains($c['message'], 'Gemma De Guzman')));
    }

    public function test_does_not_flag_rts_with_fewer_than_three_comparable_tsas(): void
    {
        $this->order(['tsa_name' => 'Gemma', 'is_upsell' => true, 'is_returned_upsell' => true, 'returned_upsell_amount' => 800, 'amount' => 800]);
        $this->order(['tsa_name' => 'Mariel', 'is_upsell' => true, 'status_code' => 3, 'amount' => 800]);

        $cards = (new InsightsGenerator())->generate();

        $this->assertFalse($cards->contains(fn ($c) => $c['category'] === 'Returns'));
    }

    public function test_does_not_flag_rts_below_the_minimum_sample_size(): void
    {
        $this->order(['tsa_name' => 'Gemma', 'is_upsell' => true, 'is_returned_upsell' => true, 'returned_upsell_amount' => 50, 'amount' => 50]);
        $this->order(['tsa_name' => 'Mariel', 'is_upsell' => true, 'status_code' => 3, 'amount' => 50]);
        $this->order(['tsa_name' => 'Kathleen', 'is_upsell' => true, 'status_code' => 3, 'amount' => 50]);

        $cards = (new InsightsGenerator())->generate();

        $this->assertFalse($cards->contains(fn ($c) => $c['category'] === 'Returns'));
    }

    // ---- Team filter -----------------------------------------------------

    public function test_generate_scopes_cards_to_a_single_team_when_requested(): void
    {
        // Gemma (SH Naturals) and Julie (Eyecare Team) each get the same
        // qualifying drop shape as test_flags_a_tsa_whose_conversion_rate_
        // dropped_well_below_their_own_baseline above.
        foreach ([4, 3, 2] as $daysAgo) {
            for ($i = 0; $i < 2; $i++) {
                $this->order(['is_upsell' => true, 'pancake_created_at' => now()->subDays($daysAgo), 'pancake_inserted_at' => now()->subDays($daysAgo)]);
                $this->order(['disposition' => 'CONFIRMED VIA CALL', 'pancake_created_at' => now()->subDays($daysAgo), 'pancake_inserted_at' => now()->subDays($daysAgo)]);

                $this->order(['team' => 'Eyecare Team', 'tsa_name' => 'Julie', 'is_upsell' => true, 'pancake_created_at' => now()->subDays($daysAgo), 'pancake_inserted_at' => now()->subDays($daysAgo)]);
                $this->order(['team' => 'Eyecare Team', 'tsa_name' => 'Julie', 'disposition' => 'CONFIRMED VIA CALL', 'pancake_created_at' => now()->subDays($daysAgo), 'pancake_inserted_at' => now()->subDays($daysAgo)]);
            }
        }
        for ($i = 0; $i < 4; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL']);
            $this->order(['team' => 'Eyecare Team', 'tsa_name' => 'Julie', 'disposition' => 'CONFIRMED VIA CALL']);
        }

        $shNaturalsOnly = (new InsightsGenerator())->generate(null, 'sh-naturals');
        $eyecareOnly = (new InsightsGenerator())->generate(null, 'eyecare');
        $both = (new InsightsGenerator())->generate();

        $this->assertTrue($shNaturalsOnly->contains(fn ($c) => str_contains($c['message'], 'Gemma De Guzman')));
        $this->assertFalse($shNaturalsOnly->contains(fn ($c) => str_contains($c['message'], 'Julie')));

        $this->assertTrue($eyecareOnly->contains(fn ($c) => str_contains($c['message'], 'Julie')));
        $this->assertFalse($eyecareOnly->contains(fn ($c) => str_contains($c['message'], 'Gemma De Guzman')));

        $this->assertTrue($both->contains(fn ($c) => str_contains($c['message'], 'Gemma De Guzman')));
        $this->assertTrue($both->contains(fn ($c) => str_contains($c['message'], 'Julie')));
    }

    public function test_an_unrecognized_team_key_falls_back_to_every_team(): void
    {
        // Same shape as test_flags_todays_top_performer — reliably produces
        // a card regardless of team scoping.
        for ($i = 0; $i < 3; $i++) {
            $this->order(['is_upsell' => true]);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->order(['tsa_name' => 'Mariel', 'disposition' => 'CONFIRMED VIA CALL']);
        }

        $cards = (new InsightsGenerator())->generate(null, 'not-a-real-team');

        // Same as passing null — the controller already guards against a
        // bad key before it reaches here, but generate() itself shouldn't
        // silently return zero cards for one either.
        $this->assertTrue($cards->contains(fn ($c) => str_contains($c['message'], 'Gemma De Guzman') && str_contains($c['message'], 'top performer')));
    }

    public function test_the_team_filter_persists_across_requests_via_session(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('insights', ['team' => 'eyecare']))->assertOk();

        // No ?team= this time — should remember Eyecare from session, same
        // as the Dashboard's own filters.dashboard.team persistence.
        $this->get(route('insights'))->assertOk();

        $this->assertSame('eyecare', session('filters.insights.team'));
    }

    public function test_the_team_filter_buttons_render_with_the_selected_one_highlighted(): void
    {
        $this->actingAs($this->admin());

        $response = $this->get(route('insights', ['team' => 'eyecare']));

        $response->assertOk();
        $response->assertSee('name="team" value="eyecare"', false);
        $response->assertSee('Eyecare');
    }

    // ---- Date picker -----------------------------------------------------

    public function test_generate_reads_the_selected_date_instead_of_todays(): void
    {
        // Same drop shape as test_flags_a_tsa_whose_conversion_rate_dropped_
        // well_below_their_own_baseline above, just anchored 3 days ago
        // instead of today — nothing seeded for the real "today" at all.
        $anchor = today()->subDays(3);
        foreach ([7, 6, 5] as $daysBeforeAnchor) {
            for ($i = 0; $i < 2; $i++) {
                $this->order(['is_upsell' => true, 'pancake_created_at' => $anchor->copy()->subDays($daysBeforeAnchor), 'pancake_inserted_at' => $anchor->copy()->subDays($daysBeforeAnchor)]);
                $this->order(['disposition' => 'CONFIRMED VIA CALL', 'pancake_created_at' => $anchor->copy()->subDays($daysBeforeAnchor), 'pancake_inserted_at' => $anchor->copy()->subDays($daysBeforeAnchor)]);
            }
        }
        for ($i = 0; $i < 4; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL', 'pancake_created_at' => $anchor, 'pancake_inserted_at' => $anchor]);
        }

        $cardsForAnchorDay = (new InsightsGenerator())->generate($anchor);
        $cardsForToday = (new InsightsGenerator())->generate();

        $this->assertTrue($cardsForAnchorDay->contains(fn ($c) => $c['severity'] === 'warning' && str_contains($c['message'], 'Gemma De Guzman')));
        $this->assertFalse($cardsForToday->contains(fn ($c) => str_contains($c['message'], 'Gemma De Guzman') && $c['category'] === 'TSA performance'));
    }

    public function test_the_insights_route_accepts_a_date_from_param(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('insights', ['date_from' => today()->subDay()->toDateString()]))->assertOk();
    }

    public function test_a_future_date_is_clamped_to_today_instead_of_erroring(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('insights', ['date_from' => today()->addWeek()->toDateString()]))->assertOk();
    }

    // ---- Day-over-day trend ------------------------------------------------

    public function test_flags_a_day_over_day_new_leads_volume_jump(): void
    {
        // Yesterday: 10 plain orders (no disposition, so 'answered' stays 0
        // and conversion_rate stays null — isolates this to a pure volume
        // signal). Today: double that.
        for ($i = 0; $i < 10; $i++) {
            $this->order(['pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        }
        for ($i = 0; $i < 20; $i++) {
            $this->order();
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['category'] === 'Daily trend'
            && $c['severity'] === 'info'
            && str_contains($c['message'], '20')
            && str_contains($c['message'], '100%')));
    }

    public function test_flags_a_day_over_day_conversion_rate_drop(): void
    {
        // Yesterday: 4 upsells (100% conversion) + 6 plain padding = 10 total.
        for ($i = 0; $i < 4; $i++) {
            $this->order(['is_upsell' => true, 'pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->order(['pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        }
        // Today: 4 confirmed-via-call, no upsells (0% conversion) + 6 plain
        // padding = 10 total, same volume as yesterday so no volume card
        // fires alongside this one.
        for ($i = 0; $i < 4; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL']);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->order();
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['category'] === 'Daily trend'
            && $c['severity'] === 'warning'
            && str_contains($c['message'], 'conversion rate')
            && str_contains($c['message'], '0%')));
    }

    public function test_ignores_a_day_over_day_swing_below_the_minimum_volume_gate(): void
    {
        // Only 5 orders per day (below MIN_DAY_VOLUME = 10), despite a huge
        // swing — too little volume on either day to trust the comparison.
        for ($i = 0; $i < 5; $i++) {
            $this->order(['pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->order(['is_upsell' => true]);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertFalse($cards->contains(fn ($c) => $c['category'] === 'Daily trend'));
    }

    // ---- Week-over-week trend -----------------------------------------

    public function test_flags_a_week_over_week_new_leads_volume_jump(): void
    {
        // Last week (10 days ago, inside the 7-13-days-ago window): 40 plain
        // orders. This week (today, inside the 0-6-days-ago window): 80.
        for ($i = 0; $i < 40; $i++) {
            $this->order(['pancake_created_at' => now()->subDays(10), 'pancake_inserted_at' => now()->subDays(10)]);
        }
        for ($i = 0; $i < 80; $i++) {
            $this->order();
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['category'] === 'Weekly trend'
            && $c['severity'] === 'info'
            && str_contains($c['message'], '80')
            && str_contains($c['message'], '100%')));
    }

    public function test_flags_a_week_over_week_conversion_rate_drop(): void
    {
        // Last week: 20 upsells + 20 confirmed-via-call = 40 total, 50%
        // conversion. This week: 40 confirmed-via-call, 0 upsells = 40
        // total (same volume, so no volume card fires alongside this one),
        // 0% conversion.
        for ($i = 0; $i < 20; $i++) {
            $this->order(['is_upsell' => true, 'pancake_created_at' => now()->subDays(10), 'pancake_inserted_at' => now()->subDays(10)]);
        }
        for ($i = 0; $i < 20; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL', 'pancake_created_at' => now()->subDays(10), 'pancake_inserted_at' => now()->subDays(10)]);
        }
        for ($i = 0; $i < 40; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL']);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['category'] === 'Weekly trend'
            && $c['severity'] === 'warning'
            && str_contains($c['message'], 'conversion rate')
            && str_contains($c['message'], '0%')));
    }

    public function test_ignores_a_week_over_week_swing_below_the_minimum_volume_gate(): void
    {
        // Only 20 orders per week (below MIN_WEEK_VOLUME = 40).
        for ($i = 0; $i < 20; $i++) {
            $this->order(['pancake_created_at' => now()->subDays(10), 'pancake_inserted_at' => now()->subDays(10)]);
        }
        for ($i = 0; $i < 20; $i++) {
            $this->order(['is_upsell' => true]);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertFalse($cards->contains(fn ($c) => $c['category'] === 'Weekly trend'));
    }

    // ---- Daily recap -----------------------------------------------------

    public function test_the_daily_recap_always_shows_every_metric_given_enough_volume(): void
    {
        // Real EOD reports supplied 2026-08-27 compare Gross Sales, Orders,
        // Pick-up Rate, Upselling Rate, and AOV day-over-day as routine
        // reporting — every line should show regardless of size.
        for ($i = 0; $i < 10; $i++) {
            $this->order(['is_upsell' => true, 'amount' => 800, 'pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL', 'pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        }
        for ($i = 0; $i < 20; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL']);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['category'] === 'Daily recap'
            && str_contains($c['message'], 'New Leads')
            && str_contains($c['message'], 'Gross Sales')
            && str_contains($c['message'], 'Orders')
            && str_contains($c['message'], 'Pick-up Rate')
            && str_contains($c['message'], 'Upselling Rate')
            && str_contains($c['message'], 'AOV')));
    }

    public function test_the_daily_recap_notes_a_drop_in_working_tsa_count(): void
    {
        // Real EOD report, 2026-08-27: blamed a drop on "kulang na
        // manpower" — 3 TSAs working vs. 4 the day before. Yesterday: Gemma
        // + Mariel both worked. Today: only Gemma.
        for ($i = 0; $i < 10; $i++) {
            $this->order(['pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->order(['tsa_name' => 'Mariel', 'pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        }
        for ($i = 0; $i < 20; $i++) {
            $this->order();
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['category'] === 'Daily recap'
            && str_contains($c['message'], '1 TSA worked vs. 2 the day before')
            && str_contains($c['message'], 'fewer hands on deck')));
    }

    public function test_the_daily_recap_is_skipped_below_the_minimum_volume_gate(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->order(['pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->order();
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertFalse($cards->contains(fn ($c) => $c['category'] === 'Daily recap'));
    }

    // ---- Daily narrative ---------------------------------------------

    public function test_the_daily_narrative_appears_with_enough_volume(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->order(['pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->order();
        }

        $cards = (new InsightsGenerator())->generate();

        $narrative = $cards->firstWhere('category', 'Overview');
        $this->assertNotNull($narrative);
        $this->assertNotEmpty($narrative['message']);
    }

    public function test_the_daily_narrative_mentions_a_manpower_drop(): void
    {
        // Same shape as the daily recap's own manpower test — Gemma +
        // Mariel worked yesterday, only Gemma today.
        for ($i = 0; $i < 10; $i++) {
            $this->order(['pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->order(['tsa_name' => 'Mariel', 'pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        }
        for ($i = 0; $i < 20; $i++) {
            $this->order();
        }

        $cards = (new InsightsGenerator())->generate();

        $narrative = $cards->firstWhere('category', 'Overview');
        $this->assertStringContainsString('1', $narrative['message']);
        $this->assertMatchesRegularExpression('/2 TSAs the day before|vs\. 2 the day before|usual 2 TSAs/', $narrative['message']);
    }

    public function test_the_daily_narrative_mentions_top_and_bottom_performer(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->order(['pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        }
        // Gemma: 100% conversion today. Mariel: 0% today.
        for ($i = 0; $i < 3; $i++) {
            $this->order(['is_upsell' => true]);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->order(['tsa_name' => 'Mariel', 'disposition' => 'CONFIRMED VIA CALL']);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->order();
        }

        $cards = (new InsightsGenerator())->generate();

        $narrative = $cards->firstWhere('category', 'Overview');
        $this->assertStringContainsString('Gemma De Guzman', $narrative['message']);
        $this->assertStringContainsString('Mariel Entanto', $narrative['message']);
    }

    public function test_the_daily_narrative_mentions_target_misses(): void
    {
        // Same missed-target shape used throughout this file.
        for ($i = 0; $i < 10; $i++) {
            $this->order(['pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL']);
        }
        for ($i = 0; $i < 12; $i++) {
            $this->order(['disposition' => 'NOT ANSWERING']);
        }

        $cards = (new InsightsGenerator())->generate();

        $narrative = $cards->firstWhere('category', 'Overview');
        $this->assertMatchesRegularExpression('/1 TSA missed|missed .* by 1 TSA/', $narrative['message']);
    }

    public function test_the_daily_narrative_is_stable_across_repeated_calls_for_the_same_day(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->order(['pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->order();
        }

        $first = (new InsightsGenerator())->generate()->firstWhere('category', 'Overview')['message'];
        $second = (new InsightsGenerator())->generate()->firstWhere('category', 'Overview')['message'];

        $this->assertSame($first, $second);
    }

    public function test_the_daily_narrative_is_skipped_below_the_minimum_volume_gate(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->order(['pancake_created_at' => now()->subDay(), 'pancake_inserted_at' => now()->subDay()]);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->order();
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertNull($cards->firstWhere('category', 'Overview'));
    }

    // ---- Peak excess hour: which shift -----------------------------------

    public function test_names_the_shift_a_peak_hours_excess_mostly_comes_from(): void
    {
        // Plain orders (no disposition, not an upsell) count fully as
        // Excess — catered stays 0 for them, so total - catered = count.
        // All at 2pm: 20 from Gemma, 5 from Julie — Gemma is 20/25 = 80% of
        // that hour's Excess, comfortably past the 40% "dominates" bar.
        for ($i = 0; $i < 20; $i++) {
            $this->order(['pancake_created_at' => today()->setTime(14, 0), 'pancake_inserted_at' => today()->setTime(14, 0)]);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->order(['team' => 'Eyecare Team', 'tsa_name' => 'Julie', 'pancake_created_at' => today()->setTime(14, 0), 'pancake_inserted_at' => today()->setTime(14, 0)]);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['category'] === 'Timing'
            && str_contains($c['message'], 'peak around')
            && str_contains($c['message'], "mostly from Gemma De Guzman's shift")
            && str_contains($c['message'], '20 of 25')));
    }

    public function test_says_entirely_when_only_one_shift_contributes(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->order(['pancake_created_at' => today()->setTime(14, 0), 'pancake_inserted_at' => today()->setTime(14, 0)]);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['category'] === 'Timing' && str_contains($c['message'], "entirely from Gemma De Guzman's shift")));
    }

    public function test_omits_the_shift_phrase_when_no_single_shift_dominates(): void
    {
        // 8/8/9 across three TSAs at the same hour — the top is only 9/25 =
        // 36%, below the 40% "dominates" bar, so no shift gets singled out.
        for ($i = 0; $i < 8; $i++) {
            $this->order(['pancake_created_at' => today()->setTime(14, 0), 'pancake_inserted_at' => today()->setTime(14, 0)]);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->order(['tsa_name' => 'Mariel', 'pancake_created_at' => today()->setTime(14, 0), 'pancake_inserted_at' => today()->setTime(14, 0)]);
        }
        for ($i = 0; $i < 9; $i++) {
            $this->order(['team' => 'Eyecare Team', 'tsa_name' => 'Julie', 'pancake_created_at' => today()->setTime(14, 0), 'pancake_inserted_at' => today()->setTime(14, 0)]);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['category'] === 'Timing'
            && str_contains($c['message'], 'peak around')
            && !str_contains($c['message'], 'shift')));
    }

    // ---- Action plan -------------------------------------------------

    public function test_a_warning_card_carries_a_concrete_action(): void
    {
        // Same missed-target shape as test_flags_a_tsa_who_misses_daily_
        // targets — an easy, reliable way to get a 'Target metrics' card.
        for ($i = 0; $i < 8; $i++) {
            $this->order(['disposition' => 'CONFIRMED VIA CALL']);
        }
        for ($i = 0; $i < 12; $i++) {
            $this->order(['disposition' => 'NOT ANSWERING']);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['category'] === 'Target metrics'
            && $c['action'] !== null
            && str_contains($c['action'], 'Gemma De Guzman')
            && str_contains($c['action'], 'coaching')));
    }

    public function test_a_positive_card_also_carries_an_action(): void
    {
        // Same shape as test_flags_todays_top_performer — a positive card
        // still gets an action (recognition), not just warnings.
        for ($i = 0; $i < 3; $i++) {
            $this->order(['is_upsell' => true]);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->order(['tsa_name' => 'Mariel', 'disposition' => 'CONFIRMED VIA CALL']);
        }

        $cards = (new InsightsGenerator())->generate();

        $this->assertTrue($cards->contains(fn ($c) => $c['severity'] === 'positive'
            && str_contains($c['message'], 'top performer')
            && $c['action'] !== null
            && str_contains($c['action'], 'Recognize')));
    }

    public function test_the_action_plan_view_only_shows_cards_that_have_an_action(): void
    {
        $this->actingAs($this->admin());

        $response = $this->get(route('insights', ['view' => 'action-plan']));

        $response->assertOk();
        $response->assertDontSee('Nothing worth flagging right now.');
    }

    public function test_the_view_toggle_persists_across_requests_via_session(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('insights', ['view' => 'action-plan']))->assertOk();
        $this->get(route('insights'))->assertOk();

        $this->assertSame('action-plan', session('filters.insights.view'));
    }

    public function test_an_unrecognized_view_falls_back_to_insights(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('insights', ['view' => 'not-a-real-view']))->assertOk();

        $this->assertSame('insights', session('filters.insights.view'));
    }

    // ---- Empty state -----------------------------------------------------

    public function test_shows_an_empty_state_when_nothing_is_worth_flagging(): void
    {
        $this->actingAs($this->admin());

        $response = $this->get(route('insights'));

        $response->assertOk();
        $response->assertSee('Nothing worth flagging right now.');
    }
}
