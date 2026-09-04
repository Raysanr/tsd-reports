<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift, routes -> calls.*. */
class LeadShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tsa_can_view_their_own_leads_detail_page_with_its_activity_timeline(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => 's1', 'customer_name' => 'Show Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        LeadActivity::log($lead, 'created', 'Lead pulled in from Pancake order #s1.');
        LeadActivity::log($lead, 'assigned', 'Round-robin assigned to Gemma De Guzman.');
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.show', $lead));

        $response->assertOk();
        $response->assertSee('Show Test');
        $response->assertSee('Lead pulled in from Pancake order #s1.');
        $response->assertSee('Round-robin assigned to Gemma De Guzman.');
    }

    public function test_a_tsa_cannot_view_another_tsas_lead_detail_page(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => 's2', 'customer_name' => 'Not Mine', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned']);
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->get(route('calls.leads.show', $lead))->assertForbidden();
    }

    public function test_an_admin_can_view_any_leads_detail_page(): void
    {
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => 's3', 'customer_name' => 'Admin View', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('calls.leads.show', $lead))->assertOk();
    }

    public function test_a_lead_with_a_conversation_link_shows_the_open_in_pancake_button(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => 's4', 'customer_name' => 'Has Conversation', 'product_id' => $product->id,
            'tsa_id' => $gemma->id, 'status' => 'assigned',
            'conversation_link' => 'https://pancake.vn/123456?customer_id=abc',
        ]);
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.show', $lead));

        $response->assertOk();
        $response->assertSee('https://pancake.vn/123456?customer_id=abc', false);
        $response->assertSee('Open in Pancake');
    }

    public function test_a_lead_without_a_conversation_link_does_not_show_the_button(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => 's5', 'customer_name' => 'No Conversation', 'product_id' => $product->id,
            'tsa_id' => $gemma->id, 'status' => 'assigned',
        ]);
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.show', $lead));

        $response->assertOk();
        $response->assertDontSee('Open in Pancake');
    }

    /** Explicit request (2026-08-25): "same UI as in the POS ... pop up like
     *  a modal" — the Leads table now fetches this same route with an
     *  X-Table-Refresh header to populate the modal instead of navigating
     *  away. Covers that the header switches to the partial-only response
     *  (no full page chrome), same AJAX-partial convention TSA Management's
     *  own table already uses. */
    public function test_x_table_refresh_header_returns_the_partial_not_the_full_page(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => 's6', 'customer_name' => 'Modal Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $full   = $this->actingAs($user)->get(route('calls.leads.show', $lead));
        $modal  = $this->actingAs($user)->get(route('calls.leads.show', $lead), ['X-Table-Refresh' => '1']);

        $full->assertOk();
        $modal->assertOk();
        $modal->assertSee('Modal Test');
        // The full page renders the shared sidebar (every calls.* page has
        // one); the modal partial must not — that's the whole point of the
        // AJAX-partial branch.
        $full->assertSee('Call Tracker');
        $modal->assertDontSee('Call Tracker');
    }

    /** Lead itself never stores a price — the product card cross-references
     *  the separate `orders` table by the same pancake_order_id (a
     *  different local sync pipeline than Leads, see
     *  LeadController::show()'s own comment) for real bundle/amount data. */
    /** Explicit follow-up request (2026-08-25): "when my pointer is in the
     *  save button there will be popup like this in the POS" — the modal's
     *  footer Save bar also carries the same real order-status pill trigger
     *  the Leads table's own row already has (openOrderStatusPill(), the
     *  shared #orderStatusPanel dropdown) — reused, not duplicated. */
    public function test_the_footer_shows_the_real_order_status_pill(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => 's9', 'customer_name' => 'Status Pill Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        Order::create([
            'pancake_order_id'   => 's9',
            'team'                => 'SH Naturals',
            'status_code'         => 8, // Packing
            'pancake_created_at'  => now(),
            'pancake_inserted_at' => now(),
            'synced_at'           => now(),
        ]);
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.show', $lead));

        $response->assertOk();
        $response->assertSee('Packing');
        $response->assertSee("openOrderStatusPill(event, {$lead->id}, 8)", false);
    }

    public function test_the_product_card_shows_real_price_from_the_matching_order(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => 's7', 'customer_name' => 'Priced Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        Order::create([
            'pancake_order_id'   => 's7',
            'team'                => 'SH Naturals',
            'bundle_description'  => '1 Sinuxyl + 1 Sinuxyl Inhaler',
            'amount'              => 800,
            'status_code'         => 3,
            'pancake_created_at'  => now(),
            'pancake_inserted_at' => now(),
            'synced_at'           => now(),
        ]);
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.show', $lead));

        $response->assertOk();
        $response->assertSee('1 Sinuxyl + 1 Sinuxyl Inhaler');
        $response->assertSee('₱800.00', false);
    }

    /** No matching order synced locally yet — the card must still render
     *  cleanly with just the Product catalog name, not error or show a
     *  broken/blank price. */
    public function test_the_product_card_falls_back_gracefully_with_no_matching_order(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => 's8', 'customer_name' => 'Unsynced Order', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.show', $lead));

        $response->assertOk();
        $response->assertSee('SINUXYL');
    }

    /** Explicit follow-up request (2026-08-25): "see too the current upsell
     *  in the pos and also the current pos tags" — $order's own single
     *  summarized line is deliberately just the isolated upsell's own info
     *  for an upsell order (see PancakeOrderTagApi::getOrderDetail()'s own
     *  doc comment), so a genuine multi-item order needs Pancake's real
     *  items[] to show the base product's own line/price too, matching
     *  what Pancake's own order popup shows. */
    public function test_a_multi_item_order_shows_every_real_line_from_pancake(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => 's9', 'customer_name' => 'Multi Item', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders/s9*' => Http::response(['data' => [
                'items' => [
                    ['variation_info' => ['name' => 'Clear Sight 3.0', 'retail_price' => 800], 'quantity' => 1],
                    ['variation_info' => ['name' => '1 Lumicare Oil + 1 Haplunas Healing Eye Cream', 'retail_price' => 1000], 'quantity' => 1],
                ],
                'tags' => [
                    ['id' => 1, 'name' => 'LIKA'],
                    ['id' => 2, 'name' => 'CLEARSIGHT'],
                    ['id' => 3, 'name' => 'UPSELL TSD - CLEARSIGHT + LUMICARE + HAPLUNAS'],
                ],
            ]]),
        ]);

        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);
        $response = $this->actingAs($user)->get(route('calls.leads.show', $lead));

        $response->assertOk();
        $response->assertSee('Clear Sight 3.0');
        $response->assertSee('1 Lumicare Oil + 1 Haplunas Healing Eye Cream');
        $response->assertSee('₱800.00', false);
        $response->assertSee('₱1,000.00', false);
        $response->assertSee('LIKA');
        $response->assertSee('CLEARSIGHT');
        $response->assertSee('UPSELL TSD - CLEARSIGHT + LUMICARE + HAPLUNAS');
    }

    /**
     * Explicit follow-up request (2026-09-04: "can fetch this like rts rate
     * and successful rate of the leads like in the pos") — confirmed live
     * against a real order: the customer sub-object already riding along in
     * this same GET carries succeed_order_count/returned_order_count/
     * order_count, the same 3 numbers Pancake POS's own hover tooltip
     * reads. Return rate is returned ÷ succeeded (matching Pancake's own
     * math: "25 successful / 1 returned" reads as 4%, i.e. 1÷25).
     */
    public function test_shows_the_customers_real_success_and_return_rate_from_pancake(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => 's10', 'customer_name' => 'Repeat Customer', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders/s10*' => Http::response(['data' => [
                'items' => [['variation_info' => ['name' => 'Sinuxyl', 'retail_price' => 800], 'quantity' => 1]],
                'tags'  => [],
                'customer' => [
                    'succeed_order_count'  => 25,
                    'returned_order_count' => 1,
                    'order_count'          => 26,
                ],
            ]]),
        ]);

        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);
        $response = $this->actingAs($user)->get(route('calls.leads.show', $lead));

        $response->assertOk();
        $response->assertSee('Successful orders: 25 / Returned orders: 1', false);
        $response->assertSee('Return rate: 4%', false);
    }

    /**
     * Reversed (explicit follow-up, 2026-09-04: "show it always, even at
     * 0/0" — a brand-new customer's hidden bar read as a missing feature,
     * not an intentional empty state). Must render with no divide-by-zero
     * error, and the track must stay bare gray rather than filling 100%
     * rose/red, which would misread as "100% returned" instead of "no data
     * yet".
     */
    public function test_shows_the_success_rate_bar_even_for_a_customer_with_no_order_history_yet(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => 's11', 'customer_name' => 'Brand New Customer', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders/s11*' => Http::response(['data' => [
                'items' => [['variation_info' => ['name' => 'Sinuxyl', 'retail_price' => 800], 'quantity' => 1]],
                'tags'  => [],
                'customer' => [
                    'succeed_order_count'  => 0,
                    'returned_order_count' => 0,
                    'order_count'          => 1,
                ],
            ]]),
        ]);

        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);
        $response = $this->actingAs($user)->get(route('calls.leads.show', $lead));

        $response->assertOk();
        $response->assertSee('Successful orders: 0 / Returned orders: 0', false);
        $response->assertSee('Return rate: 0%', false);
    }

    /** Explicit follow-up requests (2026-08-25): "add delivery to this like
     *  in the POS", then "make it editable like in the POS" — an editable
     *  form pre-filled from the same order fetch as Products/POS Tags,
     *  courier/tracking stay read-only display. */
    public function test_shows_the_real_delivery_info_from_pancake(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => 's11', 'customer_name' => 'Delivery Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders/s11*' => Http::response(['data' => [
                'items' => [], 'tags' => [],
                'shipping_address' => [
                    'full_name' => 'Victoriano Brugada', 'phone_number' => '09163774053',
                    'address' => 'Landmark: Malapit sa Tower ng Globe Padulo',
                    'full_address' => 'Landmark: Malapit sa Tower ng Globe Padulo, Poblacion ibaba, Angono, Rizal',
                    'province_id' => '63_753', 'province_name' => 'Rizal',
                    'district_id' => '63_75326', 'district_name' => 'Angono',
                    'commune_id' => null, 'commune_name' => null, 'post_code' => '1930',
                ],
                'shipping_fee' => 150,
                'estimate_delivery_date' => '2026-08-28',
                'partner' => ['partner_name' => 'J&T Philippines'],
                'tracking_link' => 'https://order.pke.gg/tracking?id=abc123',
            ]]),
        ]);

        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);
        $response = $this->actingAs($user)->get(route('calls.leads.show', $lead));

        $response->assertOk();
        $response->assertSee('value="Victoriano Brugada"', false);
        $response->assertSee('value="09163774053"', false);
        $response->assertSee('Malapit sa Tower ng Globe Padulo');
        $response->assertSee('value="1930"', false);
        $response->assertSee('J&amp;T Philippines', false);
        $response->assertSee('data-province-id="63_753"', false);
        $response->assertSee('data-district-name="Angono"', false);
    }

    /** No shipping_address at all (Pancake unreachable, or a genuinely
     *  address-less order) — the whole Delivery card must not render, not
     *  show a broken/empty panel. */
    public function test_no_delivery_card_when_pancake_has_no_shipping_address(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => 's12', 'customer_name' => 'Juan Dela Cruz', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders/s12*' => Http::response(['data' => ['items' => [], 'tags' => []]]),
        ]);

        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);
        $response = $this->actingAs($user)->get(route('calls.leads.show', $lead));

        $response->assertOk();
        $response->assertDontSee('Delivery');
    }

    /** Pancake unreachable (timeout, not configured, etc.) — must fall back
     *  to the local summarized card cleanly, never error the whole modal. */
    public function test_falls_back_to_the_local_summary_when_pancake_is_unreachable(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => 's10', 'customer_name' => 'Fallback Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        Order::create([
            'pancake_order_id'   => 's10', 'team' => 'SH Naturals',
            'bundle_description' => 'Fallback Bundle', 'amount' => 750, 'status_code' => 3,
            'pancake_created_at' => now(), 'pancake_inserted_at' => now(), 'synced_at' => now(),
        ]);

        Http::fake(['pos.pages.fm/api/v1/shops/*/orders/s10*' => Http::response([], 500)]);

        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);
        $response = $this->actingAs($user)->get(route('calls.leads.show', $lead));

        $response->assertOk();
        $response->assertSee('Fallback Bundle');
        $response->assertSee('₱750.00', false);
    }
}
