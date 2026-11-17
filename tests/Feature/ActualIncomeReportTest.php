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

    /** 2026-11-10: Actual Income's operating costs come from Actual Cost
     *  Breakdown and a whole month adds up to its monthly figures (split
     *  over the month's real days, not 24). */
    public function test_a_whole_month_of_costs_adds_up_to_actual_cost_breakdown(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        \Illuminate\Support\Carbon::setTestNow('2026-11-10 12:00:00');
        \App\Support\CostBreakdownMonths::seed('2026-10');
        \App\Models\CostBreakdownPool::where('month', '2026-10')->where('key', 'rent')->update(['amount' => 99999]);
        $this->actingAs($admin)->post(route('data.actual-cost-breakdown.copy-from-budget'), ['month' => '2026-10'])->assertSessionHas('success');
        \App\Support\CostBreakdownVariant::using(\App\Support\CostBreakdownVariant::ACTUAL,
            fn () => \App\Models\CostBreakdownPool::where('month', '2026-10')->where('key', 'rent')->update(['amount' => 31000]));

        $html = $this->get(route('data.actual-income', ['month' => '2026-10']))->assertOk()->getContent();
        \Illuminate\Support\Carbon::setTestNow();

        preg_match('/data-out="rent"[^>]*>\s*([\d,.]+)/', $html, $m);
        $this->assertSame('31,000.00', $m[1] ?? null);
        $this->assertSame(\App\Support\CostBreakdownVariant::BUDGET, \App\Support\CostBreakdownVariant::current());
    }

    /** 2026-11-17: Number of Orders / Product Cost / Fulfillment Fee are
     *  automated but editable on Actual Income. */
    private function seedTwoUpsells(): array
    {
        $product = Product::first();
        $tsa = TsaShift::create(['tsa_key' => 'JOANA', 'display_name' => 'Joana', 'team' => 'SH Naturals', 'active' => true]);
        $date = today()->toDateString();
        \App\Models\ProductCost::updateOrCreate(['variation' => '1 Clear Sight'], ['cost' => 30]);
        \App\Models\ProductCost::updateOrCreate(['variation' => '2 Clear Sight'], ['cost' => 60]);
        foreach (['1 Clear Sight', '2  clear sight'] as $i => $variation) {
            Order::create([
                'pancake_order_id' => "pc-{$i}", 'team' => 'SH Naturals', 'tsa_name' => 'JOANA',
                'product' => $product->display_name, 'bundle_description' => $variation, 'is_upsell' => false, 'amount' => 1000, 'status_code' => 3,
                'raw_tags' => ['UPSELL TSD - ' . $product->display_name],
                'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
            ]);
        }

        return [$product, $tsa, $date];
    }

    private function firstOut(string $html, string $key): ?string
    {
        preg_match('/data-out="' . $key . '"[^>]*>\s*([\d,.\-]+)/', $html, $m);

        return $m[1] ?? null;
    }

    public function test_product_cost_is_automated_from_the_product_costs_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$product, $tsa, $date] = $this->seedTwoUpsells();

        $html = $this->actingAs($admin)->get(route('data.actual-income', ['month' => substr($date, 0, 7)]))->assertOk()->getContent();

        $this->assertSame('90.00', $this->firstOut($html, 'product_cost'));
        $this->assertSame('2', $this->firstOut($html, 'number_of_orders'));
        $this->assertSame('50.00', $this->firstOut($html, 'fulfillment_fee'));
        // Editable inputs on the TSA product card, saving to Actual Income's own route.
        $teamHtml = $this->get(route('data.actual-income', ['month' => substr($date, 0, 7), 'team' => 'sh-naturals']))->assertOk()->getContent();
        $this->assertStringContainsString('data-field="product_cost" data-money="1" data-actual="1"', $teamHtml);
        $this->assertStringContainsString('data-field="number_of_orders" data-actual="1"', $teamHtml);
        $this->assertStringContainsString('data-field="fulfillment_fee" data-money="1" data-actual="1"', $teamHtml);
        $this->get(route('data.actual-income', ['month' => substr($date, 0, 7), 'team' => 'all']));
        $this->assertStringContainsString(route('data.actual-income.update-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => substr($date, 0, 7) . '-01']), $teamHtml);

        // Expected Income keeps its own (typed) Product Cost.
        $expected = $this->get(route('data.expected-income', ['date_from' => $date, 'date_to' => $date]))->assertOk()->getContent();
        $this->assertSame('0.00', $this->firstOut($expected, 'product_cost'));
    }

    public function test_typed_values_override_the_automation_and_reach_the_rollups(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$product, $tsa, $date] = $this->seedTwoUpsells();
        $url = route('data.actual-income.update-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => $date]);

        $this->actingAs($admin)->patchJson($url, ['number_of_orders' => 5, 'product_cost' => 400, 'fulfillment_fee' => 10])->assertOk();

        $html = $this->get(route('data.actual-income', ['month' => substr($date, 0, 7)]))->assertOk()->getContent();
        $this->assertSame('5', $this->firstOut($html, 'number_of_orders'));
        $this->assertSame('400.00', $this->firstOut($html, 'product_cost'));
        $this->assertSame('10.00', $this->firstOut($html, 'fulfillment_fee'));
        $teamHtml = $this->get(route('data.actual-income', ['month' => substr($date, 0, 7), 'team' => 'sh-naturals']))->assertOk()->getContent();
        $this->assertStringContainsString('border-amber-500', $teamHtml);
        $this->assertSame('5', $this->firstOut($teamHtml, 'number_of_orders'), 'team rollup includes the override');
        $this->get(route('data.actual-income', ['month' => substr($date, 0, 7), 'team' => 'all']));

        // Expected Income never sees Actual Income's overrides.
        $expected = $this->get(route('data.expected-income', ['date_from' => $date, 'date_to' => $date]))->assertOk()->getContent();
        $this->assertSame('2', $this->firstOut($expected, 'number_of_orders'));

        // Clearing every field returns to automatic and removes the row.
        $this->patchJson($url, ['number_of_orders' => null, 'product_cost' => null, 'fulfillment_fee' => null])->assertOk();
        $this->assertSame(0, \App\Models\ActualIncomeOverride::count());
        $html = $this->get(route('data.actual-income', ['month' => substr($date, 0, 7)]))->assertOk()->getContent();
        $this->assertSame('90.00', $this->firstOut($html, 'product_cost'));
    }
}
