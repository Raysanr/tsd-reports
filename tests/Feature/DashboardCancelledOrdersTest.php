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
 * upsell that was LATER REMOVED from the cart (base order still shipped)
 * got counted here as a full order cancellation, even though the order
 * itself was never voided. Confirmed live: Eyecare/Sept 1 showed 17
 * orders/₱10,838 here, but only 1 (#1361586) was a genuine full
 * cancellation — the other 16 were upsell-only reverts. A first attempt
 * fixed this by excluding is_cancelled_upsell=true rows, which did NOT
 * work — is_cancelled_upsell can structurally never be true on a
 * status_code=6 row (SyncTodayOrders gates it behind !$isExcludedStatus,
 * and $isExcludedStatus is true for every Order::VOID_STATUSES entry,
 * which includes 6). The actual fix uses Order::isBroadRealUpsell() — the
 * same function ProductPerformance::tally() already uses to decide
 * whether a Canceled order is still a genuine upsell lead — which reads
 * raw_tags directly rather than the never-true-on-status-6 flag.
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
            'amount'             => 1000.0,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ], $overrides));
    }

    public function test_counts_a_fully_cancelled_order_with_no_upsell_tag(): void
    {
        $this->order('cancel-1', ['raw_tags' => ['GEMMA', 'CONFIRMED VIA CALL']]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['cancelled_orders_count'] === 1
            && (float) $stats['cancelled_orders_value'] === 1000.0);
    }

    public function test_excludes_an_upsell_only_cancellation(): void
    {
        // A TSA added an upsell, it was later removed from the cart — the
        // base order still shipped, Pancake still marks status_code 6, but
        // this is not a real cancelled ORDER. The upsell tag surviving on
        // raw_tags is what Order::isBroadRealUpsell() picks up on.
        $this->order('upsell-only-cancel-1', ['raw_tags' => ['GEMMA', 'UPSELL TSD - SINUXYL INHALER']]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['cancelled_orders_count'] === 0
            && (float) $stats['cancelled_orders_value'] === 0.0);
    }

    public function test_counts_only_the_genuine_cancellation_when_both_shapes_exist_the_same_day(): void
    {
        $this->order('real-cancel-1', ['raw_tags' => ['GEMMA', 'CONFIRMED VIA CALL'], 'amount' => 1300.0]);
        for ($i = 0; $i < 3; $i++) {
            $this->order("upsell-only-cancel-{$i}", ['raw_tags' => ['GEMMA', 'UPSELL TSD - SINUXYL INHALER'], 'amount' => 799.0]);
        }

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['cancelled_orders_count'] === 1
            && (float) $stats['cancelled_orders_value'] === 1300.0);
    }

    public function test_a_genuinely_voided_upsell_order_is_still_excluded_even_with_is_upsell_true(): void
    {
        // is_upsell itself can be true on a status_code=6 row in some sync
        // shapes too (not just the raw_tags fallback) — isBroadRealUpsell()'s
        // FIRST branch already covers this, same reasoning.
        $this->order('is-upsell-true-cancel-1', ['is_upsell' => true, 'raw_tags' => ['GEMMA', 'UPSELL TSD - SINUXYL INHALER']]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['cancelled_orders_count'] === 0);
    }
}
