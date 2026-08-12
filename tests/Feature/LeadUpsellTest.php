<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift, routes -> calls.*.
 * Explicit request (2026-08-08): let a TSA search Pancake's real product
 * catalog and add an upsell straight to a lead's linked order, without ever
 * opening Pancake POS itself — see PancakeProductApi (search) and
 * PancakeOrderTagApi::addUpsellItem()/createTagIfMissing() (write) for the
 * mechanics this exercises end-to-end.
 */
class LeadUpsellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('pancake_api_key', 'fake-api-key');
        Setting::set('shop_id', '4');
    }

    private function leadFor(TsaShift $tsa, string $orderId = '9001'): Lead
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        return Lead::create([
            'pancake_order_id' => $orderId, 'customer_name' => 'Juan', 'product_id' => $product->id,
            'tsa_id' => $tsa->id, 'status' => 'assigned',
        ]);
    }

    public function test_search_products_returns_real_catalog_results_for_the_leads_own_tsa(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/products/variations*' => Http::response(['success' => true, 'data' => [
                ['id' => 'var-1', 'product_id' => 'prod-1', 'display_id' => '1 Haplunas Balm', 'retail_price' => 499],
            ]], 200),
        ]);

        $response = $this->actingAs($user)->getJson(route('calls.leads.products', $lead) . '?q=hap');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame('1 Haplunas Balm', $response->json('products.0.name'));
    }

    public function test_a_tsa_cannot_search_products_on_another_tsas_lead(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $lead   = $this->leadFor($gemma);
        $user   = User::create(['name' => 'Mariel User', 'email' => 'mariel@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $mariel->id]);

        $response = $this->actingAs($user)->getJson(route('calls.leads.products', $lead) . '?q=hap');

        $response->assertForbidden();
    }

    public function test_add_upsell_writes_a_real_item_and_tag_to_pancake_and_logs_locally(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 40, 'name' => 'Gemma'],
            ]], 200),
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['success' => true, 'data' => [
                'id' => 9001, 'items' => [], 'tags' => [],
            ]], 200),
        ]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.upsell', $lead), [
            'variation_id' => 'var-1', 'product_id' => 'prod-1', 'name' => 'Haplunas Balm', 'retail_price' => 499.0, 'quantity' => 2,
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        Http::assertSent(function ($r) {
            if ($r->method() !== 'PUT') return false;
            $item = collect($r['items'])->first();
            return $item['variation_id'] === 'var-1' && $item['quantity'] === 2
                && (float) $item['variation_info']['retail_price'] === 499.0;
        });
        // The tag was auto-created (no pre-existing "UPSELL TSD - Haplunas
        // Balm" tag in the fake catalog above) before being merged in.
        Http::assertSent(fn ($r) => $r->method() === 'POST' && ($r['name'] ?? null) === 'UPSELL TSD - Haplunas Balm');

        $this->assertSame(1, LeadActivity::where('lead_id', $lead->id)->where('type', 'upsell_added')->count());
    }

    public function test_a_tsa_cannot_add_upsell_on_another_tsas_lead(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $lead   = $this->leadFor($gemma);
        $user   = User::create(['name' => 'Mariel User', 'email' => 'mariel@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $mariel->id]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.upsell', $lead), [
            'variation_id' => 'var-1', 'product_id' => 'prod-1', 'name' => 'Haplunas Balm', 'retail_price' => 499.0, 'quantity' => 1,
        ]);

        $response->assertForbidden();
    }

    public function test_add_upsell_returns_an_error_when_the_pancake_write_fails_but_still_logs_locally(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => []], 200),
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['message' => 'not found'], 404),
        ]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.upsell', $lead), [
            'variation_id' => 'var-1', 'product_id' => 'prod-1', 'name' => 'Haplunas Balm', 'retail_price' => 499.0, 'quantity' => 1,
        ]);

        $response->assertStatus(500)->assertJson(['success' => false]);
        $this->assertSame(1, LeadActivity::where('lead_id', $lead->id)->where('type', 'upsell_added')->count());
    }
}
