<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
