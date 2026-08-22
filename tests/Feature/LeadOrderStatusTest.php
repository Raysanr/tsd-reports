<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Explicit request (2026-08-22, from a screenshot of Pancake POS's own Status
 * dropdown): change an order's status directly from Call Tracker's Leads tab
 * instead of leaving POS to do it. Same GET-then-PUT-whole-order write every
 * other PancakeOrderTagApi mutation uses (see LeadPancakeNotesTest for the
 * same pattern applied to note fields).
 */
class LeadOrderStatusTest extends TestCase
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

    public function test_updating_status_writes_it_back_to_the_real_order_and_the_local_cache(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);
        Order::create(['pancake_order_id' => '9001', 'team' => 'SH Naturals', 'status_code' => 0, 'synced_at' => now()]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['success' => true, 'data' => [
                'id' => 9001, 'status' => 0, 'note' => 'do not clobber me',
            ]], 200),
        ]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.status', $lead), ['status_code' => 1]);

        $response->assertOk()->assertJson(['success' => true, 'status_code' => 1, 'label' => 'Confirmed']);

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['status'] === 1 && $r['note'] === 'do not clobber me');
        $this->assertSame(1, Order::where('pancake_order_id', '9001')->value('status_code'));
    }

    public function test_a_tsa_cannot_update_status_on_another_tsas_lead(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $lead   = $this->leadFor($gemma);
        $user   = User::create(['name' => 'Mariel User', 'email' => 'mariel@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $mariel->id]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.status', $lead), ['status_code' => 1]);

        $response->assertForbidden();
    }

    public function test_status_update_is_rejected_when_the_lead_has_no_linked_order(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '', 'customer_name' => 'Juan', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        $response = $this->actingAs($user)->postJson(route('calls.leads.status', $lead), ['status_code' => 1]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    /** Only the exact set Pancake's own dropdown showed is assignable — see
     *  Order::STATUS_ASSIGNABLE's own doc comment for why "Received" (3), a
     *  real status_code, is deliberately not one of the pickable options. */
    public function test_a_status_code_outside_the_assignable_set_is_rejected(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        Http::fake();

        $response = $this->actingAs($user)->postJson(route('calls.leads.status', $lead), ['status_code' => 3]);

        $response->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_status_update_returns_an_error_and_leaves_the_local_cache_untouched_when_the_pancake_write_fails(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);
        Order::create(['pancake_order_id' => '9001', 'team' => 'SH Naturals', 'status_code' => 0, 'synced_at' => now()]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['message' => 'not found'], 404),
        ]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.status', $lead), ['status_code' => 1]);

        $response->assertStatus(500)->assertJson(['success' => false]);
        $this->assertSame(0, Order::where('pancake_order_id', '9001')->value('status_code'));
    }
}
