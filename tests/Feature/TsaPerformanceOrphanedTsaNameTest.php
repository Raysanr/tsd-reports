<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Root-caused 2026-08-21 (user report: TSA Performance's Grand Total didn't
 * tally with Dashboard/Leads Report for the same range). Order.tsa_name is a
 * snapshot written at sync time, not a live foreign key — if the TSA it
 * named is later renamed or removed from tsa_shifts, that order's tsa_name
 * no longer matches any current roster key, but it also isn't NULL, so it
 * doesn't qualify for the existing "Unassigned" bucket either (that only
 * ever catches tsa_name IS NULL — see TsaPerformanceUnassignedRowTest).
 * Dashboard/Leads Report never look at tsa_name at all (team+date only), so
 * they keep counting it; TSA Performance's per-TSA + Unassigned rows have
 * nowhere to put it, so it silently vanishes from every visible row while
 * still counting in whatever total does read the raw order set — Grand
 * Total ends up LOWER than the sum "should" be, and lower than Dashboard's
 * own number for the same range.
 *
 * Fix: a non-null tsa_name that doesn't match any TSA CURRENTLY on the
 * relevant roster falls back to the Unassigned bucket too (for a team-scoped
 * view: any roster, anywhere — because a tsa_name valid on a DIFFERENT team's
 * roster legitimately belongs to that team's own page, not this one's
 * Unassigned bucket; only a tsa_name matching NO team's roster at all counts
 * as orphaned for this purpose).
 */
class TsaPerformanceOrphanedTsaNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function orphanedOrder(string $date, string $team = 'SH Naturals'): void
    {
        Order::create([
            'pancake_order_id' => 'orphaned-1', 'team' => $team,
            'tsa_name' => 'RenamedOrRemovedTsa', 'disposition' => 'CONFIRMED VIA CALL',
            'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);
    }

    public function test_single_team_view_falls_back_the_orphaned_order_to_unassigned_instead_of_dropping_it(): void
    {
        $date = '2026-08-04';
        $this->orphanedOrder($date);

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date_from' => $date, 'date_to' => $date]));

        $response->assertOk();
        $tsaRows    = $response->viewData('tsaRows');
        $grandTotal = $response->viewData('grandTotal');

        $unassigned = $tsaRows->firstWhere('tsa_key', 'unassigned');
        $this->assertNotNull($unassigned, 'orphaned tsa_name must still surface as a visible row');
        $this->assertSame(1, $unassigned['total']);
        $this->assertSame($grandTotal['total'], $tsaRows->sum('total'));
        $this->assertSame(1, $grandTotal['total']);
    }

    public function test_all_view_grand_total_still_equals_the_sum_of_the_rows_with_an_orphaned_order(): void
    {
        $date = '2026-08-04';
        $this->orphanedOrder($date);

        $response = $this->get(route('tsa-performance', ['team' => 'all', 'date_from' => $date, 'date_to' => $date]));

        $response->assertOk();
        $tsaRows    = $response->viewData('tsaRows');
        $grandTotal = $response->viewData('grandTotal');

        $this->assertSame(1, $grandTotal['total']);
        $this->assertSame($grandTotal['total'], $tsaRows->sum('total'), 'ALL view rows must sum back to Grand Total');
    }

    public function test_a_tsa_name_belonging_to_a_different_teams_roster_is_not_treated_as_orphaned(): void
    {
        // Julie is a real Eyecare TSA. An order tagged with her name but filed
        // under SH Naturals must NOT land in SH Naturals' Unassigned bucket —
        // she's a known TSA, just not on THIS team's roster. (What team's page
        // she legitimately belongs on is a separate, pre-existing question this
        // test doesn't take a position on — it only asserts she doesn't get
        // mislabeled "Unassigned" on SH Naturals' page.)
        $date = '2026-08-04';
        Order::create([
            'pancake_order_id' => 'cross-team-1', 'team' => 'SH Naturals',
            'tsa_name' => 'Julie', 'disposition' => 'CONFIRMED VIA CALL',
            'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date_from' => $date, 'date_to' => $date]));

        $response->assertOk();
        $tsaRows = $response->viewData('tsaRows');
        $unassigned = $tsaRows->firstWhere('tsa_key', 'unassigned');

        $this->assertNull($unassigned, 'a real TSA on another team should not be relabeled Unassigned here');
    }
}
