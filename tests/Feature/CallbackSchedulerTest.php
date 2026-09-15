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

    public function test_logging_call_back_without_a_time_defaults_the_callback_to_one_day_out(): void
    {
        [$lead, $user] = $this->leadAndUser();

        $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Call Back']);

        $lead->refresh();
        $this->assertNotNull($lead->callback_at);
        $this->assertTrue($lead->callback_at->betweenIncluded(now()->addHours(23), now()->addHours(25)));
    }

    public function test_logging_call_back_with_an_explicit_time_uses_it(): void
    {
        [$lead, $user] = $this->leadAndUser();
        $when = now()->addDays(3)->startOfMinute();

        $this->actingAs($user)->post(route('calls.leads.disposition', $lead), [
            'disposition' => 'Call Back',
            'callback_at' => $when->format('Y-m-d\TH:i'),
        ]);

        $lead->refresh();
        $this->assertSame($when->format('Y-m-d H:i'), $lead->callback_at->format('Y-m-d H:i'));
    }

    public function test_a_non_call_back_disposition_never_sets_a_callback(): void
    {
        [$lead, $user] = $this->leadAndUser();

        $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed']);

        $this->assertNull($lead->refresh()->callback_at);
    }

    /** Explicit request (2026-08-12): Unattended/Not Answering mean the same
     *  thing as Call Back for follow-up purposes — nobody actually talked to
     *  the customer — so they now also set a due callback, same default as
     *  Call Back itself. */
    public function test_logging_unattended_also_schedules_a_callback(): void
    {
        [$lead, $user] = $this->leadAndUser();

        $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Unattended']);

        $lead->refresh();
        $this->assertNotNull($lead->callback_at);
        $this->assertTrue($lead->callback_at->betweenIncluded(now()->addHours(23), now()->addHours(25)));
    }

    public function test_logging_not_answering_also_schedules_a_callback(): void
    {
        [$lead, $user] = $this->leadAndUser();

        $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Not Answering']);

        $lead->refresh();
        $this->assertNotNull($lead->callback_at);
        $this->assertTrue($lead->callback_at->betweenIncluded(now()->addHours(23), now()->addHours(25)));
    }

    /** Same case-insensitive-substring-over-the-whole-joined-string behavior
     *  Call Back already had — Not Answering picked alongside an unrelated
     *  tag still schedules a callback. */
    public function test_not_answering_combined_with_another_tag_still_schedules_a_callback(): void
    {
        [$lead, $user] = $this->leadAndUser();

        $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Not Answering, Duplicate']);

        $this->assertNotNull($lead->refresh()->callback_at);
    }

    public function test_unattended_and_not_answering_leads_show_on_the_callbacks_view_when_due(): void
    {
        [$lead, $user] = $this->leadAndUser();
        $lead->update(['status' => 'called', 'disposition' => 'Unattended', 'callback_at' => now()->subMinute()]);

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
     * actually resolves it (a disposition that ISN'T Unattended/Not
     * Answering/Call Back again), ownership follows whoever did the work.
     */
    public function test_a_different_tsa_resolving_a_due_callback_becomes_its_new_owner(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $julie  = TsaShift::where('tsa_key', 'Julie')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => 'cb3', 'customer_name' => 'Shared Callback', 'product_id' => $product->id,
            'tsa_id' => $gemma->id, 'status' => 'called', 'disposition' => 'Unattended', 'callback_at' => now()->subMinute(),
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

    /** Logging ANOTHER unresolved attempt (still Unattended/Not Answering/
     *  Call Back) is not a real pickup — the lead stays with its original
     *  owner and up for grabs, rather than silently reassigning on every
     *  failed re-attempt by a different TSA. */
    public function test_a_different_tsa_logging_another_unattended_does_not_take_ownership(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => 'cb4', 'customer_name' => 'Still Unresolved', 'product_id' => $product->id,
            'tsa_id' => $gemma->id, 'status' => 'called', 'disposition' => 'Unattended', 'callback_at' => now()->subMinute(),
        ]);
        $julieUser = User::factory()->create(['role' => 'tsa', 'tsa_id' => $julie->id]);

        $this->actingAs($julieUser)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Not Answering']);

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
            'tsa_id' => $gemma->id, 'status' => 'called', 'disposition' => 'Unattended', 'callback_at' => now()->subMinute(),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed Via Call']);

        $this->assertSame($gemma->id, $lead->fresh()->tsa_id);
        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->where('type', 'transferred')->count());
    }
}
