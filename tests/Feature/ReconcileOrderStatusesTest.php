<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Real, live-confirmed bug: Pancake's list-orders endpoint excludes canceled/
 * deleted orders by default, so once an order is removed there, no future
 * date-scoped sync ever sees it again — its local copy keeps whatever status
 * it had at last sync forever. Confirmed against 2026-07-25 production data:
 * 33 of the first 100 Canceled/Deleted orders in just the last 30 days had a
 * stale local status_code. This command explicitly asks for removed orders
 * (filter_status[]=6,7 + include_removed=1) and corrects any local mismatch.
 */
class ReconcileOrderStatusesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('pancake_api_key', 'a-working-key');
        Setting::set('shop_id', '30037101');
    }

    public function test_corrects_a_local_order_whose_status_pancake_has_since_changed_to_deleted(): void
    {
        Order::factory()->create(['pancake_order_id' => '1335548', 'status_code' => 0]); // still "New" locally

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [
                    ['id' => 1335548, 'status' => 7], // Pancake: Deleted recently
                ],
            ], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $this->assertSame(7, Order::where('pancake_order_id', '1335548')->first()->status_code);
    }

    public function test_leaves_a_local_order_alone_when_its_status_already_matches(): void
    {
        Order::factory()->create(['pancake_order_id' => '1335001', 'status_code' => 6]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [
                    ['id' => 1335001, 'status' => 6],
                ],
            ], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $this->assertSame(0, (int) Setting::get('order_status_reconcile_last_corrected'));
    }

    public function test_ignores_a_pancake_removed_order_that_was_never_synced_locally_at_all(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [
                    ['id' => 'never-synced-order', 'status' => 7],
                ],
            ], 200),
        ]);

        $this->artisan('pancake:reconcile-statuses')->assertSuccessful();

        $this->assertSame(0, (int) Setting::get('order_status_reconcile_last_corrected'));
    }

    public function test_paginates_through_multiple_pages(): void
    {
        Order::factory()->create(['pancake_order_id' => 'page-1-order', 'status_code' => 0]);
        Order::factory()->create(['pancake_order_id' => 'page-2-order', 'status_code' => 0]);

        $page1 = array_fill(0, 100, ['id' => 'filler', 'status' => 7]);
        $page1[0] = ['id' => 'page-1-order', 'status' => 7];

        Http::fake(function ($request) use ($page1) {
            $page = $request->data()['page_number'] ?? 1;
            if ($page == 1) {
                return Http::response(['data' => $page1], 200);
            }
            return Http::response(['data' => [['id' => 'page-2-order', 'status' => 7]]], 200);
        });

        Artisan::call('pancake:reconcile-statuses');

        $this->assertSame(7, Order::where('pancake_order_id', 'page-1-order')->first()->status_code);
        $this->assertSame(7, Order::where('pancake_order_id', 'page-2-order')->first()->status_code);
    }

    public function test_records_last_run_status_and_counts(): void
    {
        Order::factory()->create(['pancake_order_id' => '1335548', 'status_code' => 0]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [['id' => 1335548, 'status' => 7]],
            ], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $this->assertNotNull(Setting::get('order_status_reconcile_last_run'));
        $this->assertSame(1, (int) Setting::get('order_status_reconcile_last_checked'));
        $this->assertSame(1, (int) Setting::get('order_status_reconcile_last_corrected'));
    }

    /**
     * The actual bug this closes: an order was a real upsell BEFORE Pancake
     * canceled/deleted it, so is_upsell stayed true locally forever (the
     * regular sync never re-fetches it to correct that, same root cause as
     * status_code) — silently inflating Dashboard's gross sales/upsell
     * revenue even after status_code itself gets fixed, unless this is
     * cleared too.
     */
    public function test_clears_stale_upsell_flags_when_correcting_a_void_status(): void
    {
        Order::factory()->create([
            'pancake_order_id'    => '1335999',
            'status_code'         => 9, // "Waiting for pick up" locally — stale
            'is_upsell'           => true,
            'is_cancelled_upsell' => false,
            'is_returned_upsell'  => false,
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [['id' => 1335999, 'status' => 6]], // Pancake: Canceled
            ], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $order = Order::where('pancake_order_id', '1335999')->first();
        $this->assertSame(6, $order->status_code);
        $this->assertFalse($order->is_upsell);
    }

    /** Even when status_code already matches, a stale upsell flag alone must
     *  still be corrected and counted — the two can drift independently if a
     *  previous partial fix only touched one of them. */
    public function test_clears_a_stale_upsell_flag_even_when_status_code_already_matches(): void
    {
        Order::factory()->create([
            'pancake_order_id' => '1336000',
            'status_code'      => 7,
            'is_upsell'        => true,
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [['id' => 1336000, 'status' => 7]],
            ], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $this->assertFalse(Order::where('pancake_order_id', '1336000')->first()->is_upsell);
        $this->assertSame(1, (int) Setting::get('order_status_reconcile_last_corrected'));
    }

    /**
     * Confirmed live (2026-07-28): this command corrects status_code and
     * is_upsell/is_cancelled_upsell/is_returned_upsell but had NOT been taught
     * about is_restocking_upsell (added the same day, for the Total Restocking
     * KPI fix) — an order sitting in Restocking that later actually gets
     * Deleted in Pancake would have kept counting toward Total Restocking
     * forever otherwise, the exact same bug class this command exists to fix
     * for is_upsell.
     */
    public function test_clears_a_stale_restocking_upsell_flag_when_correcting_a_void_status(): void
    {
        Order::factory()->create([
            'pancake_order_id'         => '1336500',
            'status_code'              => 11, // Restocking locally — stale
            'is_restocking_upsell'     => true,
            'restocking_upsell_amount' => 500.00,
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [['id' => 1336500, 'status' => 7]], // Pancake: Deleted
            ], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $order = Order::where('pancake_order_id', '1336500')->first();
        $this->assertSame(7, $order->status_code);
        $this->assertFalse($order->is_restocking_upsell);
        $this->assertSame(0.0, (float) $order->restocking_upsell_amount);
    }

    public function test_fails_gracefully_without_credentials(): void
    {
        Setting::set('pancake_api_key', '');
        Setting::set('shop_id', '');

        $this->artisan('pancake:reconcile-statuses')->assertFailed();
    }

    /**
     * Second, separate pass — reconcileStaleUpsellTags(). The bug this closes:
     * an order stays fully ACTIVE (never Cancelled/Deleted/Restocking) but its
     * upsell add-on item was removed or never added, leaving a stale upsell
     * tag. The Cancelled/Deleted sweep above would never touch it — confirmed
     * live, order #1341487 (₱500, tagged "UPSELL TSD - Sinuxyl Inhaler", only
     * remaining item the base "Sinuxyl") stayed wrong for months.
     */
    public function test_corrects_a_still_active_order_whose_upsell_add_on_was_removed(): void
    {
        Order::factory()->create([
            'pancake_order_id'    => '1341487',
            'status_code'         => 8, // Packaging — fully active, not void
            'is_upsell'           => true,
            'is_cancelled_upsell' => false,
            'product'             => 'Sinuxyl',
            'base_product'        => 'Sinuxyl', // the bug's structural signature
            'amount'              => 500.0,
            'pancake_created_at'  => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*'  => Http::response(['data' => []], 200),
            'pos.pages.fm/api/v1/shops/*/orders/*'  => Http::response(['data' => [
                'id'          => 1341487,
                'status'      => 8,
                'total_price' => 500,
                'tags'        => [['id' => 1, 'name' => 'UPSELL TSD - Sinuxyl Inhaler']],
                'items'       => [
                    ['variation_info' => ['name' => 'Sinuxyl', 'retail_price' => 500], 'quantity' => 1],
                ],
            ]], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $order = Order::where('pancake_order_id', '1341487')->first();
        $this->assertFalse($order->is_upsell);
        $this->assertTrue($order->is_cancelled_upsell);
        $this->assertSame(500.0, (float) $order->cancelled_upsell_amount);
        $this->assertSame(500.0, (float) $order->amount);
    }

    /** A local candidate (product == base_product) that turns out, once
     *  actually checked live, to have 2 real items — a genuine upsell, just
     *  one where product/base_product happened to be recorded identically for
     *  an unrelated reason — must be left alone, not "corrected" incorrectly. */
    public function test_leaves_a_candidate_alone_when_live_data_shows_it_is_not_actually_stale(): void
    {
        Order::factory()->create([
            'pancake_order_id'    => '1341999',
            'status_code'         => 2,
            'is_upsell'           => true,
            'is_cancelled_upsell' => false,
            'product'             => 'Clear Sight 3.0',
            'base_product'        => 'Clear Sight 3.0',
            'amount'              => 800.0,
            'pancake_created_at'  => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response(['data' => []], 200),
            'pos.pages.fm/api/v1/shops/*/orders/*' => Http::response(['data' => [
                'id'     => 1341999,
                'status' => 2,
                'tags'   => [],
                'items'  => [
                    ['variation_info' => ['name' => 'Clear Sight 3.0'], 'quantity' => 1],
                    ['variation_info' => ['name' => 'Clear Sight 3.0'], 'quantity' => 1],
                ],
            ]], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $order = Order::where('pancake_order_id', '1341999')->first();
        $this->assertTrue($order->is_upsell);
        $this->assertFalse($order->is_cancelled_upsell);
        $this->assertSame(800.0, (float) $order->amount);
    }

    /** A local order that's neither is_upsell nor matching product==base_product
     *  is never even a candidate — no per-order lookup should fire for it at
     *  all (Http::fake with no matching pattern would throw if one did). */
    public function test_does_not_check_orders_outside_the_candidate_shape_at_all(): void
    {
        Order::factory()->create([
            'pancake_order_id'   => '1342000',
            'status_code'        => 2,
            'is_upsell'          => false,
            'product'            => 'Sinuxyl',
            'base_product'       => 'Sinuxyl',
            'pancake_created_at' => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response(['data' => []], 200),
        ]);

        $this->artisan('pancake:reconcile-statuses')->assertSuccessful();
    }

    /**
     * Confirmed live (order #1341759, 2026-08-07): tagged "TSD UPSELL SCAR
     * CREAM" — no dash, no parens, so remainingItemIsJustTheBase() never
     * recognizes the phrasing at all. The sole item is "Scar Cream", which
     * trivially matches the tag's own named product (it always will, for this
     * phrasing), so name-based matching alone can't tell this apart from a
     * genuine remains-after-void order. The items history proves it: the only
     * items event is the exact same variation_id being removed and re-added
     * at a corrected price (₱499 -> ₱500) — never a different product — so
     * this order was never a real 2-item upsell to begin with.
     */
    public function test_corrects_a_still_active_order_whose_tag_never_matched_a_recognized_phrasing_but_history_proves_it_was_never_a_real_upsell(): void
    {
        Order::factory()->create([
            'pancake_order_id'    => '1341759',
            'status_code'         => 3,
            'is_upsell'           => true,
            'is_cancelled_upsell' => false,
            'product'             => 'Scar Cream',
            'base_product'        => 'Scar Cream',
            'amount'              => 500.0,
            'pancake_created_at'  => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response(['data' => []], 200),
            'pos.pages.fm/api/v1/shops/*/orders/*' => Http::response(['data' => [
                'id'          => 1341759,
                'status'      => 3,
                'total_price' => 500,
                'tags'        => [['id' => 1, 'name' => 'TSD UPSELL SCAR CREAM']],
                'items'       => [
                    ['variation_id' => 'ea15484b-d86c', 'variation_info' => ['name' => 'Scar Cream', 'retail_price' => 500], 'quantity' => 1],
                ],
                'histories' => [
                    [
                        'items' => [
                            ['variation_id' => 'ea15484b-d86c', 'old' => ['variation_info' => ['name' => 'Scar Cream', 'retail_price' => 499]], 'new' => null],
                            ['variation_id' => 'ea15484b-d86c', 'old' => null, 'new' => ['variation_info' => ['name' => 'Scar Cream', 'retail_price' => 500]]],
                        ],
                    ],
                ],
            ]], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $order = Order::where('pancake_order_id', '1341759')->first();
        $this->assertFalse($order->is_upsell);
        $this->assertTrue($order->is_cancelled_upsell);
        $this->assertSame(500.0, (float) $order->amount);
    }

    /** Guard against the new history check over-firing: a candidate whose tag
     *  doesn't match a recognized phrasing either, but whose history proves a
     *  GENUINELY DIFFERENT product once existed (a real base, later voided,
     *  leaving a real addon behind) must be left alone as a live upsell. */
    public function test_leaves_a_candidate_alone_when_history_proves_a_genuinely_different_item_once_existed(): void
    {
        Order::factory()->create([
            'pancake_order_id'    => '1341760',
            'status_code'         => 3,
            'is_upsell'           => true,
            'is_cancelled_upsell' => false,
            'product'             => 'Belo Set',
            'base_product'        => 'Belo Set',
            'amount'              => 1200.0,
            'pancake_created_at'  => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response(['data' => []], 200),
            'pos.pages.fm/api/v1/shops/*/orders/*' => Http::response(['data' => [
                'id'          => 1341760,
                'status'      => 3,
                'total_price' => 1200,
                'tags'        => [['id' => 1, 'name' => 'TSD UPSELL BELO SET']],
                'items'       => [
                    ['variation_id' => 'addon-item', 'variation_info' => ['name' => 'Belo Set', 'retail_price' => 1200], 'quantity' => 1],
                ],
                'histories' => [
                    [
                        'items' => [
                            // The real base product, genuinely voided — a different
                            // variation_id than the surviving item, never re-added.
                            ['variation_id' => 'ginseng-serum', 'old' => ['variation_info' => ['name' => 'Ginseng Serum', 'retail_price' => 1000]], 'new' => null],
                        ],
                    ],
                ],
            ]], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $order = Order::where('pancake_order_id', '1341760')->first();
        $this->assertTrue($order->is_upsell);
        $this->assertFalse($order->is_cancelled_upsell);
        $this->assertSame(1200.0, (float) $order->amount);
    }

    /**
     * Confirmed live (order #1342174, 2026-08-07): tagged "TSD UPSELL -
     * GINSENG SERUM" — the dash IS present, but the named product is the
     * BASE ("Ginseng Serum"), not the addon, a tagging mistake rather than a
     * missing phrasing. remainingItemIsJustTheBase()'s name match concludes
     * the sole remaining item (also "Ginseng Serum") IS the addon, so it
     * never flags this one. The real story, visible only in history: "Belo
     * Set" (₱1200) was added, then fully removed — Ginseng Serum's own
     * variation_id is never touched at all, proving it was the original base
     * all along.
     */
    public function test_corrects_a_still_active_order_whose_tag_names_the_base_instead_of_the_addon(): void
    {
        Order::factory()->create([
            'pancake_order_id'    => '1342174',
            'status_code'         => 11,
            'is_upsell'           => false,
            'is_restocking_upsell' => true,
            'is_cancelled_upsell' => false,
            'product'             => 'Ginseng Serum',
            'base_product'        => 'Ginseng Serum',
            'amount'              => 499.0,
            'restocking_upsell_amount' => 499.0,
            'pancake_created_at'  => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response(['data' => []], 200),
            'pos.pages.fm/api/v1/shops/*/orders/*' => Http::response(['data' => [
                'id'          => 1342174,
                'status'      => 11,
                'total_price' => 499,
                'tags'        => [['id' => 1, 'name' => 'TSD UPSELL - GINSENG SERUM']],
                'items'       => [
                    ['variation_id' => 'ginseng-serum-x', 'variation_info' => ['name' => 'Ginseng Serum', 'retail_price' => 499], 'quantity' => 1],
                ],
                'histories' => [
                    [
                        'items' => [
                            ['variation_id' => 'belo-set-x', 'old' => null, 'new' => ['variation_info' => ['name' => 'Belo Set', 'retail_price' => 1200]]],
                        ],
                    ],
                    [
                        'items' => [
                            ['variation_id' => 'belo-set-x', 'old' => ['variation_info' => ['name' => 'Belo Set', 'retail_price' => 1200]], 'new' => null],
                        ],
                    ],
                ],
            ]], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $order = Order::where('pancake_order_id', '1342174')->first();
        $this->assertFalse($order->is_upsell);
        $this->assertTrue($order->is_cancelled_upsell);
        $this->assertSame(499.0, (float) $order->amount);
    }
}
