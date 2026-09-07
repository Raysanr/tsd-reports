<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-09-07, fourth revision — see LeadsReportController's
 * own shift-window comment for the full history): every product must be a
 * browsable row on every team's page, same as TsaPerformanceController's
 * earlier fix. This is combined with the match pool already being scoped to
 * this page's own hour window ($matchPool uses where('team', $orderTeam)),
 * so a foreign-team product's row is usually a real 0 — it only shows
 * nonzero when a genuine cross-team explicit match exists (a same-team-hour
 * order whose own item, or a bundle it carries, names that product). Grand
 * Total stays scoped to same-team rows only (see LeadsReportGrandTotalTest),
 * so this test only covers row *visibility*, not the total.
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

        // A genuine Pterygium sale made during SH Naturals' own hour window
        // (team is hour-derived, so this order's team is legitimately SH
        // Naturals even though its item belongs to Eyecare).
        Order::create([
            'pancake_order_id' => 'cross-team-row-1', 'team' => 'SH Naturals', 'tsa_name' => $shShift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Pterygium', 'base_product' => 'Pterygium',
            'raw_tags' => [strtoupper($shShift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        $today = now()->toDateString();

        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', fn ($tables) => $tables->contains(
            fn ($t) => $t['product']->id === $eyecareProduct->id && $t['total']['total'] === 1
        ));
    }
}
