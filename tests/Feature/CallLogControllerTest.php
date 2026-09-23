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

    /**
     * Reversed 2026-09-08 (explicit follow-up: "i want when they click in
     * number the tsa it should be reflect to the call log right?") — a
     * logCallClick() row (direction='outgoing', duration_seconds=null) now
     * counts as a real call immediately, not only once MacroDroid
     * separately confirms a duration. Was excluded 2026-09-05 for the
     * opposite reason (see this class's own updated doc comment for the
     * full history) — real production case that prompted the reversal:
     * several TSAs' genuine dialing activity a whole day never showed up
     * here at all, since MacroDroid's phone-side automation never fired.
     */
    public function test_a_call_click_with_no_confirmed_duration_now_counts_as_a_real_call(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $today = now('Asia/Manila')->startOfDay()->addHours(9);

        // Call 1: a real MacroDroid-confirmed call, 9:00:00 - 9:01:00 (60s).
        $call1 = CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '1', 'direction' => 'outgoing', 'duration_seconds' => 60, 'occurred_at' => $today->copy()->addMinute()]);
        // A click-to-call row at 9:02:00 — no confirmed duration yet, but
        // still counts, and its OWN occurred_at is its dial/start moment
        // (not an end time the way it is for every confirmed row).
        $click = CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '1', 'direction' => 'outgoing', 'duration_seconds' => null, 'occurred_at' => $today->copy()->addMinutes(2)]);
        // Call 2: a real MacroDroid-confirmed call, starts 9:10:00 (8 idle
        // minutes after the click at 9:02:00, since the click is now a
        // real event in the chronological sequence), ends 9:10:30.
        $call2 = CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '2', 'direction' => 'outgoing', 'duration_seconds' => 30, 'occurred_at' => $today->copy()->addMinutes(10)->addSeconds(30)]);

        $response = $this->actingAs($admin)->get(route('calls.call-log', [
            'date_from' => $today->toDateString(), 'date_to' => $today->toDateString(),
        ]));

        $response->assertOk();

        // total_calls counts all 3 — the click included.
        $rows = collect($response->viewData('rows'));
        $gemmaRow = $rows->firstWhere('tsa.id', $gemma->id);
        $this->assertSame(3, $gemmaRow['total_calls']);

        // The gap before the click is measured from call 1's real end
        // (9:01:00) to the click's own occurred_at (9:02:00) = 1 minute.
        $gapBeforeSeconds = $response->viewData('gapBeforeSeconds');
        $this->assertSame(60, $gapBeforeSeconds[$click->id]);
        // The gap before call 2 is measured from the click's own
        // occurred_at (9:02:00, treated as both its start and end) to
        // call 2's real start (9:10:00) = 8 minutes.
        $this->assertSame(480, $gapBeforeSeconds[$call2->id]);

        // The "Recent calls" raw list includes the click too.
        $events = $response->viewData('events');
        $this->assertCount(3, $events);
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

    /**
     * Explicit request, 2026-09-23: "is it possible in the call log page
     * add search bar like can search the number, name of the customer" —
     * phone_number lives directly on CallEvent (matches regardless of a
     * lead link); customer_name only exists via the linked Lead.
     */
    public function test_search_matches_by_phone_number(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $today = now('Asia/Manila')->format('Y-m-d');

        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '09171234567', 'direction' => 'outgoing', 'duration_seconds' => 60, 'occurred_at' => now('Asia/Manila')]);
        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '09998887777', 'direction' => 'outgoing', 'duration_seconds' => 30, 'occurred_at' => now('Asia/Manila')]);

        $response = $this->actingAs($admin)->get(route('calls.call-log', [
            'date_from' => $today, 'date_to' => $today, 'q' => '1234567',
        ]));

        $response->assertOk();
        $events = $response->viewData('events');
        $this->assertCount(1, $events);
        $this->assertSame('09171234567', $events->first()->phone_number);
    }

    /**
     * Explicit follow-up, 2026-09-23: "i want the search bar is in the
     * recent calls" — confirmed scope: search only ever narrows the
     * Recent calls list; the Per-TSA Totals table above it keeps showing
     * everyone's real totals for the picked range regardless of what's
     * typed, so searching one customer's name doesn't make it LOOK like
     * every other TSA made fewer calls today.
     */
    public function test_search_narrows_recent_calls_but_never_the_per_tsa_totals(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $today  = now('Asia/Manila')->format('Y-m-d');

        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '09171234567', 'direction' => 'outgoing', 'duration_seconds' => 60, 'occurred_at' => now('Asia/Manila')]);
        CallEvent::create(['tsa_id' => $mariel->id, 'phone_number' => '09998887777', 'direction' => 'outgoing', 'duration_seconds' => 30, 'occurred_at' => now('Asia/Manila')]);

        $response = $this->actingAs($admin)->get(route('calls.call-log', [
            'date_from' => $today, 'date_to' => $today, 'q' => '1234567',
        ]));

        $response->assertOk();
        // Recent calls: narrowed to just the matching call.
        $this->assertCount(1, $response->viewData('events'));
        // Per-TSA Totals: BOTH TSAs still show their real, unfiltered
        // total_calls — Mariel's 1 call is untouched by a search that
        // only matched Gemma's own number.
        $rows = collect($response->viewData('rows'))->keyBy(fn ($r) => $r['tsa']->id);
        $this->assertSame(1, $rows[$gemma->id]['total_calls']);
        $this->assertSame(1, $rows[$mariel->id]['total_calls']);
    }

    /**
     * Explicit follow-up, 2026-09-23: "i want to make it auto search like
     * don't need to click the enter to search, i want to make it auto" —
     * the frontend fetches this same URL on every keystroke with an
     * X-Table-Refresh header (same convention LeadController::index()/
     * TsaStatusController::index() already use for their own live
     * search/filters) and expects back just the Recent calls fragment,
     * not the full page with its own layout/topbar/Per-TSA Totals table.
     */
    public function test_an_x_table_refresh_request_returns_only_the_recent_calls_fragment(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $today = now('Asia/Manila')->format('Y-m-d');

        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '09171234567', 'direction' => 'outgoing', 'duration_seconds' => 60, 'occurred_at' => now('Asia/Manila')]);

        $response = $this->actingAs($admin)->get(route('calls.call-log', [
            'date_from' => $today, 'date_to' => $today, 'q' => '1234567',
        ]), ['X-Table-Refresh' => '1']);

        $response->assertOk();
        $response->assertSee('09171234567');
        // The full page's own chrome (Per-TSA totals heading, the page's
        // own subtitle) must be absent — only the Recent calls fragment
        // itself, same as the Leads page's own X-Table-Refresh convention.
        $response->assertDontSee('Per-TSA totals');
        $response->assertDontSee('the basis for load reimbursement');
    }

    public function test_search_matches_by_customer_name_via_the_linked_lead(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = \App\Models\Product::where('display_name', 'SINUXYL')->first();
        $today   = now('Asia/Manila')->format('Y-m-d');

        $lead = \App\Models\Lead::create([
            'pancake_order_id' => 'call-log-search-1', 'customer_name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
        ]);
        CallEvent::create(['tsa_id' => $gemma->id, 'lead_id' => $lead->id, 'phone_number' => '09171234567', 'direction' => 'outgoing', 'duration_seconds' => 60, 'occurred_at' => now('Asia/Manila')]);
        // A different, unmatched call — must not show up for an unrelated name search.
        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '09998887777', 'direction' => 'outgoing', 'duration_seconds' => 30, 'occurred_at' => now('Asia/Manila')]);

        $response = $this->actingAs($admin)->get(route('calls.call-log', [
            'date_from' => $today, 'date_to' => $today, 'q' => 'Juan',
        ]));

        $response->assertOk();
        $events = $response->viewData('events');
        $this->assertCount(1, $events);
        $this->assertSame($lead->id, $events->first()->lead_id);
    }

    /** A search with no matches returns an empty list, not everything —
     *  confirms the filter is actually narrowing, not silently ignored. */
    public function test_search_with_no_matches_returns_no_events(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $today = now('Asia/Manila')->format('Y-m-d');

        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '09171234567', 'direction' => 'outgoing', 'duration_seconds' => 60, 'occurred_at' => now('Asia/Manila')]);

        $response = $this->actingAs($admin)->get(route('calls.call-log', [
            'date_from' => $today, 'date_to' => $today, 'q' => 'no-such-match',
        ]));

        $response->assertOk();
        $this->assertCount(0, $response->viewData('events'));
    }

    /** No `q` param at all must behave exactly as before this feature —
     *  every event in range/filter, nothing narrowed. */
    public function test_no_search_param_shows_every_event(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $today = now('Asia/Manila')->format('Y-m-d');

        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '09171234567', 'direction' => 'outgoing', 'duration_seconds' => 60, 'occurred_at' => now('Asia/Manila')]);
        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '09998887777', 'direction' => 'outgoing', 'duration_seconds' => 30, 'occurred_at' => now('Asia/Manila')]);

        $response = $this->actingAs($admin)->get(route('calls.call-log', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $this->assertCount(2, $response->viewData('events'));
        $this->assertSame('', $response->viewData('q'));
    }
}
