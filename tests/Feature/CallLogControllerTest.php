<?php

namespace Tests\Feature;

use App\Models\CallEvent;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift, routes -> calls.*.
 *
 * NOTE (adapted, not a verbatim port): the original test used TSA key
 * "Katherine" for the "a TSA with zero calls doesn't clutter the totals
 * table" assertion. tsd-reports' baseline tsa_shifts seed only has 6 TSAs
 * (Gemma, Mariel, Kathleen, Julie, Joana, Marisol) — no Katherine (see
 * ReconcileCallTrackerRosterTest's own comment on this exact gap) —
 * substituted "Kathleen", who exists in the seed and also gets zero calls
 * in this test, to preserve the same assertion's intent.
 */
class CallLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tsa_cannot_reach_the_call_log(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->get(route('calls.call-log'))->assertForbidden();
    }

    public function test_it_totals_calls_per_tsa_within_the_selected_range(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();

        $today = now('Asia/Manila')->format('Y-m-d');

        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '1', 'direction' => 'outgoing', 'duration_seconds' => 60, 'occurred_at' => now('Asia/Manila')]);
        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '2', 'direction' => 'missed', 'duration_seconds' => null, 'occurred_at' => now('Asia/Manila')]);
        CallEvent::create(['tsa_id' => $mariel->id, 'phone_number' => '3', 'direction' => 'incoming', 'duration_seconds' => 30, 'occurred_at' => now('Asia/Manila')]);
        // Outside the range — must not be counted.
        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '4', 'direction' => 'outgoing', 'duration_seconds' => 999, 'occurred_at' => now('Asia/Manila')->subDays(5)]);

        $response = $this->actingAs($admin)->get(route('calls.call-log', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $rows = collect($response->viewData('rows'));

        $gemmaRow = $rows->firstWhere('tsa.id', $gemma->id);
        $this->assertSame(2, $gemmaRow['total_calls']);
        $this->assertSame(1, $gemmaRow['outgoing']);
        $this->assertSame(1, $gemmaRow['missed']);
        $this->assertSame(60, $gemmaRow['total_duration_s']);

        $marielRow = $rows->firstWhere('tsa.id', $mariel->id);
        $this->assertSame(1, $marielRow['total_calls']);

        // A TSA with zero calls in range doesn't clutter the totals table.
        $kathleen = TsaShift::where('tsa_key', 'Kathleen')->first();
        $this->assertNull($rows->firstWhere('tsa.id', $kathleen->id));
    }
}
