<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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

    /**
     * Explicit request (2026-08-10): "Fix Now" switched from a "days back"
     * number to the shared date-range picker, so the command needs a real
     * explicit-range option — --days alone can't express "Aug 1-9" once
     * today isn't the end of the window.
     */
    public function test_from_and_to_options_target_an_explicit_range_instead_of_days_back(): void
    {
        // Outside --days=30's window (so this proves --from is actually
        // driving the query, not silently falling back to --days' default).
        Order::factory()->create(['pancake_order_id' => 'old-order', 'status_code' => 0]);

        Http::fake(function ($request) {
            $from = $request->data()['startDateTime'] ?? null;
            $to   = $request->data()['endDateTime'] ?? null;
            $expectedFrom = \Illuminate\Support\Carbon::parse('2026-01-01', 'Asia/Manila')->startOfDay()->timestamp;
            $expectedTo   = \Illuminate\Support\Carbon::parse('2026-01-09', 'Asia/Manila')->endOfDay()->timestamp;

            if ((int) $from !== $expectedFrom || (int) $to !== $expectedTo) {
                return Http::response(['data' => []], 200);
            }

            return Http::response(['data' => [['id' => 'old-order', 'status' => 7]]], 200);
        });

        Artisan::call('pancake:reconcile-statuses', ['--from' => '2026-01-01', '--to' => '2026-01-09']);

        $this->assertSame(7, Order::where('pancake_order_id', 'old-order')->first()->status_code);
    }

    /** --to defaults to today when only --from is given. */
    public function test_from_without_to_defaults_the_end_of_the_range_to_today(): void
    {
        Http::fake(function ($request) {
            $to = (int) ($request->data()['endDateTime'] ?? 0);
            $expectedTo = \Illuminate\Support\Carbon::now('Asia/Manila')->endOfDay()->timestamp;
            $this->assertEqualsWithDelta($expectedTo, $to, 2);
            return Http::response(['data' => []], 200);
        });

        $this->artisan('pancake:reconcile-statuses', ['--from' => '2026-01-01'])->assertSuccessful();
    }

    public function test_fails_gracefully_without_credentials(): void
    {
        Setting::set('pancake_api_key', '');
        Setting::set('shop_id', '');

        $this->artisan('pancake:reconcile-statuses')->assertFailed();
    }

    /**
     * Real production crash (2026-08-10): "Fix Now" on Sync Health with
     * days=9 threw an uncaught Illuminate\Http\Client\ConnectionException
     * (cURL error 28, 15s timeout) for one specific order (#1341832) among
     * the candidates, which propagated all the way through Artisan::call()
     * to SyncHealthController::reconcileStatuses() as a fatal 500 — instead
     * of just skipping that one order and correcting the rest. The list-
     * fetch pass above already has an $apiError/self::FAILURE path for a
     * non-2xx response; this pass's per-candidate lookup had no equivalent
     * for a connection-level failure (a thrown exception, not a response),
     * so nothing ever caught it.
     */
    public function test_a_connection_timeout_on_one_candidates_lookup_does_not_crash_the_whole_run(): void
    {
        Order::factory()->create([
            'pancake_order_id'    => '1341832', // the one that times out
            'status_code'         => 8,
            'is_upsell'           => true,
            'is_cancelled_upsell' => false,
            'product'             => 'Sinuxyl',
            'base_product'        => 'Sinuxyl',
            'amount'              => 500.0,
            'pancake_created_at'  => now(),
        ]);
        Order::factory()->create([
            'pancake_order_id'    => '1341487', // must still get corrected despite the above
            'status_code'         => 8,
            'is_upsell'           => true,
            'is_cancelled_upsell' => false,
            'product'             => 'Sinuxyl',
            'base_product'        => 'Sinuxyl',
            'amount'              => 500.0,
            'pancake_created_at'  => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response(['data' => []], 200),
            'pos.pages.fm/api/v1/shops/*/orders/1341832*' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received');
            },
            'pos.pages.fm/api/v1/shops/*/orders/1341487*' => Http::response(['data' => [
                'id' => 1341487, 'status' => 8, 'total_price' => 500,
                'tags'  => [['id' => 1, 'name' => 'UPSELL TSD - Sinuxyl Inhaler']],
                'items' => [['variation_info' => ['name' => 'Sinuxyl', 'retail_price' => 500], 'quantity' => 1]],
            ]], 200),
        ]);

        $this->artisan('pancake:reconcile-statuses')->assertSuccessful();

        // The timed-out one is left exactly as it was — never corrected,
        // never crashed the process, safe to pick up again next run.
        $this->assertTrue(Order::where('pancake_order_id', '1341832')->first()->is_upsell);
        // The other candidate's correction still went through.
        $this->assertFalse(Order::where('pancake_order_id', '1341487')->first()->is_upsell);
    }

    /** Same class of bug, first pass: the paginated list-fetch's own
     *  Http::get() (distinct call site from the per-candidate one above) was
     *  equally unguarded — a connection failure here must resolve through
     *  the existing $apiError/self::FAILURE path (matching a non-2xx
     *  response), not escape as an uncaught exception either. */
    public function test_a_connection_timeout_on_the_list_fetch_fails_gracefully_instead_of_crashing(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received');
            },
        ]);

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
            // retail_price included on both items (unlike before pass 3
            // existed) — this same live response now also feeds
            // reconcileUpsellAmounts(), which needs a realistic price to
            // confirm $800 is genuinely still correct, not just untouched
            // because the field happened to be absent.
            'pos.pages.fm/api/v1/shops/*/orders/*' => Http::response(['data' => [
                'id'     => 1341999,
                'status' => 2,
                'tags'   => [],
                'items'  => [
                    ['variation_info' => ['name' => 'Clear Sight 3.0', 'retail_price' => 800], 'quantity' => 1],
                    ['variation_info' => ['name' => 'Clear Sight 3.0', 'retail_price' => 800], 'quantity' => 1],
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

    /**
     * Third pass — explicit request (2026-08-10): "Fix Now" should also
     * correct Total Cross-Sell Sales' actual number, not just which orders
     * count toward it. product/base_product deliberately differ here so
     * reconcileStaleUpsellTags()'s narrower candidate query (pass 2) never
     * touches this order first — this is testing pass 3 in isolation.
     */
    public function test_corrects_a_still_active_upsells_amount_when_it_has_drifted_from_live_pancake_data(): void
    {
        Order::factory()->create([
            'pancake_order_id'    => '1350001',
            'status_code'         => 8,
            'is_upsell'           => true,
            'is_cancelled_upsell' => false,
            'product'             => 'Ear Relief Balm',
            'base_product'        => 'AudiCure', // != product, so pass 2 skips it
            'amount'              => 400.0,      // stale — live shows 600
            'pancake_created_at'  => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*'      => Http::response(['data' => []], 200),
            'pos.pages.fm/api/v1/shops/*/orders/1350001*' => Http::response(['data' => [
                'id'    => 1350001,
                'items' => [
                    ['variation_info' => ['name' => 'AudiCure', 'retail_price' => 500], 'quantity' => 1],
                    ['variation_info' => ['name' => 'Ear Relief Balm', 'retail_price' => 600], 'quantity' => 1],
                ],
            ]], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $order = Order::where('pancake_order_id', '1350001')->first();
        $this->assertTrue($order->is_upsell); // still a real upsell — only the amount was wrong
        $this->assertSame(600.0, (float) $order->amount);
    }

    /** An amount that already matches live data is left alone — and NOT
     *  counted as corrected (proven via the flash message elsewhere;
     *  here just confirming the value itself doesn't churn). */
    public function test_leaves_an_upsells_amount_alone_when_it_already_matches_live_data(): void
    {
        Order::factory()->create([
            'pancake_order_id'    => '1350002',
            'status_code'         => 8,
            'is_upsell'           => true,
            'is_cancelled_upsell' => false,
            'product'             => 'Ear Relief Balm',
            'base_product'        => 'AudiCure',
            'amount'              => 600.0, // already correct
            'pancake_created_at'  => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*'      => Http::response(['data' => []], 200),
            'pos.pages.fm/api/v1/shops/*/orders/1350002*' => Http::response(['data' => [
                'id'    => 1350002,
                'items' => [
                    ['variation_info' => ['name' => 'AudiCure', 'retail_price' => 500], 'quantity' => 1],
                    ['variation_info' => ['name' => 'Ear Relief Balm', 'retail_price' => 600], 'quantity' => 1],
                ],
            ]], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $this->assertSame(0, (int) Setting::get('order_status_reconcile_last_amount_corrected'));
        $this->assertSame(600.0, (float) Order::where('pancake_order_id', '1350002')->first()->amount);
    }

    /**
     * Confirmed live (2026-08-12), order #1347336 (Joana): a SEPARATE
     * PARCEL order's sole remaining item is the tag's named BASE, the exact
     * shape extractUpsellAmount() would normally zero out as a cancelled
     * upsell. Without the SEPARATE PARCEL exception in extractUpsellAmount()
     * itself, this pass would recompute 0 from live data on every run and
     * silently zero the amount right back out even after SyncTodayOrders
     * correctly stored it — same class of bug as the stale-tag pass this
     * file already guards elsewhere.
     */
    public function test_does_not_zero_a_separate_parcel_upsells_amount_on_reconcile(): void
    {
        Order::factory()->create([
            'pancake_order_id'    => '1347336',
            'status_code'         => 8,
            'is_upsell'           => true,
            'is_cancelled_upsell' => false,
            'product'             => null,
            'base_product'        => null,
            'amount'              => 1000.0,
            'pancake_created_at'  => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*'      => Http::response(['data' => []], 200),
            'pos.pages.fm/api/v1/shops/*/orders/1347336*' => Http::response(['data' => [
                'id'    => 1347336,
                'tags'  => [
                    ['id' => 1, 'name' => 'JOANA'],
                    ['id' => 2, 'name' => 'UPSELL TSD - Pterygium + Haplunas'],
                    ['id' => 3, 'name' => 'SEPARATE PARCEL'],
                ],
                'items' => [
                    ['variation_info' => ['name' => 'Pterygium', 'retail_price' => 1000], 'quantity' => 1],
                ],
            ]], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $order = Order::where('pancake_order_id', '1347336')->first();
        $this->assertTrue($order->is_upsell);
        $this->assertSame(1000.0, (float) $order->amount);
    }

    /**
     * Real risk with pooled concurrency: one order's connection failure
     * must not silently drop every other order in the same batch — proven
     * here with a genuine Guzzle-level rejection (same technique
     * SyncTodayOrdersOverlapAndErrorHandlingTest already uses for its own
     * pooled-request failure test), not a bare thrown exception, since
     * that's the proven way a pool failure actually surfaces in this
     * Laravel version's Http::fake.
     */
    public function test_a_connection_failure_on_one_upsells_amount_check_does_not_stop_others_in_the_same_batch(): void
    {
        Order::factory()->create([
            'pancake_order_id'    => '1350010', // this one fails
            'status_code'         => 8,
            'is_upsell'           => true,
            'is_cancelled_upsell' => false,
            'product'             => 'Ear Relief Balm',
            'base_product'        => 'AudiCure',
            'amount'              => 400.0,
            'pancake_created_at'  => now(),
        ]);
        Order::factory()->create([
            'pancake_order_id'    => '1350011', // this one must still get corrected
            'status_code'         => 8,
            'is_upsell'           => true,
            'is_cancelled_upsell' => false,
            'product'             => 'Ear Relief Balm',
            'base_product'        => 'AudiCure',
            'amount'              => 400.0,
            'pancake_created_at'  => now(),
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/orders?')) {
                return Http::response(['data' => []], 200);
            }
            if (str_contains($request->url(), '/orders/1350010')) {
                $url = $request->url();
                return Create::rejectionFor(new ConnectException(
                    "cURL error 28: Operation timed out for {$url}",
                    new Psr7Request('GET', $url)
                ));
            }
            return Http::response(['data' => [
                'id'    => 1350011,
                'items' => [
                    ['variation_info' => ['name' => 'AudiCure', 'retail_price' => 500], 'quantity' => 1],
                    ['variation_info' => ['name' => 'Ear Relief Balm', 'retail_price' => 600], 'quantity' => 1],
                ],
            ]], 200);
        });

        $this->artisan('pancake:reconcile-statuses')->assertSuccessful();

        $this->assertSame(400.0, (float) Order::where('pancake_order_id', '1350010')->first()->amount);
        $this->assertSame(600.0, (float) Order::where('pancake_order_id', '1350011')->first()->amount);
    }

    /**
     * Real production bug (2026-08-11), Mariel/Aug 1: order #1340544 was
     * synced with amount=1300 (the WHOLE order's total_price — the old,
     * wrong behavior for a Returned/Returning row) instead of 500 (the real
     * add-on value, already sitting correctly in returned_upsell_amount).
     * This pass now includes is_returned_upsell rows specifically so
     * already-synced bad data like this gets corrected retroactively, not
     * just future syncs (see SyncTodayOrders::handle()'s own fix, same date).
     */
    public function test_corrects_a_returned_upsells_amount_from_the_whole_order_total_down_to_just_the_add_on(): void
    {
        Order::factory()->create([
            'pancake_order_id'    => '1350020',
            'status_code'         => 5, // Returning — void, is_upsell forced false
            'is_upsell'           => false,
            'is_returned_upsell'  => true,
            'amount'              => 1300.0, // stale: the whole order's total
            'returned_upsell_amount' => 500.0, // already correct, untouched by this pass
            'pancake_created_at'  => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*'      => Http::response(['data' => []], 200),
            'pos.pages.fm/api/v1/shops/*/orders/1350020*' => Http::response(['data' => [
                'id'    => 1350020,
                'items' => [
                    ['variation_info' => ['name' => 'Sinuxyl', 'retail_price' => 800], 'quantity' => 1],
                    ['variation_info' => ['name' => 'Sinuxyl Inhaler', 'retail_price' => 500], 'quantity' => 1],
                ],
            ]], 200),
        ]);

        $this->artisan('pancake:reconcile-statuses')->assertSuccessful();

        $order = Order::where('pancake_order_id', '1350020')->first();
        $this->assertSame(500.0, (float) $order->amount);
        $this->assertSame(500.0, (float) $order->returned_upsell_amount); // unchanged, was already right
    }

    /**
     * Real production bug (2026-08-11), Mariel/Aug 1, order #1340590:
     * tagged "Upsell TSD (Ear Relief Balm)" but the live order's sole
     * remaining item is "AudiCure" — the base, not the addon
     * (remainingItemIsJustTheBase() proves it). This pass's candidate
     * query never checked is_returned_upsell rows at all before this fix,
     * so an order that was NEVER a genuine surviving upsell stayed counted
     * as one (contributing to TSA Performance's upsell count) indefinitely.
     */
    public function test_corrects_a_stale_tag_on_a_returned_upsell_whose_remaining_item_is_just_the_base(): void
    {
        Order::factory()->create([
            'pancake_order_id'       => '1340590',
            'status_code'            => 5, // Returning
            'is_upsell'              => false,
            'is_returned_upsell'     => true,
            'is_cancelled_upsell'    => false,
            'product'                => 'AudiCure',
            'base_product'           => 'AudiCure', // matches — candidate signature
            'amount'                 => 0.0,
            'returned_upsell_amount' => 0.0,
            'pancake_created_at'     => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*'      => Http::response(['data' => []], 200),
            'pos.pages.fm/api/v1/shops/*/orders/1340590*' => Http::response(['data' => [
                'id'          => 1340590,
                'status'      => 5,
                'total_price' => 800,
                'tags'        => [['id' => 1, 'name' => 'Upsell TSD (Ear Relief Balm)']],
                'items'       => [
                    ['variation_info' => ['name' => 'AudiCure', 'retail_price' => 800], 'quantity' => 1],
                ],
            ]], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $order = Order::where('pancake_order_id', '1340590')->first();
        $this->assertFalse($order->is_returned_upsell);
        $this->assertTrue($order->is_cancelled_upsell);
        $this->assertSame(0.0, (float) $order->returned_upsell_amount);
        $this->assertSame(800.0, (float) $order->amount);
    }
}
