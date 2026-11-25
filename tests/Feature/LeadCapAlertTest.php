<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A TSA who reached today's daily lead limit gets a "!" next to Leads
 *  Setup in the Call Tracker sidebar and a warning on the page
 *  (2026-11-25); raising the limit clears it straight away. */
class LeadCapAlertTest extends TestCase
{
    use RefreshDatabase;

    private function gemmaAtCap(): TsaShift
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['active' => true, 'daily_lead_cap' => 2]);
        $product = Product::where('display_name', 'SINUXYL')->first();
        foreach (['cap-1', 'cap-2'] as $id) {
            Lead::create(['pancake_order_id' => $id, 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now(), 'pancake_created_at' => now()]);
        }

        return $gemma;
    }

    public function test_sidebar_and_page_warn_when_a_tsa_is_at_the_limit(): void
    {
        $gemma = $this->gemmaAtCap();

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('calls.round-robin-setup'));

        $response->assertOk();
        $response->assertSee("{$gemma->display_name} has reached today's lead limit", false);
        $response->assertSee('Limit reached');
        $response->assertSee('New leads skip them until the limit is raised.');
    }

    public function test_no_warning_when_nobody_is_at_the_limit(): void
    {
        TsaShift::query()->update(['daily_lead_cap' => null]);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('calls.round-robin-setup'));

        $response->assertOk();
        $response->assertDontSee("reached today's lead limit", false);
        $response->assertDontSee('New leads skip them');
    }

    public function test_raising_the_limit_clears_the_sidebar_warning(): void
    {
        $gemma = $this->gemmaAtCap();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('calls.round-robin-setup'))->assertSee("reached today's lead limit", false);

        $this->actingAs($admin)->postJson(route('calls.round-robin-setup.update', $gemma), ['daily_lead_cap' => 50])->assertOk();

        $this->actingAs($admin)->get(route('calls.round-robin-setup'))->assertDontSee("reached today's lead limit", false);
    }
}
