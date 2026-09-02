<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Total Cancelled Orders" has had two opposite bugs on record:
 *
 * 2026-08-28: keying off is_cancelled_upsell ALONE under-counted — a fully
 * canceled order (Pancake status_code 6) with no upsell tag at all was
 * invisible here. Fixed by switching to plain status_code=6.
 *
 * 2026-09-02: that fix over-corrected — an order where a TSA added an
 * upsell that was LATER REMOVED from the cart (base order still shipped,
 * is_cancelled_upsell=true, but status_code is still 6 in Pancake) got
 * counted here as a full order cancellation, even though the order itself
 * was never voided. Confirmed live: Eyecare/Sept 1 showed 17 orders/₱10,838
 * here, but only 1 (#1361586) was a genuine full cancellation — the other
 * 16 were upsell-only reverts.
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
            'is_cancelled_upsell'=> false,
            'amount'             => 1000.0,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ], $overrides));
    }

    public function test_counts_a_fully_cancelled_order_with_no_upsell_tag(): void
    {
        $this->order('cancel-1');

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['cancelled_orders_count'] === 1
            && (float) $stats['cancelled_orders_value'] === 1000.0);
    }

    public function test_excludes_an_upsell_only_cancellation(): void
    {
        // A TSA added an upsell, it was later removed from the cart — the
        // base order still shipped, Pancake still marks status_code 6, but
        // this is not a real cancelled ORDER.
        $this->order('upsell-only-cancel-1', ['is_cancelled_upsell' => true]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['cancelled_orders_count'] === 0
            && (float) $stats['cancelled_orders_value'] === 0.0);
    }

    public function test_counts_only_the_genuine_cancellation_when_both_shapes_exist_the_same_day(): void
    {
        $this->order('real-cancel-1', ['amount' => 1300.0]);
        for ($i = 0; $i < 3; $i++) {
            $this->order("upsell-only-cancel-{$i}", ['is_cancelled_upsell' => true, 'amount' => 799.0]);
        }

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['cancelled_orders_count'] === 1
            && (float) $stats['cancelled_orders_value'] === 1300.0);
    }
}
