<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TsaPerformanceAllViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    // Coverage gap found while extending dark mode to the report pages (Part 2a):
    // every other TSA Performance/Leads Report view already had a Feature test
    // hitting it, but team=all — which renders tsa-performance-all.blade.php
    // instead of tsa-performance.blade.php (see TsaPerformanceController::indexAll)
    // — did not. Just proves the page renders; not a styling-specific test.
    public function test_team_all_renders_the_all_tsas_view_without_error(): void
    {
        $response = $this->get(route('tsa-performance', ['team' => 'all']));

        $response->assertOk();
        $response->assertViewIs('tsa-performance-all');
    }

    /**
     * Explicit request: ALL's grand total used to exclude leads with no TSA
     * assigned yet, while each team's own page already folded those same
     * leads into ITS total (no visible "Unassigned" row since an earlier
     * request removed that, but the leads still counted) — so ALL's total ran
     * lower than SH Naturals' total + Eyecare's total for the same day. Both
     * now use the same inclusive definition, so the three filters tally.
     */
    public function test_all_view_grand_total_equals_the_sum_of_each_teams_own_total(): void
    {
        $date = '2026-08-04';

        // Claimed by a real TSA on each team.
        Order::factory()->create([
            'pancake_order_id' => 'sh-claimed', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);
        Order::factory()->create([
            'pancake_order_id' => 'eye-claimed', 'team' => 'Eyecare Team', 'tsa_name' => 'Joana',
            'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);
        // Never claimed by any TSA — must still count toward both the team
        // page's total AND (now) ALL's grand total.
        Order::factory()->create([
            'pancake_order_id' => 'sh-unclaimed', 'team' => 'SH Naturals', 'tsa_name' => null,
            'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 11:00:00', 'synced_at' => now(),
        ]);
        Order::factory()->create([
            'pancake_order_id' => 'eye-unclaimed', 'team' => 'Eyecare Team', 'tsa_name' => null,
            'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 11:00:00', 'synced_at' => now(),
        ]);

        $shNaturals = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date_from' => $date, 'date_to' => $date]));
        $eyecare    = $this->get(route('tsa-performance', ['team' => 'eyecare', 'date_from' => $date, 'date_to' => $date]));
        $all        = $this->get(route('tsa-performance', ['team' => 'all', 'date_from' => $date, 'date_to' => $date]));

        $shTotal  = $shNaturals->viewData('totals')['total_called'];
        $eyeTotal = $eyecare->viewData('totals')['total_called'];
        $allTotal = $all->viewData('grandTotal')['total_called'];

        $this->assertSame(2, $shTotal);
        $this->assertSame(2, $eyeTotal);
        $this->assertSame($shTotal + $eyeTotal, $allTotal);
    }

    /** Each row's drilldown context (team, tsa) lives on its own <tr> — not a
     *  single table-wide attribute — since this view combines rows from every
     *  team into one table. Confirms both the per-row team attribute and a
     *  real disposition cell actually render as clickable/wired. */
    public function test_a_tsa_row_carries_its_own_teams_drilldown_context(): void
    {
        $date = '2026-08-04';

        Order::factory()->create([
            'pancake_order_id' => 'sh-1', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);

        $response = $this->get(route('tsa-performance', ['team' => 'all', 'date_from' => $date, 'date_to' => $date]));

        $response->assertOk();
        $response->assertSee('data-dd-team="sh-naturals"', false);
        $response->assertSee('data-dd-tsa="Gemma"', false);
        $response->assertSee('data-dd-column="confirmed_via_call"', false);
    }
}
