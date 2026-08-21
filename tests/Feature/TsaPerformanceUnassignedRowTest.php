<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-11): rows must sum back to Grand Total on every
 * TSA Performance view (single-team flat summary, single-team hourly, and
 * ALL) — reported live as SH Naturals showing 73 + 43 + 0 = 116 against a
 * Grand Total of 117 for 2026-08-07, the +1 being a lead that matched an SH
 * Naturals product but was never claimed by any TSA (team set, tsa_name
 * null). An earlier explicit request had removed the visible "Unassigned"
 * row for exactly this bucket ("a row with nothing visible in any column
 * was just noise") — restored here since that reasoning doesn't hold once
 * the row is guaranteed non-empty (these tests only ever create it when the
 * bucket has real orders).
 */
class TsaPerformanceUnassignedRowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /** Same repro shape as the live report: two claimed orders + one
     *  matching the team's products but with no TSA tag at all. */
    private function seedOrdersWithOneUnclaimed(string $date): void
    {
        Order::factory()->create([
            'pancake_order_id' => 'gemma-1', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);
        Order::factory()->create([
            'pancake_order_id' => 'mariel-1', 'team' => 'SH Naturals', 'tsa_name' => 'Mariel',
            'disposition' => 'NOT ANSWERING', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 11:00:00', 'synced_at' => now(),
        ]);
        // The +1: matched to the team by product, but tsa_name is null —
        // Fix #15's "swept by the midnight UNCATERED LEADS bulk action"
        // case, or simply never claimed yet.
        Order::factory()->create([
            'pancake_order_id' => 'unclaimed-1', 'team' => 'SH Naturals', 'tsa_name' => null,
            'disposition' => 'NOT ANSWERING', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 12:00:00', 'synced_at' => now(),
        ]);
    }

    public function test_single_team_flat_summary_rows_sum_to_grand_total_with_an_unclaimed_order(): void
    {
        $date = '2026-08-07';
        $this->seedOrdersWithOneUnclaimed($date);

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date_from' => $date, 'date_to' => $date]));

        $response->assertOk();

        $tsaRows = $response->viewData('tsaRows');

        $unassignedRow = $tsaRows->firstWhere('tsa_key', 'unassigned');
        $this->assertNotNull($unassignedRow, 'Expected an Unassigned row when a claimed-team, no-TSA order exists.');
        $this->assertSame('Unassigned', $unassignedRow['display_name']);
        $this->assertSame(1, $unassignedRow['not_answering']);

        // $tsaRows counts every order regardless of product match; Grand
        // Total (2026-08-21, explicit request) is a separate, product-
        // matched figure so it tallies with Dashboard/Leads Report instead
        // — see TsaPerformanceOrphanedTsaNameTest's class doc comment for
        // the full trade-off. These three orders don't set a real product,
        // so they no longer necessarily equal Grand Total.
        $this->assertSame(3, $tsaRows->sum('catered'));
    }

    /** Proves the guard in tsa-performance.blade.php is load-bearing, not
     *  cosmetic — hitting the individual-TSA route directly with the
     *  'unassigned' key 404s, since no real tsa_shifts row exists for it. */
    public function test_unassigned_key_has_no_real_individual_page(): void
    {
        $response = $this->get(route('tsa-performance.individual', [
            'team' => 'sh-naturals', 'tsaKey' => 'unassigned',
        ]));

        $response->assertNotFound();
    }

    public function test_single_team_view_does_not_link_the_unassigned_row_to_a_broken_page(): void
    {
        $date = '2026-08-07';
        $this->seedOrdersWithOneUnclaimed($date);

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date_from' => $date, 'date_to' => $date]));

        $response->assertOk();
        $response->assertDontSee(
            route('tsa-performance.individual', ['team' => 'sh-naturals', 'tsaKey' => 'unassigned', 'date_from' => $date, 'date_to' => $date]),
            false
        );
        // Still drills down at the cell level, just not a page link on the name.
        $response->assertSee('data-dd-tsa="unassigned"', false);
    }

    public function test_all_view_shows_a_per_team_unassigned_row_and_still_tallies(): void
    {
        $date = '2026-08-07';
        $this->seedOrdersWithOneUnclaimed($date);

        $response = $this->get(route('tsa-performance', ['team' => 'all', 'date_from' => $date, 'date_to' => $date]));

        $response->assertOk();

        $tsaRows = $response->viewData('tsaRows');

        $unassignedRow = $tsaRows->first(fn ($row) => $row['tsa_key'] === 'unassigned' && $row['team_key'] === 'sh-naturals');
        $this->assertNotNull($unassignedRow, 'Expected an SH Naturals Unassigned row in the ALL view.');

        // See the single-team test above for why this no longer checks
        // against Grand Total (now a separate, product-matched figure).
        $this->assertSame(3, $tsaRows->sum('catered'));
    }
}
