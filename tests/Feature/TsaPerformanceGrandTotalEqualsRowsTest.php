<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-22): "make it the grand total equal the sum of
 * rows" — found live on the ALL view by hand-summing the Catered Leads
 * column (25+14+1+3+9+5+39+43+2 = 141) against a Grand Total of 145. Root
 * cause: Grand Total was computed via a SEPARATE product-matched pass
 * (ProductPerformance::sumRows over per-product buildRow() rows), not the
 * $tsaRows already sitting right above it on the same page — a combo order
 * matching 2+ products could count twice in that product pass while only
 * once in the one TSA row that handled it, or an order matching no tracked
 * product at all could count in $tsaRows but not in Grand Total. Fix: Grand
 * Total is $tsaRows summed, full stop — correct by construction since every
 * order lands in exactly one row.
 */
class TsaPerformanceGrandTotalEqualsRowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /** A single order that matches TWO different products at once (a combo)
     *  is exactly the scenario that used to make Grand Total disagree with
     *  the sum of the rows — it must count once in $tsaRows (one TSA
     *  handled it) and Grand Total must match that, not double it. */
    public function test_all_view_grand_total_equals_the_sum_of_its_own_rows_even_with_a_combo_order(): void
    {
        $date = '2026-08-22';

        Order::factory()->create([
            'pancake_order_id' => 'combo-1', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'product' => 'Sinuxyl', 'bundle_description' => 'Sinuxyl + Pterygium combo',
            'raw_tags' => ['SINUXYL', 'PTERYGIUM', 'CONFIRMED VIA CALL'],
            'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);
        Order::factory()->create([
            'pancake_order_id' => 'unmatched-1', 'team' => 'Eyecare Team', 'tsa_name' => 'Joana',
            'product' => 'Some Untracked Product', 'raw_tags' => ['NOT ANSWERING'],
            'disposition' => 'NOT ANSWERING', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 11:00:00', 'synced_at' => now(),
        ]);

        $response = $this->get(route('tsa-performance', ['team' => 'all', 'date_from' => $date, 'date_to' => $date]));

        $response->assertOk();

        $tsaRows    = $response->viewData('tsaRows');
        $grandTotal = $response->viewData('grandTotal');

        foreach (['total', 'catered', 'total_called', 'answered', 'unanswered'] as $key) {
            $this->assertSame($tsaRows->sum($key), $grandTotal[$key], "Grand Total['{$key}'] must equal the sum of \$tsaRows['{$key}']");
        }
        $this->assertSame(2, $grandTotal['total']);
    }

    /** Same invariant on a single-team page, not just the ALL view. */
    public function test_single_team_view_grand_total_equals_the_sum_of_its_own_rows(): void
    {
        $date = '2026-08-22';

        Order::factory()->create([
            'pancake_order_id' => 'sh-1', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'product' => 'Sinuxyl', 'raw_tags' => ['SINUXYL', 'PTERYGIUM', 'CONFIRMED VIA CALL'],
            'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);
        Order::factory()->create([
            'pancake_order_id' => 'sh-2', 'team' => 'SH Naturals', 'tsa_name' => null,
            'product' => 'Untracked', 'raw_tags' => ['NOT ANSWERING'],
            'disposition' => 'NOT ANSWERING', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 11:00:00', 'synced_at' => now(),
        ]);

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date_from' => $date, 'date_to' => $date]));

        $response->assertOk();

        $tsaRows    = $response->viewData('tsaRows');
        $grandTotal = $response->viewData('grandTotal');

        $this->assertSame($tsaRows->sum('total'), $grandTotal['total']);
        $this->assertSame(2, $grandTotal['total']);
    }
}
