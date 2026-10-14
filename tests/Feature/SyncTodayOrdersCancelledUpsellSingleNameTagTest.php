<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Confirmed in production (order #1341487, 2026-08-02): remainingItemIsJustTheBase()
 * only recognized "UPSELL TSD - Base + Addon" tags (both names, joined by "+") as
 * proof the add-on was removed from a single-item order. Most real upsell tags name
 * ONLY the add-on — "UPSELL TSD - <Addon>" (no "+") or "Upsell TSD (<Addon>)" — so
 * that check silently never fired for them, and a ₱500 base-only order tagged
 * "UPSELL TSD - Sinuxyl Inhaler" (the actual add-on was never added, or was later
 * removed — Pancake's own order only ever showed the one ₱500 "Sinuxyl" item) got
 * counted as a live ₱500 upsell instead of a cancelled one.
 */
class SyncTodayOrdersCancelledUpsellSingleNameTagTest extends TestCase
{
    use RefreshDatabase;

    private function fakeOnePage(array $order): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::sequence()
                ->push(['data' => [$order]])
                ->push(['data' => []]),
        ]);
    }

    public function test_single_item_order_with_dash_addon_only_tag_is_not_counted_as_a_live_upsell(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1341487,
            'status'      => 8,
            'total_price' => 500,
            'cod'         => 500,
            'inserted_at' => '2026-08-02T11:13:00',
            'updated_at'  => '2026-08-02T11:13:00',
            'tags'        => [
                ['id' => 1, 'name' => 'MARIEL'],
                ['id' => 2, 'name' => 'Call in Progress (Sinuxyl Inhaler)'],
                ['id' => 3, 'name' => 'UPSELL TSD - Sinuxyl Inhaler'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Sinuxyl', 'retail_price' => 500], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-02']);

        $order = Order::where('pancake_order_id', '1341487')->first();

        $this->assertNotNull($order);
        $this->assertFalse($order->is_upsell, 'A single-item order whose only item is the base product must not count as a live upsell');
        $this->assertTrue($order->is_cancelled_upsell);
        $this->assertSame(500.0, (float) $order->amount);
    }

    public function test_single_item_order_with_parenthetical_addon_only_tag_is_not_counted_as_a_live_upsell(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1341488,
            'status'      => 8,
            'total_price' => 800,
            'cod'         => 800,
            'inserted_at' => '2026-08-02T11:13:00',
            'updated_at'  => '2026-08-02T11:13:00',
            'tags'        => [
                ['id' => 1, 'name' => 'KATHLEEN'],
                ['id' => 2, 'name' => 'Call in Progress (Audicure)'],
                ['id' => 3, 'name' => 'Upsell TSD (Ear Relief Balm)'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'AudiCure', 'retail_price' => 800], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-02']);

        $order = Order::where('pancake_order_id', '1341488')->first();

        $this->assertNotNull($order);
        $this->assertFalse($order->is_upsell);
        $this->assertTrue($order->is_cancelled_upsell);
    }

    /** A genuinely live single-item upsell order — the one item IS the add-on
     *  itself (no base item at all in the cart) — must still count normally.
     *  Guards against the new "does the sole item match the addon name" check
     *  being backwards. */
    public function test_single_item_order_where_the_item_is_genuinely_the_addon_still_counts_as_upsell(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1341999,
            'status'      => 2,
            'total_price' => 1000,
            'inserted_at' => '2026-08-02T11:13:00',
            'updated_at'  => '2026-08-02T11:13:00',
            'tags'        => [
                ['id' => 1, 'name' => 'MARIEL'],
                ['id' => 2, 'name' => 'Call in Progress (Sinuxyl Inhaler)'],
                ['id' => 3, 'name' => 'UPSELL TSD - Sinuxyl Inhaler'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Sinuxyl Inhaler', 'retail_price' => 1000], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-02']);

        $order = Order::where('pancake_order_id', '1341999')->first();

        $this->assertNotNull($order);
        $this->assertTrue($order->is_upsell);
        $this->assertFalse($order->is_cancelled_upsell);
    }

    /**
     * Confirmed live (2026-08-12), order #1347336 (Joana): a SEPARATE
     * PARCEL-tagged order has the same base-only shape as a cancelled
     * upsell (sole remaining item matches the tag's named base) — but the
     * add-on wasn't cancelled, it shipped as its own sibling order instead.
     * Must still count as a real upsell, not a cancelled one, and its
     * amount must not be zeroed out either — it's a real TSA-driven sale.
     */
    public function test_single_item_order_with_separate_parcel_tag_still_counts_as_a_live_upsell(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1347336,
            'status'      => 8,
            'total_price' => 1000,
            'cod'         => 1000,
            'inserted_at' => '2026-08-12T14:51:00',
            'updated_at'  => '2026-08-12T14:51:00',
            'tags'        => [
                ['id' => 1, 'name' => 'JOANA'],
                ['id' => 2, 'name' => 'UPSELL TSD - Pterygium + Haplunas'],
                ['id' => 3, 'name' => 'SEPARATE PARCEL'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Pterygium', 'retail_price' => 1000], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-12']);

        $order = Order::where('pancake_order_id', '1347336')->first();

        $this->assertNotNull($order);
        $this->assertTrue($order->is_upsell, 'A SEPARATE PARCEL order must still count as a live upsell, not a cancelled one');
        $this->assertFalse($order->is_cancelled_upsell);
        $this->assertSame(1000.0, (float) $order->amount, 'A SEPARATE PARCEL order\'s amount must not be zeroed out — it is a real TSA sale');
    }

    /**
     * Explicit follow-up request (2026-09-03: TSA Performance's Per-Product
     * Hourly Breakdown undercounted a real upsell the Dashboard leaderboard
     * already counted correctly, "8500 only" vs the correct 9500) — confirmed
     * live, order #1363274 (Kathleen Santilleses): a single-item SEPARATE
     * PARCEL order whose upsell tag names the BASE product only ("TSD UPSELL
     * - GINSENG SERUM", no "+" combo), and whose one remaining item
     * (TURMERIC SOAP) is the real upsold add-on itself, shipped separately —
     * not the base surviving after the add-on was cancelled.
     * extractUpsellProduct() zeroed out product/base_product/
     * bundle_description for this exact shape (missing the same
     * !hasSeparateParcelTag() guard the is_upsell/is_cancelled_upsell check
     * above already had), silently dropping it from every per-product table
     * (Leads Report, TSA Performance) while the Dashboard leaderboard kept
     * counting it fine (isBroadRealUpsell()/realUpsellAmount() never depend
     * on product/base_product at all).
     */
    public function test_single_item_separate_parcel_order_still_resolves_a_real_product_name(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1363274,
            'status'      => 8,
            'total_price' => 1000,
            'cod'         => 1000,
            'inserted_at' => '2026-09-03T14:00:00',
            'updated_at'  => '2026-09-03T14:00:00',
            'tags'        => [
                ['id' => 1, 'name' => 'KATH'],
                ['id' => 2, 'name' => 'TSD UPSELL - GINSENG SERUM'],
                ['id' => 3, 'name' => 'SEPARATE PARCEL'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'TURMERIC SOAP', 'retail_price' => 1000], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-09-03']);

        $order = Order::where('pancake_order_id', '1363274')->first();

        $this->assertNotNull($order);
        $this->assertTrue($order->is_upsell);
        $this->assertSame(1000.0, (float) $order->amount);
        $this->assertSame('TURMERIC SOAP', $order->product, 'The real upsold add-on name must not be blanked out just because it shipped as its own SEPARATE PARCEL order');
    }

    /**
     * Root-caused 2026-09-11, real production order #1366186 (Kathleen
     * Santilleses, Sep 9): a TSA cancelled an upsell by typing "cancelled
     * upsell" directly into Pancake's Note field, WITHOUT the add-on item
     * ever being removed from the order and without any status change — the
     * order's sole remaining item (GINSENG SERUM) matches its own upsell
     * tag's named base product, the exact shape
     * remainingItemIsJustTheBase()'s dash branch reads as "the addon is
     * still here" (str_contains($name, $addon) is true since the tag names
     * GINSENG SERUM itself, not some other add-on). No structural signal —
     * item list, tags, status_code — ever changes for this shape, which is
     * why the user's own manual Sync Health "Fix Now" reconcile could not
     * fix it: every structural check correctly re-derived "not stale" every
     * time. Only the human-typed note carries the fact that this was
     * cancelled.
     */
    public function test_note_saying_cancelled_upsell_is_not_counted_as_a_live_upsell_even_with_no_item_change(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1366186,
            'status'      => 8,
            'total_price' => 499,
            'cod'         => 499,
            'inserted_at' => '2026-09-09T15:47:00',
            'updated_at'  => '2026-09-09T15:47:00',
            'note'        => "kath\n9-10 HOLD BY TSS GRETCHEN\ncancelled upsell",
            'tags'        => [
                ['id' => 1, 'name' => 'KATH'],
                ['id' => 2, 'name' => 'Call in Progress (Ginseng)'],
                ['id' => 3, 'name' => 'TSD UPSELL - GINSENG SERUM'],
                ['id' => 4, 'name' => 'INSTRUCTION (BELO SET)'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'GINSENG SERUM', 'retail_price' => 499], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-09-09']);

        $order = Order::where('pancake_order_id', '1366186')->first();

        $this->assertNotNull($order);
        $this->assertFalse($order->is_upsell, 'A note saying "cancelled upsell" must exclude the order from live upsell counts even though its item list never changed');
        $this->assertTrue($order->is_cancelled_upsell);
        $this->assertSame(499.0, (float) $order->amount, 'The order still ships — its base amount must not be zeroed out, only excluded from upsell revenue');
    }

    /**
     * Root-caused 2026-10-13, real production order #1377777 (Katherine
     * Chua, Oct 7): same "cancelled upsell" note pattern as #1366186 above,
     * but a DIFFERENT staff member added a later line in the same note
     * retracting it — "10-09 as per TSS GRETCHEN I-DISREGARD NA ITO DAHIL
     * NA PROCEED NA ANG ORDER NI CS W/ UPSELL" ("disregard this, the order
     * with the upsell did proceed after all"). Since is_cancelled_upsell is
     * recomputed fresh from Pancake's live note text on every sync, the
     * stale cancellation phrase kept re-matching forever — the correction
     * had no way to ever take effect. A disregard phrase appearing AFTER
     * the cancellation phrase in the note must suppress the exclusion.
     */
    public function test_a_later_disregard_note_un_cancels_an_earlier_cancelled_upsell_note(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1377777,
            'status'      => 11,
            'total_price' => 1000,
            'cod'         => 1000,
            'inserted_at' => '2026-10-07T12:50:47',
            'updated_at'  => '2026-10-07T12:50:47',
            'note'        => "chua - hindi po nakita na may DP si CX kaya na cancelled upsell \r\n"
                . "humingi po ako ng DP para po sure na i receive hindi ko po na notice na HIGH RTS \r\n\r\n\r\n"
                . "HOLD MUNA FOR INVESTIGATION\r\n\r\n\r\n"
                . "10-09 as per TSS GRETCHEN I-DISREGARD NA ITO DAHIL NA PROCEED NA ANG ORDER NI CS W/ UPSELL",
            'tags' => [
                ['id' => 1, 'name' => 'KATHERINE'],
                ['id' => 2, 'name' => 'PTERYLIEF'],
                ['id' => 3, 'name' => 'UPSELL TSD - PTERYLIEF+ LUMICARE + HAPLUNAS'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Lumicare Oil', 'retail_price' => 500], 'quantity' => 1],
                ['variation_info' => ['name' => 'Haplunas Healing Eye Cream', 'retail_price' => 500], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-10-07']);

        $order = Order::where('pancake_order_id', '1377777')->first();

        $this->assertNotNull($order);
        $this->assertFalse($order->is_cancelled_upsell, 'A later "disregard" line in the same note must override an earlier "cancelled upsell" phrase');
    }

    /** Guards against the fix above being backwards: a disregard phrase
     *  appearing BEFORE the cancellation phrase must NOT suppress it —
     *  that would read as disregarding something not yet mentioned. */
    public function test_a_disregard_note_appearing_before_the_cancellation_phrase_does_not_suppress_it(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1377778,
            'status'      => 8,
            'total_price' => 499,
            'cod'         => 499,
            'inserted_at' => '2026-10-07T12:50:47',
            'updated_at'  => '2026-10-07T12:50:47',
            'note'        => "DISREGARD the earlier note about DP\ncancelled upsell per TSA confirmation",
            'tags'        => [
                ['id' => 1, 'name' => 'KATH'],
                ['id' => 2, 'name' => 'TSD UPSELL - GINSENG SERUM'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'GINSENG SERUM', 'retail_price' => 499], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-10-07']);

        $order = Order::where('pancake_order_id', '1377778')->first();

        $this->assertNotNull($order);
        $this->assertTrue($order->is_cancelled_upsell, 'A disregard phrase written BEFORE the cancellation phrase must not retroactively un-cancel it');
    }

    /**
     * Root-caused live 2026-10-14, real production order #1375218
     * (Mariel, NutriLay): is_cancelled_upsell's own structural check
     * (remainingItemIsJustTheBase()) used to be gated behind
     * !$isExcludedStatus — deliberately, per this file's own original
     * intent, "this flag is for an ACTIVE order, not a void/cancelled
     * ORDER." That reasoning broke once Order::isBroadRealUpsell()'s own
     * tag-fallback branch was built specifically to recover a genuine
     * upsell FROM a void-status order — is_cancelled_upsell is the ONLY
     * thing that can stop that fallback from misfiring on a stale tag,
     * and the gate meant it could never do that job for exactly the
     * Cancelled/Restocking orders the fallback targets. This order is
     * Cancelled(6), carries tag "UPSELL TSD - NUTRILAY + NUTRIGAIN", but
     * its sole remaining item is "NutriLay" (the tag's own named base) —
     * the Nutrigain add-on was never actually added. Also flagged
     * "DUPLICATED BY LOGISTICS" (the same real-production detail that
     * exposed this bug: that note no longer blocks $hasUpsellTag, see
     * this file's own 2026-10-13 fix history), confirming the real
     * end-to-end production shape.
     */
    public function test_a_stale_cancelled_upsell_on_a_void_status_order_is_still_recognized(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1375218,
            'status'      => 6,
            'total_price' => 798,
            'cod'         => 798,
            'inserted_at' => '2026-10-02T15:37:00',
            'updated_at'  => '2026-10-02T15:37:00',
            'note'        => "yel - upsell 450\r\n\r\nCancelled Order.\r\n\r\nDUPLICATED BY LOGISTICS",
            'tags'        => [
                ['id' => 1, 'name' => 'MARIEL'],
                ['id' => 2, 'name' => 'INSTRUCTION NUTRIGAIN'],
                ['id' => 3, 'name' => 'NUTRIGAIN PICTURE'],
                ['id' => 4, 'name' => 'UPSELL TSD - NUTRILAY + NUTRIGAIN'],
                ['id' => 5, 'name' => 'NUTRILAY'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'NutriLay', 'retail_price' => 798], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-10-02']);

        $order = Order::where('pancake_order_id', '1375218')->first();

        $this->assertNotNull($order);
        $this->assertTrue($order->is_duplicated_by_logistics, 'test setup: confirms the real end-to-end production shape');
        $this->assertTrue($order->is_cancelled_upsell, 'the Nutrigain add-on was never actually added — this is not a real upsell, even on a Cancelled-status order');
        $this->assertFalse($order->is_upsell);
        $this->assertFalse($order->is_upsell_on_voided_order);
        $this->assertFalse(Order::isBroadRealUpsell($order), 'must not be recognized as a real upsell at all');
    }

    /** Guards the fix above from being too broad: a GENUINE upsell on a
     *  void-status order (the add-on item still present) must still be
     *  recognized correctly — this is exactly #1377777's own shape from
     *  earlier the same week (Restocking status, 2-item order), confirming
     *  removing the !$isExcludedStatus gate didn't accidentally make every
     *  void-status order look stale. */
    public function test_a_genuine_upsell_on_a_void_status_order_is_unaffected(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1375219,
            'status'      => 6,
            'total_price' => 1596,
            'cod'         => 1596,
            'inserted_at' => '2026-10-02T15:37:00',
            'updated_at'  => '2026-10-02T15:37:00',
            'tags'        => [
                ['id' => 1, 'name' => 'MARIEL'],
                ['id' => 2, 'name' => 'UPSELL TSD - NUTRILAY + NUTRIGAIN'],
                ['id' => 3, 'name' => 'NUTRILAY'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'NutriLay', 'retail_price' => 798], 'quantity' => 1],
                ['variation_info' => ['name' => 'Nutrigain', 'retail_price' => 798], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-10-02']);

        $order = Order::where('pancake_order_id', '1375219')->first();

        $this->assertNotNull($order);
        $this->assertFalse($order->is_cancelled_upsell, 'the Nutrigain add-on IS present — this is a genuine upsell');
        $this->assertTrue($order->is_upsell_on_voided_order);
        $this->assertSame(798.0, (float) $order->amount, 'isolated to just the Nutrigain add-on price');
    }
}
