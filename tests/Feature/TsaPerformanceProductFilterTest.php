<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TsaPerformanceProductFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_available_products_come_from_the_database_not_config(): void
    {
        Product::create(['display_name' => 'Brand New Product', 'team' => 'SH Naturals', 'sort_order' => 99]);

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals']));

        $response->assertOk();
        $response->assertViewHas('availableProducts', function ($products) {
            return $products->pluck('display_name')->contains('Brand New Product');
        });
    }

    public function test_product_dropdown_is_not_scoped_to_the_viewed_team(): void
    {
        // Explicit request, 2026-10-05: every TSA now handles every product
        // (same reasoning Product Management's own page already dropped its
        // team grouping for, 2026-09-06), so the dropdown should offer the
        // full catalog on every team's page, not just that team's products.
        $sshNaturalsProduct = Product::where('team', 'SH Naturals')->first();
        $eyecareProduct     = Product::where('team', 'Eyecare Team')->first();

        $response = $this->get(route('tsa-performance', ['team' => 'eyecare']));

        $response->assertOk();
        $response->assertViewHas('availableProducts', function ($products) use ($sshNaturalsProduct, $eyecareProduct) {
            $names = $products->pluck('display_name');
            return $names->contains($sshNaturalsProduct->display_name)
                && $names->contains($eyecareProduct->display_name);
        });
    }

    public function test_product_filter_matches_via_the_products_table_match_keyword(): void
    {
        // CANPRO JUICE DRINK's match_keyword is "CANPRO" — an order tagged with the
        // longer real product name should still be found when filtering by it.
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        // SH Naturals is the "Closing" team — TeamShiftWindow (2026-09-29)
        // only counts an order in this page's hourly totals when its own
        // created hour falls in Closing's 15:00-23:59 window. A bare now()
        // only works when the test happens to run in that window — pinned
        // to 4pm so this test isn't flaky depending on the time of day.
        Order::create([
            'pancake_order_id'   => 'test-canpro-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'raw_tags'           => ['CANPRO JUICE DRINK', strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell'          => false,
            'status_code'        => 1,
            'pancake_created_at' => now()->setTime(16, 0),
            'synced_at'          => now()->setTime(16, 0),
        ]);

        $response = $this->get(route('tsa-performance', [
            'team'    => 'sh-naturals',
            'product' => 'CANPRO JUICE DRINK',
            'date'    => now()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertViewHas('totals', fn($totals) => $totals['total'] === 1);
    }

    public function test_product_filter_normalizes_spacing_and_casing_like_matches_text(): void
    {
        // Root cause, confirmed live 2026-10-05: CLEARSIGHT's real tag comes
        // through as "Clear Sight 3.0" (inconsistent spacing/casing vs the
        // bare keyword) — Product::matchesText()'s own doc comment names
        // this exact pair as the reason it normalizes before comparing. The
        // product filter used to call effective_keyword + a raw stripos()
        // with no normalization, so this exact real-world tag never matched
        // and the page showed "No data" despite the lead existing.
        $product = Product::where('display_name', 'CLEARSIGHT')->first();
        $shift   = TsaShift::where('team', 'Eyecare Team')->first();

        Order::create([
            'pancake_order_id'   => 'test-clearsight-1',
            'team'               => 'Eyecare Team',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'raw_tags'           => ['Clear Sight 3.0', strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell'          => false,
            'status_code'        => 1,
            'pancake_created_at' => now()->setTime(10, 0),
            'synced_at'          => now()->setTime(10, 0),
        ]);

        $response = $this->get(route('tsa-performance', [
            'team'    => 'eyecare',
            'product' => $product->display_name,
            'date'    => now()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertViewHas('totals', fn($totals) => $totals['total'] === 1);
    }

    public function test_hidden_product_is_excluded_from_the_dropdown_regardless_of_date(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $product->is_hidden = true;
        $product->save();

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals']));

        $response->assertOk();
        $response->assertViewHas('availableProducts', function ($products) {
            return $products->doesntContain(fn($p) => $p->display_name === 'SINUXYL');
        });
    }
}
