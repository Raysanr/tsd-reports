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

    /**
     * Explicit request, 2026-09-22: "i want in the leads page can search
     * multiple order id" — space-separated, each token matched
     * independently against the same 3 fields (customer_name/
     * phone_number/pancake_order_id) a single-value search already used,
     * so a paste of several order IDs at once now returns every one of
     * them, not zero rows (the whole multi-value string previously got
     * treated as one literal LIKE pattern that matched nothing real).
     */
    public function test_admin_can_search_leads_by_multiple_space_separated_order_ids(): void
    {
        $this->seedLeads();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.index', ['q' => '778899 112233']));

        $response->assertSee('Juan Dela Cruz');
        $response->assertSee('Maria Santos');
    }

    /** Comma-separated works the same way as space-separated — a TSA
     *  pasting a comma-delimited list from a spreadsheet shouldn't have
     *  to manually replace the commas with spaces first. */
    public function test_admin_can_search_leads_by_multiple_comma_separated_order_ids(): void
    {
        $this->seedLeads();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.index', ['q' => '778899,112233']));

        $response->assertSee('Juan Dela Cruz');
        $response->assertSee('Maria Santos');
    }

    /** A mixed list — some tokens are order IDs, one is a name — still
     *  matches each independently against all 3 fields, not just order ID
     *  specifically. */
    public function test_multi_value_search_mixes_names_and_order_ids_freely(): void
    {
        $this->seedLeads();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.index', ['q' => '778899 Maria']));

        $response->assertSee('Juan Dela Cruz');
        $response->assertSee('Maria Santos');
    }

    /** A multi-value search that matches neither seeded lead returns
     *  none — confirms the OR-of-tokens isn't accidentally matching
     *  everything. */
    public function test_multi_value_search_with_no_matches_returns_nothing(): void
    {
        $this->seedLeads();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.index', ['q' => '000000 999999']));

        $response->assertDontSee('Juan Dela Cruz');
        $response->assertDontSee('Maria Santos');
    }

    /** Extra whitespace/a trailing comma between tokens must not blow up
     *  into an empty token that accidentally matches everything (an empty
     *  LIKE '%%' would). */
    public function test_multi_value_search_tolerates_extra_whitespace_and_trailing_separators(): void
    {
        $this->seedLeads();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.index', ['q' => '  778899,  , 112233  ']));

        $response->assertSee('Juan Dela Cruz');
        $response->assertSee('Maria Santos');
    }
}
