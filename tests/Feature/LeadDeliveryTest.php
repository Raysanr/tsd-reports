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
 * Explicit follow-up request (2026-08-25, from a screenshot of Pancake POS's
 * own Delivery panel): "make it editable like in the POS" — a real editable
 * form (recipient/address/province-district-commune picker/postcode/
 * estimated delivery date) backed by Pancake's real geo endpoints and the
 * same GET-then-PUT-whole-order write every other mutation on this page
 * already uses.
 */
class LeadDeliveryTest extends TestCase
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

    public function test_lists_real_provinces_for_the_delivery_picker(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        Http::fake([
            'pos.pages.fm/api/v1/geo/provinces*' => Http::response(['data' => [
                ['id' => '63_995', 'name' => 'South-cotabato'],
                ['id' => '63_753', 'name' => 'Rizal'],
            ]], 200),
        ]);

        $response = $this->actingAs($user)->getJson(route('calls.leads.delivery.provinces', $lead));

        $response->assertOk()->assertJson(['success' => true]);
        $response->assertJsonFragment(['name' => 'Rizal']);
    }

    public function test_lists_real_districts_for_a_province(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        Http::fake([
            'pos.pages.fm/api/v1/geo/districts*' => Http::response(['data' => [
                ['id' => '63_75326', 'name' => 'Angono', 'province_id' => '63_753'],
            ]], 200),
        ]);

        $response = $this->actingAs($user)->getJson(route('calls.leads.delivery.districts', $lead) . '?province_id=63_753');

        $response->assertOk()->assertJson(['success' => true]);
        $response->assertJsonFragment(['name' => 'Angono']);
        Http::assertSent(fn ($r) => $r->url() === 'https://pos.pages.fm/api/v1/geo/districts?province_id=63_753');
    }

    public function test_lists_real_communes_for_a_district(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        Http::fake([
            'pos.pages.fm/api/v1/geo/communes*' => Http::response(['data' => [
                ['id' => '63_7532601', 'name' => 'Poblacion-ibaba', 'postcode' => [1930]],
            ]], 200),
        ]);

        $response = $this->actingAs($user)->getJson(
            route('calls.leads.delivery.communes', $lead) . '?province_id=63_753&district_id=63_75326'
        );

        $response->assertOk()->assertJson(['success' => true]);
        $response->assertJsonFragment(['name' => 'Poblacion-ibaba']);
    }

    public function test_a_tsa_cannot_query_geo_lookups_on_another_tsas_lead(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $lead   = $this->leadFor($gemma);
        $user   = User::create(['name' => 'Mariel User', 'email' => 'mariel@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $mariel->id]);

        $this->actingAs($user)->getJson(route('calls.leads.delivery.provinces', $lead))->assertForbidden();
    }

    public function test_updating_delivery_writes_the_shipping_address_back_to_the_real_order(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::sequence()
                ->push(['success' => true, 'data' => [
                    'id' => 9001,
                    'shipping_address' => [
                        'full_name' => 'Old Name', 'phone_number' => '09000000000',
                        'address' => 'Old address', 'province_id' => null,
                        'render_type' => 'old',
                    ],
                ]], 200)
                ->push(['success' => true], 200),
        ]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.delivery.update', $lead), [
            'full_name'    => 'Victoriano Brugada',
            'phone_number' => '09163774053',
            'address'      => 'Landmark: Malapit sa Tower ng Globe Padulo',
            'province_id'   => '63_753',
            'province_name' => 'Rizal',
            'district_id'   => '63_75326',
            'district_name' => 'Angono',
            'commune_id'    => '63_7532601',
            'commune_name'  => 'Poblacion-ibaba',
            'post_code'     => '1930',
            'estimate_delivery_date' => '2026-08-28',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        Http::assertSent(function ($r) {
            if ($r->method() !== 'PUT') return false;
            $addr = $r['shipping_address'];
            return $addr['full_name'] === 'Victoriano Brugada'
                && $addr['phone_number'] === '09163774053'
                && $addr['province_id'] === '63_753'
                && $addr['district_name'] === 'Angono'
                && $addr['full_address'] === 'Landmark: Malapit sa Tower ng Globe Padulo, Poblacion-ibaba, Angono, Rizal'
                // render_type wasn't part of this app's form — it must survive
                // the merge untouched (array_merge onto the existing address).
                && $addr['render_type'] === 'old'
                && $r['estimate_delivery_date'] === '2026-08-28';
        });

        $this->assertNotNull(LeadActivity::where('lead_id', $lead->id)->where('type', 'delivery_updated')->first());
    }

    public function test_a_tsa_cannot_update_delivery_on_another_tsas_lead(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $lead   = $this->leadFor($gemma);
        $user   = User::create(['name' => 'Mariel User', 'email' => 'mariel@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $mariel->id]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.delivery.update', $lead), [
            'full_name' => 'X', 'phone_number' => '09000000000',
            'province_id' => '63_753', 'province_name' => 'Rizal',
            'district_id' => '63_75326', 'district_name' => 'Angono',
        ]);

        $response->assertForbidden();
    }

    public function test_update_delivery_is_rejected_when_the_lead_has_no_linked_order(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '', 'customer_name' => 'Juan', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        $response = $this->actingAs($user)->postJson(route('calls.leads.delivery.update', $lead), [
            'full_name' => 'X', 'phone_number' => '09000000000',
            'province_id' => '63_753', 'province_name' => 'Rizal',
            'district_id' => '63_75326', 'district_name' => 'Angono',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_update_delivery_requires_province_and_district(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.delivery.update', $lead), [
            'full_name' => 'X', 'phone_number' => '09000000000',
        ]);

        $response->assertStatus(422);
    }

    public function test_update_delivery_returns_an_error_when_the_pancake_write_fails(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['message' => 'not found'], 404),
        ]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.delivery.update', $lead), [
            'full_name' => 'X', 'phone_number' => '09000000000',
            'province_id' => '63_753', 'province_name' => 'Rizal',
            'district_id' => '63_75326', 'district_name' => 'Angono',
        ]);

        $response->assertStatus(500)->assertJson(['success' => false]);
    }
}
