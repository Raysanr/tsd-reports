<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug fix (2026-09-07) — same root cause as
 * TsaPerformanceCrossTeamUpsellVisibilityTest: every TSA can now handle
 * every product, so a team's Leads Report page must show every product's
 * row (browsable), not just its own team's products, or a genuine
 * cross-team upsell has no row to appear in at all. Grand Total stays
 * scoped to the page's own team (see LeadsReportGrandTotalTest) — this
 * test only covers row *visibility*.
 */
class LeadsReportCrossTeamProductVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_an_eyecare_product_gets_its_own_row_on_the_sh_naturals_page(): void
    {
        $shShift = TsaShift::where('team', 'SH Naturals')->first();
        $eyecareProduct = Product::where('team', 'Eyecare Team')->where('display_name', 'PTERYGIUM')->first();

        Order::create([
            'pancake_order_id' => 'cross-team-row-1', 'team' => 'SH Naturals', 'tsa_name' => $shShift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'LUMICARE OIL',
            'base_product' => $eyecareProduct->match_keyword,
            'raw_tags' => [strtoupper($shShift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => true, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        $today = now()->toDateString();

        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', fn ($tables) => $tables->contains(
            fn ($t) => $t['product']->id === $eyecareProduct->id
        ));
    }
}
