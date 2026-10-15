<?php

namespace Tests\Feature;

use App\Http\Controllers\DataManagement\ExpectedIncomeController;
use App\Models\CostBreakdownPool;
use App\Models\CostBreakdownRole;
use App\Models\CostBreakdownTsaEntry;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Explicit request (2026-10-15): "in the dsppr make this automated too the
 *  tiktok that is reflected by expected income". */
class DsPprTiktokAutomationTest extends TestCase
{
    use RefreshDatabase;

    private function setUpTiktokTsa(): TsaShift
    {
        CostBreakdownRole::ensureSeeded();
        CostBreakdownPool::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);

        return $tsa;
    }

    public function test_tiktok_orders_row_mirrors_expected_income_and_ignores_manual_edits(): void
    {
        $tsa = $this->setUpTiktokTsa();
        $date = today()->toDateString();
        $this->seedTiktokOrder($tsa->id, 'natureva', $date, 1000);
        $this->seedTiktokOrder($tsa->id, 'sh_naturals', $date, 500);

        $expected = ExpectedIncomeController::tiktokTotalsByTsaAndDate($date, $date)->get($tsa->id . ':' . $date);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->patchJson(
            route('data.dsppr.update-tiktok', ['date' => $date]),
            ['gross_sales' => 99999, 'net_income' => 12345, 'total_orders' => 77, 'total_leads' => 9]
        )->assertOk();

        $this->assertEqualsWithDelta(1500.0, $response->json('derived.gross_sales'), 0.01);
        $this->assertEqualsWithDelta($expected['net_income'], $response->json('derived.net_income'), 0.01);
        $this->assertSame(2, (int) $response->json('derived.total_orders'));
        $this->assertSame(9, (int) $response->json('derived.total_leads'), 'leads stay manual');
    }

    public function test_automated_fields_render_read_only_but_pre_oct_1_stays_editable(): void
    {
        $this->setUpTiktokTsa();
        $admin = User::factory()->create(['role' => 'admin']);
        $today = today()->toDateString();

        $content = $this->actingAs($admin)->get(route('data.dsppr', ['date_from' => $today, 'date_to' => $today]))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*data-field="gross_sales"[^>]*data-date="' . $today . '"/s', $content);
        $this->assertMatchesRegularExpression('/<input[^>]*data-field="total_leads"[^>]*data-date="' . $today . '"/s', $content);

        $before = '2026-09-30';
        $content = $this->actingAs($admin)->get(route('data.dsppr', ['date_from' => $before, 'date_to' => $before]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*data-field="gross_sales"[^>]*data-date="' . $before . '"/s', $content);
    }

    /** Explicit report (2026-10-15): "the NI% is why is it no percentage?"
     *  — every daily product row showed 0.00% NI % on page load. */
    public function test_daily_product_rows_show_their_real_ni_percent(): void
    {
        $this->setUpTiktokTsa();
        $tsa = TsaShift::orderBy('id')->skip(1)->first();
        $product = \App\Models\Product::where('team', $tsa->team)->orderBy('sort_order')->first();
        $date = today()->toDateString();
        \App\Models\Order::create([
            'pancake_order_id' => 'ni-pct-1', 'team' => $tsa->team, 'tsa_name' => $tsa->tsa_key,
            'product' => $product->display_name, 'is_upsell' => true, 'amount' => 3300, 'status_code' => 3,
            'raw_tags' => [$product->display_name], 'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);

        $fig = ExpectedIncomeController::grossSalesAndNetIncomeByProductAndDate($date, $date)->get($product->id . ':' . $date);
        $this->assertGreaterThan(0, $fig['gross_sales'], 'test setup: expected real Gross Sales for this product');
        $expectedPct = number_format($fig['net_income'] / $fig['gross_sales'] * 100, 2) . '%';

        $content = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('data.dsppr', ['date_from' => $date, 'date_to' => $date]))->assertOk()->getContent();

        preg_match_all('/data-out="ni_pct" data-date="' . $date . '"[^>]*>\s*([^<]*?)\s*</', $content, $m);
        $this->assertContains($expectedPct, $m[1], 'the product row\'s daily NI % must reflect its real Gross Sales/Net Income');
    }
}
