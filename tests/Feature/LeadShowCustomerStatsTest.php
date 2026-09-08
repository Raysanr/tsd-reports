<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Root-caused 2026-09-08 (real production reports, orders #1365574/
 * #1365559/#1365556: the Leads modal's success/return-rate bar showed 0/0 or
 * a wrong ratio while Pancake's own POS tooltip showed real order history —
 * e.g. "4 successful / 0 returned" — for the exact same customer). Cause:
 * the bar used to read straight off the order-detail endpoint's embedded
 * 'customer' sub-object (succeed_order_count/returned_order_count), which is
 * scoped to ONE customer_id record — Pancake can silently create a fresh,
 * empty customer_id for what is still the same real person/phone number, and
 * that new record's own counter starts back at 0/0 regardless of their real
 * history elsewhere.
 *
 * Fix: PancakeOrderTagApi::getCustomerOrderStats() now searches Pancake's
 * own orders API by phone number and tallies real order statuses directly —
 * confirmed live this does not always reproduce POS's own exact figure (its
 * internal definition couldn't be determined from the API alone), but it's
 * real order data, not a single customer_id's possibly-fresh counter.
 *
 * Also moved out of the synchronous show()/getOrderDetail() path entirely
 * (explicit follow-up, same day: "fetch it in the background, not blocking
 * the modal") — the phone-number search is slow/unreliable (confirmed live:
 * ~1s to 20+s, regardless of page_size), so LeadController::customerStats()
 * is its own separate async endpoint calls.js fetches after the modal has
 * already rendered. See LeadShowTest's own updated test for the loading-
 * placeholder coverage on the main page.
 */
class LeadShowCustomerStatsTest extends TestCase
{
    use RefreshDatabase;

    private function makeLead(string $orderId): Lead
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        return Lead::create([
            'pancake_order_id' => $orderId, 'customer_name' => 'Test Customer',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
        ]);
    }

    public function test_customer_stats_endpoint_tallies_real_orders_by_phone_number(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $lead = $this->makeLead('s20');

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders/s20*' => Http::response(['data' => [
                'items' => [],
                'tags'  => [],
                'bill_phone_number' => '09624806238',
            ]]),
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'success' => true,
                'total_pages' => 1,
                'data' => [
                    ['id' => '1357642', 'status' => 3, 'bill_phone_number' => '09624806238'],
                    ['id' => '1275835', 'status' => 5, 'bill_phone_number' => '09624806238'],
                    ['id' => '1242212', 'status' => 3, 'bill_phone_number' => '09624806238'],
                    // Loosely matched by Pancake's own `search` param but a
                    // DIFFERENT phone number — must be excluded from the
                    // tally (confirmed live: `search` is not an exact-phone
                    // filter, it also returns unrelated customers' orders).
                    ['id' => '999999', 'status' => 3, 'bill_phone_number' => '09999999999'],
                ],
            ]),
        ]);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->getJson(route('calls.leads.customer-stats', $lead));

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'stats' => [
                'succeed_count'  => 2,
                'returned_count' => 1,
                'total_count'    => 3,
            ],
        ]);
    }

    public function test_status_16_collected_money_also_counts_as_successful(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $lead = $this->makeLead('s21');

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders/s21*' => Http::response(['data' => [
                'items' => [], 'tags' => [], 'bill_phone_number' => '09111111111',
            ]]),
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'success' => true, 'total_pages' => 1,
                'data' => [
                    ['id' => '1', 'status' => 16, 'bill_phone_number' => '09111111111'],
                ],
            ]),
        ]);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->getJson(route('calls.leads.customer-stats', $lead));

        $response->assertOk();
        $response->assertJson(['stats' => ['succeed_count' => 1, 'returned_count' => 0, 'total_count' => 1]]);
    }

    public function test_returns_null_stats_when_pancake_is_unreachable(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $lead = $this->makeLead('s22');

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders/s22*' => Http::response(['data' => [
                'items' => [], 'tags' => [], 'bill_phone_number' => '09222222222',
            ]]),
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response('Server error', 500),
        ]);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->getJson(route('calls.leads.customer-stats', $lead));

        $response->assertOk();
        $response->assertJson(['success' => true, 'stats' => null]);
    }

    public function test_a_tsa_cannot_fetch_customer_stats_for_another_tsas_lead(): void
    {
        $lead = $this->makeLead('s23');

        $other = TsaShift::where('tsa_key', '!=', 'Gemma')->first();
        $user  = User::factory()->create(['role' => 'tsa', 'tsa_id' => $other->id]);

        $response = $this->actingAs($user)->getJson(route('calls.leads.customer-stats', $lead));

        $response->assertForbidden();
    }
}
