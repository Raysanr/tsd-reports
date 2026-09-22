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

    /**
     * Real bug, explicit report 2026-09-19: Hannah, Marisol, and Marsha all
     * showed LOGOUT on TSA Logs followed by later CALL/CALLING rows for the
     * same TSA. Root cause: this endpoint unconditionally flipped
     * $lead->tsa to Calling with no logout check at all — a genuinely
     * solo TSA who logged out and is done for the day must stay logged out
     * even if a stale browser tab (or an admin) still clicks to call one of
     * their leads.
     */
    public function test_clicking_to_call_a_solo_tsas_lead_after_they_logged_out_does_not_revive_them(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);
        $lead  = $this->leadFor($gemma);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('calls.leads.call-click', $lead))->assertOk();

        $this->assertSame(TsaShift::STATUS_LOGOUT, $gemma->fresh()->status);
    }

    /**
     * Same root cause as above, but for two TSAs sharing one physical phone
     * (TsaShift::pairWith()) — the same class of bug
     * TsaShift::resolveActiveOfPair() already fixed for the MacroDroid
     * call-ended webhook (2026-09-18) never got applied to this
     * click-to-call path. A lead assigned to the now-logged-out TSA can
     * still genuinely be the one their still-working partner is dialing
     * from the shared phone, so the partner — not the logged-out TSA —
     * is who should flip to Calling.
     */
    public function test_clicking_to_call_a_logged_out_paired_tsas_lead_flips_the_partner_instead(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $gemma->pairWith($mariel);
        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);
        $lead  = $this->leadFor($gemma);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('calls.leads.call-click', $lead))->assertOk();

        $this->assertSame(TsaShift::STATUS_LOGOUT, $gemma->fresh()->status);
        $this->assertSame(TsaShift::STATUS_CALLING, $mariel->fresh()->status);
    }

    /** If BOTH sides of a pair are logged out, there's genuinely nobody on
     *  the phone to flip — neither side should be revived into Calling. */
    public function test_clicking_to_call_when_both_paired_tsas_are_logged_out_revives_neither(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $gemma->pairWith($mariel);
        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);
        $mariel->applyStatusChange(TsaShift::STATUS_LOGOUT);
        $lead  = $this->leadFor($gemma);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('calls.leads.call-click', $lead))->assertOk();

        $this->assertSame(TsaShift::STATUS_LOGOUT, $gemma->fresh()->status);
        $this->assertSame(TsaShift::STATUS_LOGOUT, $mariel->fresh()->status);
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

    /**
     * "Recently Called" panel (explicit follow-up, 2026-09-18: "they
     * still will search to the main leads still" — after an earlier fix
     * auto-opened a lead's detail modal right after a call, closing THAT
     * modal too still lost the lead, back to the original "search the
     * main Leads list" complaint). Sourced from LeadActivity (user_id =
     * the viewer), not Lead.dialed_at — see recentlyCalled()'s own doc
     * comment for why.
     */
    public function test_recently_called_lists_the_viewers_own_dials_newest_first(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $first  = $this->leadFor($gemma, '9010');
        $second = $this->leadFor($gemma, '9011');
        $user   = User::create(['name' => 'Gemma User', 'email' => 'gemma10@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->postJson(route('calls.leads.call-click', $first))->assertOk();
        $this->actingAs($user)->postJson(route('calls.leads.call-click', $second))->assertOk();

        $response = $this->actingAs($user)->getJson(route('calls.leads.recently-called'));

        $response->assertOk();
        $ids = collect($response->json('leads'))->pluck('id')->all();
        $this->assertSame([$second->id, $first->id], $ids);
    }

    /** A redial of the SAME lead must only ever appear once, at its most
     *  recent position — not once per click. */
    public function test_recently_called_deduplicates_a_lead_dialed_more_than_once(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma, '9012');
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma11@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->postJson(route('calls.leads.call-click', $lead))->assertOk();
        $this->actingAs($user)->postJson(route('calls.leads.call-click', $lead))->assertOk();

        $response = $this->actingAs($user)->getJson(route('calls.leads.recently-called'));

        $ids = collect($response->json('leads'))->pluck('id')->all();
        $this->assertSame([$lead->id], $ids);
    }

    /** Scoped to the VIEWER's own dials — an admin covering a different
     *  lead must never see it show up on another user's own list. */
    public function test_recently_called_is_scoped_to_the_viewer_not_the_leads_own_tsa(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $lead   = $this->leadFor($gemma, '9013');
        $admin  = User::factory()->create(['role' => 'admin']);
        $gemmaUser = User::create(['name' => 'Gemma User', 'email' => 'gemma12@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        // Admin (not Gemma) is the one who actually clicked to call.
        $this->actingAs($admin)->postJson(route('calls.leads.call-click', $lead))->assertOk();

        $adminResponse = $this->actingAs($admin)->getJson(route('calls.leads.recently-called'));
        $this->assertSame([$lead->id], collect($adminResponse->json('leads'))->pluck('id')->all());

        // Gemma herself never clicked anything — her own list is empty,
        // even though the lead is assigned to her.
        $gemmaResponse = $this->actingAs($gemmaUser)->getJson(route('calls.leads.recently-called'));
        $this->assertSame([], collect($gemmaResponse->json('leads'))->pluck('id')->all());
    }

    /** Disposition removed from this panel entirely (explicit request,
     *  2026-09-18: "remove the disposition") — it's a quick way back to a
     *  recently-dialed lead, not a second place to track outcome state. */
    public function test_recently_called_does_not_expose_a_disposition_field(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma, '9014');
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma13@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->postJson(route('calls.leads.call-click', $lead))->assertOk();
        $lead->update(['disposition' => 'Confirmed']);

        $response = $this->actingAs($user)->getJson(route('calls.leads.recently-called'));

        $this->assertArrayNotHasKey('disposition', $response->json('leads.0'));
    }

    /**
     * Real gap, explicit request 2026-09-22: "i want to make it like even
     * shared queue it will not be same calling or wrap up like that" —
     * confirmed live: Gemma owns a lead with a due callback_at (so it sits
     * in the shared Callbacks queue, visible/dialable by anyone per
     * canAccess()'s own due-callback branch), Gemma steps away entirely,
     * and Mariel (who never owned it) opens Callbacks and dials it — Gemma
     * (who touched nothing) used to flip to Calling on Monitor/TSA Logs
     * while Mariel (the one actually on the phone) showed no change at
     * all. Now flips the REAL clicker (Mariel) instead of the lead's
     * original owner (Gemma).
     */
    public function test_a_shared_queue_pickup_flips_the_clickers_own_status_not_the_owners(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $gemma->applyStatusChange(TsaShift::STATUS_DNA_HUDDLE);
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => '9020', 'customer_name' => 'Juan', 'phone_number' => '09171234567',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
            'callback_at' => now()->subMinute(),
        ]);
        $marielUser = User::create(['name' => 'Mariel User', 'email' => 'mariel-pickup@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $mariel->id]);

        $this->actingAs($marielUser)->postJson(route('calls.leads.call-click', $lead))->assertOk();

        $this->assertSame(TsaShift::STATUS_DNA_HUDDLE, $gemma->fresh()->status);
        $this->assertSame(TsaShift::STATUS_CALLING, $mariel->fresh()->status);
    }

    /** The activity log's own description must also credit the real
     *  clicker, not the lead's owner, for the same shared-queue pickup —
     *  otherwise TSA Logs would show "Gemma clicked to call Juan" even
     *  though Gemma never touched anything. */
    public function test_a_shared_queue_pickups_activity_log_credits_the_real_clicker(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => '9021', 'customer_name' => 'Juan', 'phone_number' => '09171234567',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
            'callback_at' => now()->subMinute(),
        ]);
        $marielUser = User::create(['name' => 'Mariel User', 'email' => 'mariel-log@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $mariel->id]);

        $this->actingAs($marielUser)->postJson(route('calls.leads.call-click', $lead))->assertOk();

        $activity = LeadActivity::where('lead_id', $lead->id)->where('type', 'call_clicked')->first();
        $this->assertStringContainsString($mariel->display_name, $activity->description);
        $this->assertStringNotContainsString($gemma->display_name, $activity->description);
    }

    /** A NORMAL (non-shared) lead is unaffected by this fix — the owner
     *  is the one clicking, so flipping "the clicker" and "the owner"
     *  land on the exact same person either way, same as before. */
    public function test_a_normal_non_shared_lead_still_flips_its_own_owner(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma, '9022');
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma-normal@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->postJson(route('calls.leads.call-click', $lead))->assertOk();

        $this->assertSame(TsaShift::STATUS_CALLING, $gemma->fresh()->status);
    }

    /**
     * endCall() needs the identical fix (same call site's own doc
     * comment) — must flip the SAME TSA logCallClick() actually flipped
     * to Calling for this pickup, or a shared-queue "End Call" either
     * wrongly wraps up the lead's original owner (who was never on this
     * call) or silently no-ops.
     */
    public function test_ending_a_shared_queue_call_wraps_up_the_real_caller_not_the_owner(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => '9023', 'customer_name' => 'Juan', 'phone_number' => '09171234567',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
            'callback_at' => now()->subMinute(),
        ]);
        $marielUser = User::create(['name' => 'Mariel User', 'email' => 'mariel-end@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $mariel->id]);

        $this->actingAs($marielUser)->postJson(route('calls.leads.call-click', $lead))->assertOk();
        $this->assertSame(TsaShift::STATUS_CALLING, $mariel->fresh()->status);

        $this->actingAs($marielUser)->postJson(route('calls.leads.end-call', $lead))->assertOk();

        $this->assertSame(TsaShift::STATUS_WRAP_UP, $mariel->fresh()->status);
        // Gemma (the owner, who never touched anything) is untouched
        // throughout — still whatever she was before this pickup.
        $this->assertNotSame(TsaShift::STATUS_CALLING, $gemma->fresh()->status);
        $this->assertNotSame(TsaShift::STATUS_WRAP_UP, $gemma->fresh()->status);
    }
}
