<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift, routes -> calls.*.
 * Explicit request (2026-08-10): clicking a customer's phone number in My
 * Leads/the lead detail page should show up on TSA Logs, not just real
 * status changes — see LeadController::logCallClick()'s own doc comment for
 * why this is a LeadActivity, not a TsaStatusLog row.
 */
class LeadCallClickTest extends TestCase
{
    use RefreshDatabase;

    private function leadFor(TsaShift $tsa, string $orderId = '9001'): Lead
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        return Lead::create([
            'pancake_order_id' => $orderId, 'customer_name' => 'Juan', 'phone_number' => '09171234567',
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'status' => 'assigned',
        ]);
    }

    public function test_clicking_to_call_logs_a_lead_activity(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.call-click', $lead));

        $response->assertOk()->assertJson(['success' => true]);
        $activity = LeadActivity::where('lead_id', $lead->id)->where('type', 'call_clicked')->first();
        $this->assertNotNull($activity);
        $this->assertStringContainsString('Juan', $activity->description);
    }

    public function test_a_tsa_cannot_log_a_call_click_on_another_tsas_lead(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $lead   = $this->leadFor($gemma);
        $user   = User::create(['name' => 'Mariel User', 'email' => 'mariel@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $mariel->id]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.call-click', $lead));

        $response->assertForbidden();
        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->count());
    }

    public function test_an_admin_can_log_a_call_click_on_any_leads_behalf(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('calls.leads.call-click', $lead));

        $response->assertOk();
        $this->assertSame(1, LeadActivity::where('lead_id', $lead->id)->where('type', 'call_clicked')->count());
    }

    /** An unassigned lead has no TSA to attribute the click to — nothing
     *  meaningful to log, so it's silently skipped rather than erroring. */
    public function test_a_call_click_on_an_unassigned_lead_is_not_logged(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '9002', 'customer_name' => 'Ana', 'phone_number' => '09171234567', 'product_id' => $product->id, 'status' => 'unassigned']);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('calls.leads.call-click', $lead));

        $response->assertOk();
        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->count());
    }
}
