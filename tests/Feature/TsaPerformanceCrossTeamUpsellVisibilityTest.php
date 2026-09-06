<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug fix (2026-09-06) — every TSA now handles every product (see
 * ExpandProductRosterToAllTsas), so a TSA can genuinely upsell a product
 * that belongs to a DIFFERENT team than her own. The Per-Product Hourly
 * Breakdown on showTsa() used to only build a column for the TSA's own
 * team's products, so a real cross-team upsell had nowhere to appear —
 * confirmed live: Joana (moved to SH Naturals) had 7 real upsells one day,
 * 6 of them for Pterylief Eye Drops (an Eyecare product); the Day Total
 * above correctly counted all 7, but this table only showed the 1 SH
 * Naturals one.
 */
class TsaPerformanceCrossTeamUpsellVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_a_cross_team_upsell_gets_its_own_product_column_on_the_tsas_individual_page(): void
    {
        // Joana moved to SH Naturals in the real incident this reproduces —
        // seed data has her on Eyecare Team by default, so move her here to
        // match the real shape: an SH Naturals TSA upselling an Eyecare
        // product.
        $joana = TsaShift::where('tsa_key', 'Joana')->first();
        $joana->update(['team' => 'SH Naturals']);

        $eyecareProduct = Product::where('team', 'Eyecare Team')->where('display_name', 'PTERYGIUM')->first();
        $hour = now('Asia/Manila')->setTime(10, 0)->startOfHour();

        Order::create([
            'pancake_order_id' => 'cross-team-upsell-1', 'team' => 'SH Naturals', 'tsa_name' => 'Joana',
            'is_upsell' => true, 'amount' => 1000.0, 'status_code' => 2,
            'product' => 'LUMICARE OIL', 'base_product' => $eyecareProduct->match_keyword,
            'pancake_created_at' => $hour->copy()->addMinutes(5), 'synced_at' => now(),
        ]);

        $team = collect(config('teams'))->search(fn ($t) => $t['order_team'] === 'SH Naturals');

        $response = $this->get(route('tsa-performance.individual', [
            'team' => $team, 'tsaKey' => 'Joana',
            'date_from' => $hour->toDateString(), 'date_to' => $hour->toDateString(),
        ]));

        $response->assertOk();

        // The cross-team product now has its own column at all.
        $response->assertViewHas('products', fn ($products) => $products->contains('id', $eyecareProduct->id));

        // And the upsell actually landed in that column's count, not lost.
        $response->assertViewHas('productHourlyRows', function ($rows) use ($eyecareProduct) {
            $row = collect($rows)->first();
            return $row && ($row['counts'][$eyecareProduct->id] ?? 0) === 1;
        });

        // The Day Total (summary) already counted this correctly before the
        // fix too — confirming this fix didn't change that separate number.
        $response->assertViewHas('summary', fn ($summary) => $summary['upsell_confirmation'] === 1);
    }
}
