<?php

namespace Tests\Feature;

use App\Models\CallEvent;
use App\Models\Lead;
use App\Models\Product;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift.
 * The webhook a TSA's own phone (via MacroDroid) hits after every real
 * call — no browser session on that side, so this is authenticated by the
 * per-TSA api_token alone (see TsaShift::generateApiToken(), TsaManagementController::
 * regenerateApiToken()). This is the actual mechanism behind "connect their
 * phone number to the system": no telco exposes a way to touch a personal
 * SIM's balance, so every real call gets logged here instead, for a
 * load-reimbursement report (CallLogController) to be built from.
 */
class CallEventControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_token_logs_a_call_event(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['api_token' => 'secret-token']);

        $response = $this->postJson('/api/call-events', [
            'api_token'        => 'secret-token',
            'phone_number'     => '09171234567',
            'direction'        => 'outgoing',
            'duration_seconds' => 125,
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('call_events', [
            'tsa_id'           => $gemma->id,
            'phone_number'     => '09171234567',
            'direction'        => 'outgoing',
            'duration_seconds' => 125,
        ]);
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        $response = $this->postJson('/api/call-events', [
            'api_token'    => 'not-a-real-token',
            'phone_number' => '09171234567',
            'direction'    => 'outgoing',
        ]);

        $response->assertUnauthorized();
        $this->assertSame(0, CallEvent::count());
    }

    public function test_a_capitalized_direction_from_macrodroids_own_call_type_variable_is_accepted(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['api_token' => 'secret-token']);

        $response = $this->postJson('/api/call-events', [
            'api_token'    => 'secret-token',
            'phone_number' => '09171234567',
            'direction'    => 'Outgoing', // MacroDroid's [call_type] reports it capitalized
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('call_events', ['direction' => 'outgoing']);
    }

    public function test_an_invalid_direction_is_rejected(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['api_token' => 'secret-token']);

        $response = $this->postJson('/api/call-events', [
            'api_token'    => 'secret-token',
            'phone_number' => '09171234567',
            'direction'    => 'sideways',
        ]);

        $response->assertUnprocessable();
    }

    public function test_the_call_is_matched_to_that_tsas_own_lead_by_phone_number(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['api_token' => 'secret-token']);
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => '1', 'customer_name' => 'Juan', 'phone_number' => '+63 917 123 4567',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
        ]);

        $response = $this->postJson('/api/call-events', [
            'api_token'    => 'secret-token',
            'phone_number' => '09171234567', // same subscriber number, different formatting
            'direction'    => 'outgoing',
        ]);

        $response->assertOk();
        $response->assertJson(['matched_lead_id' => $lead->id]);
    }

    public function test_the_call_is_not_matched_to_a_different_tsas_lead_with_the_same_number(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $gemma->update(['api_token' => 'secret-token']);
        $product = Product::where('display_name', 'SINUXYL')->first();
        Lead::create([
            'pancake_order_id' => '1', 'customer_name' => 'Juan', 'phone_number' => '09171234567',
            'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned',
        ]);

        $response = $this->postJson('/api/call-events', [
            'api_token'    => 'secret-token', // Gemma's token
            'phone_number' => '09171234567',
            'direction'    => 'outgoing',
        ]);

        $response->assertOk();
        $response->assertJson(['matched_lead_id' => null]);
    }

    public function test_a_missed_call_with_no_duration_is_accepted(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['api_token' => 'secret-token']);

        $response = $this->postJson('/api/call-events', [
            'api_token'    => 'secret-token',
            'phone_number' => '09171234567',
            'direction'    => 'missed',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('call_events', ['direction' => 'missed', 'duration_seconds' => null]);
    }

    /**
     * Two TSAs sharing one phone (explicit request, 2026-09-11: "2 tsa, one
     * cellphone... no shift schedules, whoever is online and clicks
     * dial") — the physical phone's MacroDroid can only hold the PRIMARY's
     * token (see TsaShift::pairWith()), so a call the PARTNER actually
     * dialed must still be re-attributed to the partner, not silently
     * logged under the primary just because it's their token.
     */
    public function test_a_paired_calls_event_is_attributed_to_whichever_partner_is_currently_calling(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $gemma->update(['api_token' => 'shared-token']);
        $gemma->pairWith($mariel);
        $mariel->applyStatusChange(TsaShift::STATUS_CALLING);

        $response = $this->postJson('/api/call-events', [
            'api_token'    => 'shared-token', // still Gemma's — the only one MacroDroid has
            'phone_number' => '09171234567',
            'direction'    => 'outgoing',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('call_events', ['tsa_id' => $mariel->id, 'phone_number' => '09171234567']);
        $this->assertDatabaseMissing('call_events', ['tsa_id' => $gemma->id]);
    }

    public function test_a_paired_calls_event_falls_back_to_the_primary_when_neither_side_is_marked_calling(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $gemma->update(['api_token' => 'shared-token']);
        $gemma->pairWith($mariel);

        $response = $this->postJson('/api/call-events', [
            'api_token'    => 'shared-token',
            'phone_number' => '09171234567',
            'direction'    => 'outgoing',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('call_events', ['tsa_id' => $gemma->id]);
    }

    public function test_wrap_up_flips_only_the_partner_actually_marked_calling_not_the_token_owner(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $gemma->update(['api_token' => 'shared-token']);
        $gemma->pairWith($mariel);
        $mariel->applyStatusChange(TsaShift::STATUS_CALLING);

        $this->postJson('/api/call-events', [
            'api_token'    => 'shared-token',
            'phone_number' => '09171234567',
            'direction'    => 'outgoing',
        ])->assertOk();

        $this->assertSame(TsaShift::STATUS_WRAP_UP, $mariel->fresh()->status);
        $this->assertNotSame(TsaShift::STATUS_WRAP_UP, $gemma->fresh()->status);
    }
}
