<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-08): the single-team pages (SH Naturals, Eyecare)
 * now also carry the same one-row-per-TSA + Grand Total summary the ALL view
 * shows (indexAll()'s $tsaRows/$grandTotal), rendered above the existing
 * hourly breakdown rather than replacing it — see TsaPerformanceController::
 * index()'s own $tsaRows/$grandTotal comment.
 */
class TsaPerformanceFlatSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_single_team_view_carries_a_tsa_rows_and_grand_total_matching_the_hourly_totals(): void
    {
        $date = '2026-08-04';

        Order::factory()->create([
            'pancake_order_id' => 'gemma-1', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'product' => 'Sinuxyl', 'raw_tags' => ['SINUXYL', 'CONFIRMED VIA CALL'],
            'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);
        Order::factory()->create([
            'pancake_order_id' => 'mariel-1', 'team' => 'SH Naturals', 'tsa_name' => 'Mariel',
            'product' => 'Sinuxyl', 'raw_tags' => ['SINUXYL', 'NOT ANSWERING'],
            'disposition' => 'NOT ANSWERING', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 14:00:00', 'synced_at' => now(),
        ]);

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date_from' => $date, 'date_to' => $date]));

        $response->assertOk();
        $response->assertViewIs('tsa-performance');

        $tsaRows    = $response->viewData('tsaRows');
        $grandTotal = $response->viewData('grandTotal');
        $totals     = $response->viewData('totals');

        $gemmaRow  = $tsaRows->firstWhere('tsa_key', 'Gemma');
        $marielRow = $tsaRows->firstWhere('tsa_key', 'Mariel');

        $this->assertSame(1, $gemmaRow['confirmed_via_call']);
        $this->assertSame(1, $marielRow['not_answering']);
        $this->assertSame('sh-naturals', $gemmaRow['team_key']);

        // $totals (the hourly view's own accumulator) counts every order
        // regardless of product match; Grand Total (2026-08-21, explicit
        // request) is a separate, product-matched figure so it tallies with
        // Dashboard/Leads Report instead (see TsaPerformanceController::
        // index()'s own comment) — both orders here match a real tracked
        // product (SINUXYL), so the two coincide for this scenario, but
        // they're no longer the SAME definition in general.
        $this->assertSame($totals['total_called'], $grandTotal['total_called']);
        $this->assertSame(2, $grandTotal['total_called']);
    }

    /** The flat summary's rows render with their own drilldown context, same
     *  as the hourly table's cells — proves it's actually wired, not just
     *  present in the view data. */
    public function test_flat_summary_row_renders_with_drilldown_attributes(): void
    {
        $date = '2026-08-04';

        Order::factory()->create([
            'pancake_order_id' => 'gemma-1', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date_from' => $date, 'date_to' => $date]));

        $response->assertOk();
        $response->assertSee('id="tsaPerfFlatSummaryTable"', false);
        $response->assertSee('data-dd-tsa="Gemma"', false);
        $response->assertSee('data-dd-column="confirmed_via_call"', false);
    }
}
