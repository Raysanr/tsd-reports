<?php

namespace Tests\Feature;

use App\Models\ExpectedIncomeEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\ProductPerformance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Explicit request, 2026-10-17: hiding a product (Product Management's eye
 *  toggle) drops its leads out of every report total, not just its own row. */
class HiddenProductTotalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function hide(string $name): void
    {
        Product::where('display_name', $name)->update(['is_hidden' => true]);
    }

    private function order(string $id, array $tags, string $product, array $extra = []): Order
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        return Order::create(array_merge([
            'pancake_order_id'   => $id,
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'product'            => $product,
            'raw_tags'           => array_merge($tags, [strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL']),
            'is_upsell'          => false,
            'status_code'        => 1,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ], $extra));
    }

    public function test_helper_is_a_no_op_when_nothing_is_hidden(): void
    {
        $this->order('o-1', ['SINUXYL'], 'Sinuxyl');

        $this->assertCount(1, ProductPerformance::withoutHiddenProductOrders(Order::all()));
    }

    public function test_helper_drops_orders_of_a_hidden_product_only(): void
    {
        $this->order('o-sinuxyl', ['SINUXYL'], 'Sinuxyl');
        $this->order('o-audicure', ['AUDICURE'], 'Audicure');
        $this->order('o-unmatched', [], 'Something Untracked');
        $this->hide('SINUXYL');

        $kept = ProductPerformance::withoutHiddenProductOrders(Order::all())->pluck('pancake_order_id')->sort()->values()->all();

        $this->assertSame(['o-audicure', 'o-unmatched'], $kept);
    }

    public function test_helper_keeps_a_combo_order_that_also_matches_a_visible_product(): void
    {
        // Matched by real Pancake product IDs so it's unambiguously a combo.
        Product::where('display_name', 'SINUXYL')->first()->update(['pancake_product_ids' => ['pid-sinuxyl']]);
        Product::where('display_name', 'AUDICURE')->first()->update(['pancake_product_ids' => ['pid-audicure']]);
        $this->order('o-combo', ['SINUXYL', 'AUDICURE'], 'Sinuxyl', ['pancake_product_ids' => ['pid-sinuxyl', 'pid-audicure']]);
        $this->order('o-solo', ['SINUXYL'], 'Sinuxyl', ['pancake_product_ids' => ['pid-sinuxyl']]);
        $this->hide('SINUXYL');

        $kept = ProductPerformance::withoutHiddenProductOrders(Order::all())->pluck('pancake_order_id')->all();

        $this->assertSame(['o-combo'], $kept);
    }

    public function test_dashboard_total_leads_excludes_a_hidden_products_leads(): void
    {
        $this->order('o-sinuxyl', ['SINUXYL'], 'Sinuxyl');
        $this->order('o-audicure', ['AUDICURE'], 'Audicure');
        $this->hide('SINUXYL');

        $today = now()->toDateString();
        $response = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['total_leads'] === 1);
    }

    public function test_dashboard_sales_exclude_a_hidden_products_upsell(): void
    {
        $this->order('o-sinuxyl', ['SINUXYL'], 'Sinuxyl', ['is_upsell' => true, 'amount' => 500]);
        $this->hide('SINUXYL');

        $today = now()->toDateString();
        $response = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => (float) $stats['total_sales'] === 0.0 && $stats['total_orders'] === 0);
        $response->assertViewHas('topProducts', fn ($rows) => $rows->isEmpty());
    }

    public function test_charts_kpis_exclude_a_hidden_products_leads(): void
    {
        $this->order('o-sinuxyl', ['SINUXYL'], 'Sinuxyl');
        $this->hide('SINUXYL');

        $response = $this->get(route('charts'));

        $response->assertOk();
        $response->assertViewHas('kpis', fn ($kpis) => $kpis['total_called']['value'] === 0);
        $response->assertViewHas('productRows', fn ($rows) => $rows->doesntContain(fn ($r) => $r['display_name'] === 'SINUXYL'));
    }

    public function test_dsppr_drops_a_hidden_products_row(): void
    {
        Product::where('display_name', 'SINUXYL')->update(['display_name' => 'ZZHIDDENPROD', 'is_hidden' => true]);
        $date = now()->toDateString();

        $rows = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('data.dsppr', ['date_from' => $date, 'date_to' => $date]))
            ->assertOk()->viewData('rows');

        $this->assertFalse(collect($rows)->pluck('label')->contains(fn ($l) => str_contains(strtoupper($l), 'ZZHIDDENPROD')));
        $this->assertNotEmpty($rows);
    }

    public function test_expected_income_drops_a_hidden_products_card_and_manual_entry(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        Product::whereKey($product->id)->update(['display_name' => 'ZZHIDDENPROD', 'is_hidden' => true]);
        ExpectedIncomeEntry::create(['product_id' => $product->id, 'entry_date' => today(), 'gross_sales' => 90100.00]);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('data.expected-income'));

        $response->assertOk();
        $response->assertDontSee('ZZHIDDENPROD');
        $response->assertSee('Telesales Expected Performance');
    }
}
