<?php

namespace Tests\Feature;

use App\Models\Lead;
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
}
