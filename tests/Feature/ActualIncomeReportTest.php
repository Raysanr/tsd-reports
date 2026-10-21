<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\ExpectedIncomeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Actual Income (2026-10-21): Expected Income's page with the revenue
 *  split by each real upsell order's current Pancake status. */
class ActualIncomeReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Product::query()->update(['has_cost_allocation' => true]);
    }

    public function test_revenue_is_split_by_real_order_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::first();
        $tsa = TsaShift::create(['tsa_key' => 'JOANA', 'display_name' => 'Joana', 'team' => 'SH Naturals', 'active' => true]);
        $date = today()->toDateString();
        // status => amount: Canceled, Confirmed (unfulfilled), Shipped (fulfilled), Returned, Received (delivered)
        foreach ([6 => 500, 1 => 1000, 2 => 2000, 5 => 3000, 3 => 4000] as $status => $amount) {
            Order::create([
                'pancake_order_id' => "ai-{$status}", 'team' => 'SH Naturals', 'tsa_name' => 'JOANA',
                'product' => $product->display_name, 'is_upsell' => false, 'amount' => $amount, 'status_code' => $status,
                'raw_tags' => ['UPSELL TSD - ' . $product->display_name],
                'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
            ]);
        }

        $html = $this->actingAs($admin)->get(route('data.actual-income', ['month' => substr($date, 0, 7)]))
            ->assertOk()->assertSee('Telesales Actual Performance')->assertSee('Cancelled &amp; Waiting', false)
            ->assertDontSee('>Projected Delivered<', false)->getContent();

        foreach (['gross_sales' => '10,500.00', 'cancelled' => '500.00', 'unfulfilled' => '1,000.00', 'fulfilled' => '2,000.00', 'returns' => '3,000.00', 'delivered' => '4,000.00'] as $key => $value) {
            $this->assertMatchesRegularExpression('/data-out="' . $key . '"[^>]*>\s*' . preg_quote($value, '/') . '/', $html, $key);
        }
        $this->assertStringNotContainsString('data-ei-lock-toggle data-locked', $html);
        $this->assertFalse(ExpectedIncomeCalculator::$actual, 'mode flag must reset after the request');

        // Expected Income is unchanged: projected 25% returns on the same orders.
        $this->get(route('data.expected-income', ['date_from' => $date, 'date_to' => $date]))
            ->assertOk()->assertSee('Projected Returns')->assertDontSee('Telesales Actual Performance');
    }

    public function test_it_uses_a_month_picker_not_a_day_range(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('data.actual-income', ['month' => '2026-09']))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="month-hidden"', $html);
        $this->assertStringNotContainsString('data-role="from-hidden"', $html);
        $this->assertStringContainsString('value="2026-09"', $html);
        $this->assertStringContainsString('September 1, 2026 – September 30, 2026', $html);
    }
}
