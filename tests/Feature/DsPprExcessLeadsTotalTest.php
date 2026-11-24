<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** DSPPR's day TOTAL Excess Leads must tally with Leads Report: the sum of
 *  each product row's excess (excluded and restocking leads out), not the
 *  day's Total Leads − Catered Leads. */
class DsPprExcessLeadsTotalTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_day_total_excess_is_the_sum_of_the_rows_not_total_minus_catered(): void
    {
        $date = '2026-11-19';
        $product = Product::where('is_hidden', false)->orderBy('sort_order')->firstOrFail();
        $keyword = strtoupper(explode(',', (string) $product->match_keyword)[0] ?: $product->display_name);
        $order = fn (string $id, array $extra) => Order::create($extra + [
            'pancake_order_id' => $id, 'team' => $product->team, 'product' => $product->display_name,
            'raw_tags' => [$keyword], 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => "{$date} 16:00:00", 'synced_at' => now(),
        ]);
        $order('catered', ['disposition' => 'NOT ANSWERING', 'raw_tags' => [$keyword, 'NOT ANSWERING']]);
        $order('excess', []);
        $order('excluded', ['raw_tags' => [$keyword, 'RESTOCKING ORDERS']]);
        $order('restocking', ['raw_tags' => [$keyword, 'RESTOCKING ORDERS']]);

        $row = \App\Support\ProductPerformance::dsPprRow(collect([$product]), Order::all());
        $this->assertSame(4, $row['total_leads']);
        $this->assertSame(1, $row['catered_leads']);
        $this->assertSame(1, $row['excess_leads'], 'Leads Report: 4 − 1 catered − 2 restocking/excluded');

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('data.dsppr', ['dm' => 'data', 'date_from' => $date, 'date_to' => $date]))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-out="excess_leads" data-date="' . $date . '" data-total-row="1">\s*1\s*</',
            $html,
            'the day TOTAL shows 1 (the rows\' sum), not 3 (Total − Catered)'
        );
    }
}
