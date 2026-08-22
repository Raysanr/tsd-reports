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
 * Fix: a non-null tsa_name that doesn't match any TSA on THIS view's own
 * roster falls back to the Unassigned bucket too.
 *
 * Revised same day (explicit follow-up request: SH Naturals' total +
 * Eyecare's total must equal ALL's total, matching Dashboard/Leads Report
 * exactly the same way): every view — index(), indexAll(), drilldown() —
 * now partitions strictly by an order's own `team` column, the same field
 * Dashboard/Leads Report already use, not by which team a TSA happens to be
 * registered under. Previously, an order was credited to its tsa_name's OWN
 * roster team regardless of the order's own `team` (deliberately, so a TSA
 * wouldn't lose credit for a cross-team combo order she genuinely closed) —
 * but that meant a single order could be included in one team's page via
 * the roster-trust branch while ALSO belonging to a different team's page
 * by its own `team` field, breaking the "the three team views sum to ALL"
 * invariant Dashboard/Leads Report now guarantee. Strict team-column
 * partitioning is the only way to guarantee both "no order counted twice"
 * and "no order silently dropped" at once.
 *
 * Revised again same day (explicit follow-up: Catered Leads/Grand Total
 * here must ALSO tally with Dashboard/Leads Report): Grand Total became a
 * sum of product-matched rows (see index()'s own comment at the time), a
 * genuinely DIFFERENT figure than $tsaRows->sum() — these tests' orphaned/
 * cross-team orders don't set a real product, so they no longer contributed
 * to Grand Total at all under that definition.
 *
 * Reverted 2026-08-22 (explicit user request: "make it the grand total
 * equal the sum of rows" — found by hand-summing this page's own Catered
 * Leads column against Grand Total and getting a different answer): Grand
 * Total is $tsaRows summed again, product match or not — see index()'s own
 * current comment. The orphaned/cross-team orders below are back to
 * contributing to Grand Total via their Unassigned row, same as any other
 * order.
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
        $tsaRows = $response->viewData('tsaRows');

        $unassigned = $tsaRows->firstWhere('tsa_key', 'unassigned');
        $this->assertNotNull($unassigned, 'orphaned tsa_name must still surface as a visible row');
        $this->assertSame(1, $unassigned['total']);
    }

    public function test_all_view_also_falls_back_the_orphaned_order_to_unassigned(): void
    {
        $date = '2026-08-04';
        $this->orphanedOrder($date);

        $response = $this->get(route('tsa-performance', ['team' => 'all', 'date_from' => $date, 'date_to' => $date]));

        $response->assertOk();
        $unassigned = $response->viewData('tsaRows')->firstWhere('tsa_key', 'unassigned');
        $this->assertNotNull($unassigned, 'orphaned tsa_name must still surface as a visible row on the ALL view too');
        $this->assertSame(1, $unassigned['total']);
    }

    public function test_a_tsa_name_belonging_to_a_different_teams_roster_is_now_treated_as_unassigned_here(): void
    {
        // Julie is a real Eyecare TSA. An order FILED under SH Naturals (its own
        // `team` column) but tagged with her name must land in SH Naturals'
        // Unassigned bucket — her own roster team no longer overrides the
        // order's own team column (see this file's class doc comment for why:
        // crediting her under SH Naturals here, or under Eyecare via the ALL
        // view's per-team loop, would count this ONE order on two different
        // team pages, breaking "SH Naturals + Eyecare = ALL").
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

        $this->assertNotNull($unassigned, 'a TSA not on this team\'s own roster falls back to Unassigned here');
        $this->assertSame(1, $unassigned['total']);

        // And she must NOT also pick up credit on her own team's page — the
        // order's `team` column says SH Naturals, so Eyecare's page (which
        // strictly follows that column too) never sees it at all.
        $eyecareResponse = $this->get(route('tsa-performance', ['team' => 'eyecare', 'date_from' => $date, 'date_to' => $date]));
        $eyecareResponse->assertOk();
        $julieRow = $eyecareResponse->viewData('tsaRows')->firstWhere('tsa_key', 'Julie');
        $this->assertSame(0, $julieRow['total'] ?? 0);
    }

    public function test_sh_naturals_plus_eyecare_grand_totals_equal_the_all_views_grand_total(): void
    {
        $date = '2026-08-04';

        Order::create([
            'pancake_order_id' => 'sh-plain', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'product' => 'Sinuxyl', 'raw_tags' => ['SINUXYL', 'CONFIRMED VIA CALL'],
            'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'eye-plain', 'team' => 'Eyecare Team', 'tsa_name' => 'Joana',
            'product' => 'Pterygium', 'raw_tags' => ['PTERYGIUM', 'CONFIRMED VIA CALL'],
            'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);
        // Plus the two trickier shapes this file already covers individually
        // (an orphaned tsa_name, and a real TSA credited on the wrong team's
        // order) — neither sets a real product, but both still land in an
        // Unassigned row and so still contribute to Grand Total now;
        // included here to prove they don't break the additivity of the
        // two plain ones above.
        $this->orphanedOrder($date);
        Order::create([
            'pancake_order_id' => 'cross-team-2', 'team' => 'SH Naturals',
            'tsa_name' => 'Julie', 'disposition' => 'CONFIRMED VIA CALL',
            'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);

        $sh   = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date_from' => $date, 'date_to' => $date]));
        $eye  = $this->get(route('tsa-performance', ['team' => 'eyecare', 'date_from' => $date, 'date_to' => $date]));
        $all  = $this->get(route('tsa-performance', ['team' => 'all', 'date_from' => $date, 'date_to' => $date]));

        $sh->assertOk();
        $eye->assertOk();
        $all->assertOk();

        $shTotal  = $sh->viewData('grandTotal')['total'];
        $eyeTotal = $eye->viewData('grandTotal')['total'];
        $allTotal = $all->viewData('grandTotal')['total'];

        // 4 = the 2 plain orders + the orphaned order + the cross-team order
        // — all four now land somewhere in $tsaRows (two real TSA rows, two
        // Unassigned rows), so all four contribute to Grand Total.
        $this->assertSame(4, $allTotal);
        $this->assertSame($allTotal, $shTotal + $eyeTotal);
    }
}
