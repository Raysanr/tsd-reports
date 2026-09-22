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
class CallbackSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private function leadAndUser(): array
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => 'cb1', 'customer_name' => 'Callback Test', 'product_id' => $product->id,
            'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now(),
        ]);
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        return [$lead, $user];
    }

    /**
     * Reversed 2026-09-22 (explicit request: "make the call backs should
     * be only will have a call back tag") — "Call Back" is now the ONLY
     * disposition that schedules a callback; Unattended/Not Answering/
     * Invalid Number moved to their own dedicated page (Unanswered Calls)
     * instead and no longer touch callback_at at all (see
     * LeadController::CALLBACK_TRIGGER_KEYWORDS' own doc comment for the
     * full history — this constant was Unattended/Not Answering ONLY from
     * 2026-09-17 until this reversal). An explicit callback_at submitted
     * alongside "Call Back" is honored (this is the datetime input
     * calls.js reveals specifically for this tag, per setSelectedTags()).
     */
    public function test_logging_call_back_schedules_a_callback_at_the_chosen_time(): void
    {
        [$lead, $user] = $this->leadAndUser();
        $when = now()->addDays(3)->startOfMinute();

        $this->actingAs($user)->post(route('calls.leads.disposition', $lead), [
            'disposition' => 'Call Back',
            'callback_at' => $when->format('Y-m-d\TH:i'),
        ]);

        $this->assertTrue($when->equalTo($lead->refresh()->callback_at));
    }

    /**
     * The picker itself changed from datetime-local to a bare time input
     * (explicit request, 2026-09-22: "there's no date should be only
     * time" — a callback is always today, so picking a date too was one
     * extra, pointless step) — this is what the real <input type="time">
     * now actually submits: a plain "HH:MM" string, no date component at
     * all. Confirms Carbon::parse() resolves that to TODAY's date at that
     * time, not some other default, with no frontend change needed to
     * updateDisposition()'s own 'nullable','date' validation.
     */
    public function test_logging_call_back_with_a_time_only_value_resolves_to_today(): void
    {
        [$lead, $user] = $this->leadAndUser();

        $this->actingAs($user)->post(route('calls.leads.disposition', $lead), [
            'disposition' => 'Call Back',
            'callback_at' => '14:30',
        ]);

        $lead->refresh();
        $this->assertNotNull($lead->callback_at);
        $this->assertTrue($lead->callback_at->isToday());
        $this->assertSame('14:30', $lead->callback_at->format('H:i'));
    }

    /** No explicit callback_at picked — defaults to +1 day, same
     *  fallback updateDisposition() already used before this reversal. */
    public function test_logging_call_back_with_no_chosen_time_defaults_to_plus_one_day(): void
    {
        [$lead, $user] = $this->leadAndUser();

        $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Call Back']);

        $lead->refresh();
        $this->assertNotNull($lead->callback_at);
        $this->assertTrue($lead->callback_at->betweenIncluded(now()->addHours(23), now()->addHours(25)));
    }

    public function test_a_non_call_back_disposition_never_sets_a_callback(): void
    {
        [$lead, $user] = $this->leadAndUser();

        $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed']);

        $this->assertNull($lead->refresh()->callback_at);
    }

    /** Unattended/Not Answering no longer schedule a callback (reversed
     *  2026-09-22) — they now belong to the Unanswered Calls page instead,
     *  which matches on disposition directly and doesn't depend on
     *  callback_at at all (see LeadController::index()'s own 'unanswered'
     *  branch). */
    public function test_logging_unattended_no_longer_schedules_a_callback(): void
    {
        [$lead, $user] = $this->leadAndUser();

        $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Unattended']);

        $this->assertNull($lead->refresh()->callback_at);
    }

    public function test_logging_not_answering_no_longer_schedules_a_callback(): void
    {
        [$lead, $user] = $this->leadAndUser();

        $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Not Answering']);

        $this->assertNull($lead->refresh()->callback_at);
    }

    /** Same case-insensitive-substring-over-the-whole-joined-string
     *  behavior CALLBACK_TRIGGER_KEYWORDS always had — "Call Back" picked
     *  alongside an unrelated tag still schedules a callback. */
    public function test_call_back_combined_with_another_tag_still_schedules_a_callback(): void
    {
        [$lead, $user] = $this->leadAndUser();

        $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Call Back, Duplicate']);

        $this->assertNotNull($lead->refresh()->callback_at);
    }

    public function test_a_call_back_lead_shows_on_the_callbacks_view_when_due(): void
    {
        [$lead, $user] = $this->leadAndUser();
        $lead->update(['status' => 'called', 'disposition' => 'Call Back', 'callback_at' => now()->subMinute()]);

        $response = $this->actingAs($user)->get(route('calls.leads.index', ['view' => 'callbacks']));

        $response->assertSee('Callback Test');
    }

    public function test_a_due_callback_shows_on_the_callbacks_view_and_a_future_one_does_not(): void
    {
        [$lead, $user] = $this->leadAndUser();
        $lead->update(['status' => 'called', 'disposition' => 'Call Back', 'callback_at' => now()->subMinute()]);

        $futureLead = Lead::create([
            'pancake_order_id' => 'cb2', 'customer_name' => 'Future Callback', 'product_id' => $lead->product_id,
            'tsa_id' => $lead->tsa_id, 'status' => 'called', 'disposition' => 'Call Back', 'callback_at' => now()->addDays(2),
        ]);

        $response = $this->actingAs($user)->get(route('calls.leads.index', ['view' => 'callbacks']));

        $response->assertSee('Callback Test');
        $response->assertDontSee('Future Callback');
    }

    /**
     * Explicit request, 2026-09-16: "gemma leads has unattended but julie
     * call that unattended and removed the unattended and the disposition
     * now is upsell tsd like that or confirmed via call or any answered
     * like that, i want to make it like it will automatically julie's
     * leads that" — Callbacks is the one queue where canAccess() already
     * lets ANY TSA act on a due lead she doesn't own; when a different TSA
     * actually resolves it (a disposition that ISN'T the trigger keyword
     * again), ownership follows whoever did the work. Uses "Call Back" as
     * the original trigger disposition (updated 2026-09-22 — the original
     * 2026-09-16 incident was actually "Unattended", but
     * CALLBACK_TRIGGER_KEYWORDS no longer matches that; the reassignment
     * rule itself is unchanged).
     */
    public function test_a_different_tsa_resolving_a_due_callback_becomes_its_new_owner(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $julie  = TsaShift::where('tsa_key', 'Julie')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => 'cb3', 'customer_name' => 'Shared Callback', 'product_id' => $product->id,
            'tsa_id' => $gemma->id, 'status' => 'called', 'disposition' => 'Call Back', 'callback_at' => now()->subMinute(),
        ]);
        $julieUser = User::factory()->create(['role' => 'tsa', 'tsa_id' => $julie->id]);

        $this->actingAs($julieUser)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed Via Call']);

        $lead->refresh();
        $this->assertSame($julie->id, $lead->tsa_id);
        $activity = LeadActivity::where('lead_id', $lead->id)->where('type', 'transferred')->first();
        $this->assertNotNull($activity);
        $this->assertStringContainsString('Gemma', $activity->description);
        $this->assertStringContainsString($julieUser->name, $activity->description);
    }

    /** Logging ANOTHER unresolved attempt (still the trigger keyword) is
     *  not a real pickup — the lead stays with its original owner and up
     *  for grabs, rather than silently reassigning on every failed
     *  re-attempt by a different TSA. */
    public function test_a_different_tsa_logging_another_call_back_does_not_take_ownership(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => 'cb4', 'customer_name' => 'Still Unresolved', 'product_id' => $product->id,
            'tsa_id' => $gemma->id, 'status' => 'called', 'disposition' => 'Call Back', 'callback_at' => now()->subMinute(),
        ]);
        $julieUser = User::factory()->create(['role' => 'tsa', 'tsa_id' => $julie->id]);

        $this->actingAs($julieUser)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Call Back']);

        $this->assertSame($gemma->id, $lead->fresh()->tsa_id);
        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->where('type', 'transferred')->count());
    }

    /** An admin logging on a TSA's behalf is correcting the record, not
     *  personally picking up the call — ownership must never move just
     *  because an admin was the one who submitted the form. */
    public function test_an_admin_logging_on_a_tsas_behalf_does_not_reassign_ownership(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => 'cb5', 'customer_name' => 'Admin Corrected', 'product_id' => $product->id,
            'tsa_id' => $gemma->id, 'status' => 'called', 'disposition' => 'Call Back', 'callback_at' => now()->subMinute(),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed Via Call']);

        $this->assertSame($gemma->id, $lead->fresh()->tsa_id);
        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->where('type', 'transferred')->count());
    }

    /**
     * Explicit request, 2026-09-22: "i want to make it like this in my
     * dial modal" — the Calling modal's own new Callback quick-pick
     * section (resources/views/calls/partials/modals.blade.php,
     * scheduleCallbackFromModal() in calls.js) submits here via a JSON
     * fetch instead of a plain form POST, so this method needs a JSON
     * response branch alongside the existing back()-redirect one the
     * Leads table's own disposition-form still uses unmodified.
     */
    public function test_updating_disposition_via_json_returns_a_json_response_not_a_redirect(): void
    {
        [$lead, $user] = $this->leadAndUser();
        $when = now()->addMinutes(30)->startOfMinute();

        $response = $this->actingAs($user)->postJson(route('calls.leads.disposition', $lead), [
            'disposition' => 'Call Back',
            'callback_at' => $when->toIso8601String(),
            'notes'       => 'Customer requested',
        ]);

        $response->assertOk()->assertJson([
            'success'     => true,
            'disposition' => 'Call Back',
        ]);
        $lead->refresh();
        $this->assertTrue($when->equalTo($lead->callback_at));
        $this->assertSame('Customer requested', $lead->notes);
        $this->assertSame('called', $lead->status);
    }

    /** The pre-existing plain-form path (Leads table's own
     *  disposition-form) must still redirect, completely unaffected by
     *  the new JSON branch above. */
    public function test_updating_disposition_via_plain_form_still_redirects(): void
    {
        [$lead, $user] = $this->leadAndUser();

        $response = $this->actingAs($user)->post(route('calls.leads.disposition', $lead), [
            'disposition' => 'Confirmed',
        ]);

        $response->assertRedirect();
    }
}
