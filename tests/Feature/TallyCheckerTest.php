<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Data Management's own Tally Checker (explicit request, 2026-10-12:
 * "add TALLY CHECKER BUTTON ... show is all tally in the expected
 * income, summary sales report, leads report module"). See
 * TallyCheckerController's own class doc comment for exactly what each
 * check does and why Net Income is informational-only, not a pass/fail.
 */
class TallyCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_normal_user_can_run_the_check(): void
    {
        $normal = User::factory()->create(['role' => 'normal']);

        $response = $this->actingAs($normal)->getJson(route('data.tally-checker', [
            'date_from' => '2026-10-10', 'date_to' => '2026-10-10',
        ]));

        $response->assertOk();
        $response->assertJsonStructure(['dateFrom', 'dateTo', 'teams']);
    }

    /** With no real orders seeded, every check trivially tallies (0 vs 0)
     *  — confirms the baseline shape/keys of the response for every real
     *  team configured, not just that the endpoint doesn't 500. */
    public function test_an_empty_range_tallies_trivially_for_every_team(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->getJson(route('data.tally-checker', [
            'date_from' => '2026-10-10', 'date_to' => '2026-10-10',
        ]));

        $response->assertOk();
        $teams = $response->json('teams');
        $this->assertNotEmpty($teams);
        foreach ($teams as $team) {
            $this->assertTrue($team['grossSales']['tallies']);
            $this->assertTrue($team['numberOfLeads']['tallies']);
            $this->assertEquals(0.0, $team['grossSales']['shared']);
            $this->assertEquals(0.0, $team['grossSales']['independent']);
        }
    }

    /** A real order worked by a team's own TSA must tally between the
     *  shared teamGrossSalesAndOrders() basis and the independent raw
     *  query built fresh in the controller — the exact regression class
     *  this checker exists to catch (same shape as the Oct-12
     *  cross-hour-window Gross Sales bug this session already found and
     *  fixed independently of this checker). */
    public function test_a_real_order_tallies_for_gross_sales_and_orders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::where('team', 'SH Naturals')->first();
        $date = '2026-10-10';

        Order::create([
            'pancake_order_id' => 'tally-checker-order-1',
            'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key,
            'product' => 'Some Product',
            'is_upsell' => true, 'amount' => 1500.0, 'status_code' => 2,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson(route('data.tally-checker', [
            'date_from' => $date, 'date_to' => $date,
        ]));

        $response->assertOk();
        $shNaturals = collect($response->json('teams'))->firstWhere('orderTeam', 'SH Naturals');
        $this->assertNotNull($shNaturals);
        $this->assertTrue($shNaturals['grossSales']['tallies']);
        $this->assertEqualsWithDelta(1500.0, $shNaturals['grossSales']['shared'], 0.01);
        $this->assertEqualsWithDelta(1500.0, $shNaturals['grossSales']['independent'], 0.01);
        $this->assertSame(1, $shNaturals['grossSales']['sharedOrders']);
        $this->assertSame(1, $shNaturals['grossSales']['independentOrders']);
    }

    /** Regression coverage for the exact bug class this checker exists
     *  to catch: an order worked by this team's own TSA but arriving
     *  during the OTHER team's hour window (Order.team = 'Eyecare Team')
     *  — both the shared and independent Gross Sales paths are TSA-
     *  roster-scoped (not Order.team-scoped, see
     *  ExpectedIncomeController::ordersByTsaTeamAndDate()'s own doc
     *  comment), so both must still count it and still tally against
     *  each other. */
    public function test_a_cross_hour_window_order_still_tallies(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::where('team', 'SH Naturals')->first();
        $date = '2026-10-10';

        Order::create([
            'pancake_order_id' => 'tally-checker-cross-hour-1',
            'team' => 'Eyecare Team', 'tsa_name' => $tsa->tsa_key,
            'product' => 'Some Product',
            'is_upsell' => true, 'amount' => 1000.0, 'status_code' => 2,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson(route('data.tally-checker', [
            'date_from' => $date, 'date_to' => $date,
        ]));

        $response->assertOk();
        $shNaturals = collect($response->json('teams'))->firstWhere('orderTeam', 'SH Naturals');
        $this->assertTrue($shNaturals['grossSales']['tallies']);
        $this->assertEqualsWithDelta(1000.0, $shNaturals['grossSales']['shared'], 0.01);
    }

    public function test_net_income_is_present_but_not_a_pass_fail_field(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->getJson(route('data.tally-checker', [
            'date_from' => '2026-10-10', 'date_to' => '2026-10-10',
        ]));

        $response->assertOk();
        $team = $response->json('teams.0');
        $this->assertArrayHasKey('netIncome', $team);
        $this->assertArrayHasKey('value', $team['netIncome']);
        $this->assertArrayNotHasKey('tallies', $team['netIncome']);
    }

    /** Per-product extension (explicit follow-up request, 2026-10-12:
     *  "include the dsppr to the tally checker like for every products")
     *  — a real order matching one configured product must tally for
     *  Number of Leads/Number of Orders, pooled across every team (DSPPR
     *  itself has no team split — one row per product). */
    public function test_a_products_number_of_leads_and_orders_tally(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::where('team', 'SH Naturals')->first();
        $product = Product::where('team', 'SH Naturals')->first();
        $date = '2026-10-10';

        Order::create([
            'pancake_order_id' => 'tally-checker-product-1',
            'team' => $tsa->team, 'tsa_name' => $tsa->tsa_key,
            'product' => $product->display_name,
            'is_upsell' => true, 'amount' => 500.0, 'status_code' => 2,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson(route('data.tally-checker', [
            'date_from' => $date, 'date_to' => $date,
        ]));

        $response->assertOk();
        $productResult = collect($response->json('products'))->firstWhere('product', $product->display_name);
        $this->assertNotNull($productResult, 'expected the product to appear in the per-product results');
        $this->assertTrue($productResult['numberOfLeads']['tallies']);
        $this->assertTrue($productResult['numberOfOrders']['tallies']);
        $this->assertSame(1, $productResult['numberOfLeads']['shared']);
        $this->assertSame(1, $productResult['numberOfLeads']['independent']);
        $this->assertSame(1, $productResult['numberOfOrders']['shared']);
        $this->assertSame(1, $productResult['numberOfOrders']['independent']);
    }

    /** A product with zero activity in range is skipped entirely, not
     *  shown as a trivial 0-vs-0 "pass" — same "no noise for inactive
     *  products" reasoning DsPprReportController's own hidden-product
     *  exclusion already follows. */
    public function test_a_product_with_no_activity_is_omitted_from_results(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();

        $response = $this->actingAs($admin)->getJson(route('data.tally-checker', [
            'date_from' => '2026-10-10', 'date_to' => '2026-10-10',
        ]));

        $response->assertOk();
        $productResult = collect($response->json('products'))->firstWhere('product', $product->display_name);
        $this->assertNull($productResult);
    }

    /** Regression test for the real 2026-10-13 production incident: order
     *  #1374667 (Joana Caluag, Oct 1, "Haplunas Healing Eye Cream") never
     *  appeared on her Expected Income or TSA Performance cards — root
     *  cause confirmed via production data: Clear Sight's own Product
     *  record only had ONE configured keyword ("Clear Sight 3.0"), while
     *  this order's real tag was the shorter "CLEARSIGHT" (no "3.0").
     *  Since matching checks whether the TAG contains the KEYWORD,
     *  "CLEARSIGHT" never contains "CLEARSIGHT30", so the order matched
     *  zero products anywhere in the app — silent, invisible data loss
     *  with no error, no mismatch to compare against (every "shared vs
     *  independent" check in this controller would have shown 0 vs 0,
     *  a trivial "pass"). checkUnmatchedOrders() exists specifically to
     *  surface this class of gap. */
    public function test_a_real_order_matching_no_product_is_surfaced_as_unmatched(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::where('team', 'Eyecare Team')->first();
        $date = '2026-10-10';

        Order::create([
            'pancake_order_id' => 'tally-checker-unmatched-1',
            'team' => $tsa->team, 'tsa_name' => $tsa->tsa_key,
            'product' => 'Some Completely Unconfigured Item', 'base_product' => 'Some Completely Unconfigured Item',
            'raw_tags' => [strtoupper($tsa->tsa_key), 'A_TAG_NOBODY_CONFIGURED'],
            'is_upsell' => true, 'amount' => 777.0, 'status_code' => 2,
            'pancake_created_at' => $date . ' 09:00:00', 'synced_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson(route('data.tally-checker', [
            'date_from' => $date, 'date_to' => $date,
        ]));

        $response->assertOk();
        $unmatched = collect($response->json('unmatchedOrders'));
        $row = $unmatched->firstWhere('orderId', 'tally-checker-unmatched-1');
        $this->assertNotNull($row, 'expected the unmatched order to be surfaced');
        $this->assertEquals(777.0, $row['amount']);
        $this->assertSame($tsa->tsa_key, $row['tsaName']);
        $this->assertSame('Some Completely Unconfigured Item', $row['product']);
    }

    /** A real order that DOES match a configured product must never show
     *  up as unmatched — confirms the check isn't trivially true/noisy. */
    public function test_a_matched_order_is_not_surfaced_as_unmatched(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::where('team', 'SH Naturals')->first();
        $product = Product::where('team', 'SH Naturals')->first();
        $date = '2026-10-10';

        Order::create([
            'pancake_order_id' => 'tally-checker-matched-1',
            'team' => $tsa->team, 'tsa_name' => $tsa->tsa_key,
            'product' => $product->display_name,
            'is_upsell' => true, 'amount' => 500.0, 'status_code' => 2,
            'pancake_created_at' => $date . ' 09:00:00', 'synced_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson(route('data.tally-checker', [
            'date_from' => $date, 'date_to' => $date,
        ]));

        $response->assertOk();
        $unmatched = collect($response->json('unmatchedOrders'));
        $this->assertNull($unmatched->firstWhere('orderId', 'tally-checker-matched-1'));
    }
}
