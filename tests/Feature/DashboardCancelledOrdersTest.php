<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * This card has changed meaning several times on record (see git history
 * for the 2026-08-28 / 09-02 / 09-04 versions).
 *
 * 2026-10-15, explicit request: "it will display the total of awaiting stock
 * and cancelled status like in the expected income" — the card now uses
 * Expected Income's own automated Cancelled rule (ProductPerformance::
 * tally()'s 'cancelled_upsell_sales'): a real TSA upsell whose order is
 * Cancelled (6) or Awaiting Stock (11), one combined total, TikTok-tagged
 * upsells excluded.
 */
class DashboardCancelledOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function order(string $id, array $overrides = []): void
    {
        Order::create(array_merge([
            'pancake_order_id'   => $id,
            'team'               => 'SH Naturals',
            'tsa_name'           => 'Gemma',
            'status_code'        => 6,
            'amount'             => 800.0,
            'is_upsell'          => false,
            'raw_tags'           => ['GEMMA', 'UPSELL TSD - Sinuxyl Inhaler'],
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ], $overrides));
    }

    private function assertCard(int $count, float $value): void
    {
        $this->get(route('dashboard'))->assertOk()->assertViewHas('stats',
            fn ($stats) => $stats['cancelled_orders_count'] === $count && abs((float) $stats['cancelled_orders_value'] - $value) < 0.005);
    }

    public function test_totals_cancelled_and_awaiting_stock_upsells_together(): void
    {
        $this->order('cancelled-1');
        $this->order('awaiting-1', [
            'status_code' => 11, 'amount' => 1800.0,
            'is_restocking_upsell' => true, 'restocking_upsell_amount' => 1000.0,
        ]);

        $this->assertCard(2, 1800.0); // 800 + the isolated 1,000 add-on, not the 1,800 order total
    }

    public function test_ignores_a_cancelled_order_with_no_upsell(): void
    {
        $this->order('real-cancel-1', ['raw_tags' => ['GEMMA', 'CONFIRMED VIA CALL']]);

        $this->assertCard(0, 0.0);
    }

    public function test_ignores_live_upsells_and_tiktok_upsells(): void
    {
        $this->order('live-1', ['status_code' => 8, 'is_upsell' => true]);
        $this->order('tiktok-1', ['raw_tags' => ['GEMMA', 'TIKTOK UPSELL -SH NATURALS']]);

        $this->assertCard(0, 0.0);
    }

    public function test_matches_expected_incomes_cancelled_figure(): void
    {
        $this->order('cancelled-1');
        $this->order('cancelled-2', ['amount' => 500.0]);
        $this->order('not-an-upsell', ['raw_tags' => ['GEMMA']]);

        $orders = Order::all()->pipe(fn ($o) => Order::withoutTiktokUpsells($o));
        $expected = (float) \App\Support\ProductPerformance::tally($orders)['cancelled_upsell_sales'];

        $this->assertSame(1300.0, $expected);
        $this->assertCard(2, $expected);
    }
}
