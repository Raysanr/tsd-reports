<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * This card has changed meaning twice on record:
 *
 * 2026-08-28: keying off is_cancelled_upsell ALONE under-counted — a fully
 * canceled order (Pancake status_code 6) with no upsell tag at all was
 * invisible here. Fixed by switching to plain status_code=6.
 *
 * 2026-09-02: that fix over-corrected — an order where a TSA added an
 * upsell that was LATER REMOVED from the cart (base order still shipped)
 * got counted here as a full order cancellation, even though the order
 * itself was never voided. Fixed via Order::isBroadRealUpsell().
 *
 * 2026-09-04: explicit follow-up request ("same kpi card as cancelled
 * upsells") — deliberate reversal, not a bug fix. This card now shows
 * CANCELLED UPSELLS specifically (a TSA added an upsell add-on that was
 * later removed, base order still ships — real live example, order
 * #1362700: Sinuxyl base order, ₱800, status still "Packing", tagged
 * "UPSELL TSD - Sinuxyl Inhaler", internal note literally reads "cancelled
 * upsell") rather than real order-level cancellations. Accepted tradeoff:
 * a genuinely fully-cancelled order with NO upsell ever involved no longer
 * shows on this card at all.
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
            'status_code'        => 8, // Packing — the base order still ships
            'amount'             => 800.0,
            'is_cancelled_upsell' => false,
            'cancelled_upsell_amount' => 0,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ], $overrides));
    }

    public function test_counts_a_cancelled_upsell_on_an_otherwise_live_order(): void
    {
        $this->order('cancelled-upsell-1', [
            'is_cancelled_upsell'     => true,
            'cancelled_upsell_amount' => 800.0,
            'raw_tags'                => ['KATH', 'UPSELL TSD - Sinuxyl Inhaler'],
        ]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['cancelled_orders_count'] === 1
            && (float) $stats['cancelled_orders_value'] === 800.0);
    }

    public function test_excludes_a_fully_cancelled_order_with_no_upsell_involved(): void
    {
        // Explicit, accepted tradeoff (see class doc comment) — a genuine
        // full-order cancellation with no upsell at all does not show here
        // anymore, even though status_code is 6.
        $this->order('real-cancel-1', [
            'status_code' => 6,
            'raw_tags'    => ['GEMMA', 'CONFIRMED VIA CALL'],
        ]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['cancelled_orders_count'] === 0
            && (float) $stats['cancelled_orders_value'] === 0.0);
    }

    public function test_counts_only_the_cancelled_upsell_when_both_shapes_exist_the_same_day(): void
    {
        $this->order('real-cancel-1', ['status_code' => 6, 'raw_tags' => ['GEMMA', 'CONFIRMED VIA CALL']]);
        for ($i = 0; $i < 3; $i++) {
            $this->order("cancelled-upsell-{$i}", [
                'is_cancelled_upsell'     => true,
                'cancelled_upsell_amount' => 500.0,
                'raw_tags'                => ['GEMMA', 'UPSELL TSD - SINUXYL INHALER'],
            ]);
        }

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['cancelled_orders_count'] === 3
            && (float) $stats['cancelled_orders_value'] === 1500.0);
    }

    /** A cancelled upsell on an order Pancake DID later mark Canceled (e.g.
     *  the add-on was removed, then the whole order was ALSO canceled for an
     *  unrelated reason) still counts — is_cancelled_upsell is the deciding
     *  flag here, not the order's own status_code. */
    public function test_counts_a_cancelled_upsell_even_when_the_order_itself_later_went_void(): void
    {
        $this->order('cancelled-upsell-void-1', [
            'status_code'             => 6,
            'is_cancelled_upsell'     => true,
            'cancelled_upsell_amount' => 500.0,
        ]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['cancelled_orders_count'] === 1
            && (float) $stats['cancelled_orders_value'] === 500.0);
    }

    /**
     * Explicit follow-up (2026-09-04) — confirmed live, orders #1362700/
     * #1362151/#1362682/#1362185/#1362532: cancelled_upsell_amount can be
     * genuinely null (the add-on was added and removed before any sync ever
     * captured its live price, and Pancake's current data no longer has it
     * anywhere). Falls back to the order's own base 'amount' — an accepted
     * approximation, not the true lost upsell revenue, just a non-zero
     * stand-in so the order doesn't silently contribute ₱0 to the total.
     */
    public function test_falls_back_to_the_base_amount_when_the_cancelled_upsells_own_price_was_never_captured(): void
    {
        $this->order('cancelled-upsell-null-amount-1', [
            'is_cancelled_upsell'     => true,
            'cancelled_upsell_amount' => null,
            'amount'                  => 800.0,
        ]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['cancelled_orders_count'] === 1
            && (float) $stats['cancelled_orders_value'] === 800.0);
    }
}
