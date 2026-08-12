<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift, routes -> calls.*. */
class LeadSearchTest extends TestCase
{
    use RefreshDatabase;

    private function seedLeads(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        Lead::create(['pancake_order_id' => '778899', 'customer_name' => 'Juan Dela Cruz', 'phone_number' => '09171234567', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        Lead::create(['pancake_order_id' => '112233', 'customer_name' => 'Maria Santos', 'phone_number' => '09189876543', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
    }

    public function test_admin_can_search_leads_by_customer_name(): void
    {
        $this->seedLeads();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.index', ['q' => 'Juan']));

        $response->assertSee('Juan Dela Cruz');
        $response->assertDontSee('Maria Santos');
    }

    public function test_admin_can_search_leads_by_order_id(): void
    {
        $this->seedLeads();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.index', ['q' => '778899']));

        $response->assertSee('Juan Dela Cruz');
        $response->assertDontSee('Maria Santos');
    }

    public function test_admin_can_search_leads_by_phone_number(): void
    {
        $this->seedLeads();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.index', ['q' => '09189876543']));

        $response->assertSee('Maria Santos');
        $response->assertDontSee('Juan Dela Cruz');
    }
}
