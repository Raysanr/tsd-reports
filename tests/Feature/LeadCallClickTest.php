<?php

namespace Tests\Feature;

use App\Models\CallEvent;
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

    /**
     * Explicit request (2026-08-22): the Leads table had no visible way to
     * tell whether a TSA had actually dialed a customer yet, only whether an
     * outcome had been logged (called_at, set once a disposition is chosen —
     * a much later step). dialed_at is a separate, lighter-weight signal set
     * on this exact same click, before any disposition exists.
     */
    public function test_clicking_to_call_stamps_the_leads_own_dialed_at(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->assertNull($lead->fresh()->dialed_at);

        $this->actingAs($user)->postJson(route('calls.leads.call-click', $lead))->assertOk();

        $this->assertNotNull($lead->fresh()->dialed_at);
        // Not the same thing as an actual recorded outcome — that's a
        // separate, later step (LeadController::updateDisposition()).
        $this->assertNull($lead->fresh()->called_at);
    }

    /** Redialing (a second click) must not error and should just move the
     *  timestamp forward — nothing here assumes a click only ever happens once. */
    public function test_clicking_to_call_again_updates_dialed_at_to_the_latest_click(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);
        $lead->update(['dialed_at' => now()->subHour()]);

        $this->actingAs($user)->postJson(route('calls.leads.call-click', $lead))->assertOk();

        $this->assertTrue($lead->fresh()->dialed_at->isAfter(now()->subMinute()));
    }

    /** Same "nothing meaningful to log" guard as the unassigned-lead
     *  LeadActivity case above — dialed_at must not get stamped either. */
    public function test_a_call_click_on_an_unassigned_lead_does_not_stamp_dialed_at(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '9003', 'customer_name' => 'Ana', 'phone_number' => '09171234567', 'product_id' => $product->id, 'status' => 'unassigned']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('calls.leads.call-click', $lead))->assertOk();

        $this->assertNull($lead->fresh()->dialed_at);
    }

    /**
     * Explicit follow-up request (2026-09-03: "when they call in the leads
     * it is automatically be has data in the call log ... marisol tried to
     * call one lead but it did not display to the call log") — Call Log was
     * previously 100% dependent on each TSA's own phone's MacroDroid
     * automation reporting back; this guarantees a row always exists even
     * when that automation never fires. duration_seconds is always null —
     * this click has no way to know how long the call actually lasted.
     */
    public function test_clicking_to_call_creates_a_placeholder_call_event(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->postJson(route('calls.leads.call-click', $lead))->assertOk();

        $event = CallEvent::where('lead_id', $lead->id)->first();
        $this->assertNotNull($event);
        $this->assertSame($gemma->id, $event->tsa_id);
        $this->assertSame('09171234567', $event->phone_number);
        $this->assertSame('outgoing', $event->direction);
        $this->assertNull($event->duration_seconds);
    }

    /** Same "nothing meaningful to log" guard as the unassigned-lead cases
     *  above — no TSA to attribute a call event to. */
    public function test_a_call_click_on_an_unassigned_lead_does_not_create_a_call_event(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '9006', 'customer_name' => 'Ana', 'phone_number' => '09171234567', 'product_id' => $product->id, 'status' => 'unassigned']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('calls.leads.call-click', $lead))->assertOk();

        $this->assertSame(0, CallEvent::where('lead_id', $lead->id)->count());
    }

    public function test_the_leads_table_shows_a_dialed_indicator_once_stamped(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $dialed    = $this->leadFor($gemma, '9004');
        $notDialed = $this->leadFor($gemma, '9005');
        $dialed->update(['dialed_at' => now()]);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('Called ' . $dialed->fresh()->dialed_at->diffForHumans(), false);
    }
}
