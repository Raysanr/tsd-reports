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
 * "Katherine" for a "TSA with zero calls" assertion. tsd-reports' baseline
 * tsa_shifts seed only has 6 TSAs (Gemma, Mariel, Kathleen, Julie, Joana,
 * Marisol) — no Katherine (see ReconcileCallTrackerRosterTest's own comment
 * on this exact gap) — substituted "Kathleen" throughout, who exists in the
 * seed and also gets zero calls in these tests.
 *
 * Follow-up (2026-08-24): a zero-call TSA used to be filtered OUT of the
 * totals table entirely ("doesn't clutter") — explicit request reversed
 * that: every active TSA in scope shows up now, 0 calls included, since
 * disappearing read as "not tracked" rather than "tracked, made no calls".
 */
class CallLogControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Explicit follow-up (2026-09-02: "i want tsa can see access this tabs
     * — dashboard, leads, call log") — reverses the earlier admin-only
     * restriction. The controller forces $selectedTsa to the viewer's own
     * tsa_id for a non-admin regardless of any team/tsa param, so a TSA
     * only ever sees their own row here, never a colleague's.
     */
    public function test_a_tsa_can_reach_the_call_log_and_sees_only_their_own_row(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $user   = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $today = now('Asia/Manila')->format('Y-m-d');
        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '1', 'direction' => 'outgoing', 'duration_seconds' => 60, 'occurred_at' => now('Asia/Manila')]);
        CallEvent::create(['tsa_id' => $mariel->id, 'phone_number' => '2', 'direction' => 'incoming', 'duration_seconds' => 30, 'occurred_at' => now('Asia/Manila')]);

        // Even a colleague's tsa_id passed explicitly in the URL is ignored
        // for a non-admin — the controller always forces their own.
        $response = $this->actingAs($user)->get(route('calls.call-log', ['date_from' => $today, 'date_to' => $today, 'tsa' => $mariel->id]));

        $response->assertOk();
        $rows = collect($response->viewData('rows'));

        $this->assertCount(1, $rows);
        $this->assertSame($gemma->id, $rows->first()['tsa']->id);
        $this->assertSame(1, $rows->first()['total_calls']);

        // The raw "Recent calls" event list is scoped the same way.
        $events = $response->viewData('events');
        $this->assertCount(1, $events);
        $this->assertSame($gemma->id, $events->first()->tsa_id);
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

        $marielRow = $rows->firstWhere('tsa.id', $mariel->id);
        $this->assertSame(1, $marielRow['total_calls']);
        // Nothing earlier in range to gap against — a lone call has no gap.
        $this->assertNull($marielRow['avg_gap_seconds']);
        $this->assertNull($marielRow['longest_gap_seconds']);

        // A TSA with zero calls in range still shows up, with 0 — not
        // filtered out entirely.
        $kathleen = TsaShift::where('tsa_key', 'Kathleen')->first();
        $kathleenRow = $rows->firstWhere('tsa.id', $kathleen->id);
        $this->assertNotNull($kathleenRow);
        $this->assertSame(0, $kathleenRow['total_calls']);
        $this->assertNull($kathleenRow['avg_gap_seconds']);
    }

    /**
     * Explicit request (2026-08-24): replaced the outgoing/incoming/missed/
     * duration breakdown with "how much idle time sat between this TSA's
     * calls" — the actual pace question this page was for. Gap is measured
     * from one call's END (occurred_at, stamped when Macro 1's "Call Ended"
     * trigger fires) to the NEXT call's START (its own occurred_at minus its
     * own duration), never confused with a call's own length.
     */
    public function test_computes_the_idle_gap_between_a_tsas_consecutive_calls(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $today = now('Asia/Manila')->startOfDay()->addHours(9);

        // Call 1: 9:00:00 - 9:01:00 (60s).
        $call1 = CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '1', 'direction' => 'outgoing', 'duration_seconds' => 60, 'occurred_at' => $today->copy()->addMinute()]);
        // Call 2 starts 9:06:00 (5 real idle minutes after call 1 ended at 9:01:00), ends 9:06:30.
        $call2 = CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '2', 'direction' => 'outgoing', 'duration_seconds' => 30, 'occurred_at' => $today->copy()->addMinutes(6)->addSeconds(30)]);
        // Call 3 starts 9:20:30 (14 idle minutes after call 2 ended), ends 9:21:00.
        $call3 = CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '3', 'direction' => 'outgoing', 'duration_seconds' => 30, 'occurred_at' => $today->copy()->addMinutes(21)]);

        $response = $this->actingAs($admin)->get(route('calls.call-log', [
            'date_from' => $today->toDateString(), 'date_to' => $today->toDateString(),
        ]));

        $response->assertOk();
        $gapBeforeSeconds = $response->viewData('gapBeforeSeconds');

        $this->assertArrayNotHasKey($call1->id, $gapBeforeSeconds, 'first call of the day has no gap');
        $this->assertSame(300, $gapBeforeSeconds[$call2->id]); // 5 minutes
        $this->assertSame(840, $gapBeforeSeconds[$call3->id]); // 14 minutes

        $gemmaRow = collect($response->viewData('rows'))->firstWhere('tsa.id', $gemma->id);
        $this->assertSame((300 + 840) / 2, $gemmaRow['avg_gap_seconds']);
        $this->assertSame(840, $gemmaRow['longest_gap_seconds']);
    }

    /** Explicit request (2026-08-24): filter by team, same ALL/SH Naturals/
     *  Eyecare convention Monitor TSA's own filter already uses. */
    public function test_a_team_filter_scopes_both_the_totals_and_the_recent_calls_list(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first(); // SH Naturals
        $julie  = TsaShift::where('tsa_key', 'Julie')->first(); // Eyecare Team
        $today  = now('Asia/Manila')->format('Y-m-d');

        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '1', 'direction' => 'outgoing', 'duration_seconds' => 60, 'occurred_at' => now('Asia/Manila')]);
        CallEvent::create(['tsa_id' => $julie->id, 'phone_number' => '2', 'direction' => 'outgoing', 'duration_seconds' => 30, 'occurred_at' => now('Asia/Manila')]);

        $response = $this->actingAs($admin)->get(route('calls.call-log', [
            'team' => 'sh-naturals', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $rows = collect($response->viewData('rows'));
        $this->assertNotNull($rows->firstWhere('tsa.id', $gemma->id));
        $this->assertNull($rows->firstWhere('tsa.id', $julie->id));

        $events = $response->viewData('events');
        $this->assertTrue($events->every(fn ($e) => $e->tsa_id === $gemma->id));
    }

    /** Explicit request (2026-08-25): "make this table has filter of
     *  TSA'S" — narrows both the per-TSA totals table and the Recent
     *  calls list down to one TSA, same convention as the team filter
     *  above. */
    public function test_a_tsa_filter_scopes_both_the_totals_and_the_recent_calls_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $today = now('Asia/Manila')->format('Y-m-d');

        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '1', 'direction' => 'outgoing', 'duration_seconds' => 60, 'occurred_at' => now('Asia/Manila')]);
        CallEvent::create(['tsa_id' => $mariel->id, 'phone_number' => '2', 'direction' => 'outgoing', 'duration_seconds' => 30, 'occurred_at' => now('Asia/Manila')]);

        $response = $this->actingAs($admin)->get(route('calls.call-log', [
            'tsa' => $gemma->id, 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        // Only Gemma's row remains — a picked TSA narrows the totals table
        // down to just them, not a zero'd-out row for everyone else.
        $rows = collect($response->viewData('rows'));
        $this->assertCount(1, $rows);
        $this->assertSame($gemma->id, $rows->first()['tsa']->id);

        $events = $response->viewData('events');
        $this->assertTrue($events->every(fn ($e) => $e->tsa_id === $gemma->id));

        // The dropdown's own option list ($teamTsas) stays every TSA on the
        // team, not narrowed down to just the one currently picked.
        $this->assertGreaterThan(1, $response->viewData('teamTsas')->count());
    }

    /** An explicit "tsa=" (empty) clears the remembered filter back to
     *  every TSA — same has()-based-empty-clear convention
     *  PersistsCallTrackerFilters documents for every other filter here. */
    public function test_an_empty_tsa_param_clears_the_filter(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();

        $this->actingAs($admin)->get(route('calls.call-log', ['tsa' => $gemma->id]));
        $response = $this->actingAs($admin)->get(route('calls.call-log', ['tsa' => '']));

        $response->assertOk();
        $this->assertNull($response->viewData('selectedTsa'));
    }
}
