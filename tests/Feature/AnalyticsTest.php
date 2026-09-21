<?php

namespace Tests\Feature;

use App\Models\CallEvent;
use App\Models\Lead;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift, routes -> calls.*.
 *
 * NOTE: this file wasn't explicitly named in the Phase 9 plan's port list
 * (only Analytics*Controller* was named as ported in Phase 2) — porting it
 * anyway since AnalyticsController genuinely was ported and had zero test
 * coverage otherwise. No filename collision with any existing tsd-reports
 * test.
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tsa_cannot_reach_analytics(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->get(route('calls.analytics'))->assertForbidden();
    }

    /**
     * Replaces the old Confirm Rate/No-Answer Rate columns (explicit
     * request, 2026-09-21 — see AnalyticsController's own doc comment on
     * avgGapSecondsByTsa for why: those two read as 0%/100% for nearly
     * every TSA on a real production day with substantial call volume,
     * confirmed live not a math bug — TSAs consistently log Unattended/Not
     * Answering (which sets a callback reminder) but essentially never log
     * a Confirmed/upsell disposition back in Call Tracker for a successful
     * call, so the rate was measuring an unrepresentative sliver of "TSA
     * bothered to log an outcome," not real call quality). Avg Gap/Call is
     * computed straight from CallEvent's own occurred_at/duration_seconds —
     * real phone activity, no disposition-logging step required — same
     * exact per-TSA chronological-gap algorithm and fixture shape as
     * CallLogControllerTest::test_computes_the_idle_gap_between_a_tsas_consecutive_calls().
     */
    public function test_it_computes_the_average_gap_between_a_tsas_calls(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $today = now('Asia/Manila')->startOfDay()->addHours(9);

        // Call 1: 9:00:00 - 9:01:00 (60s).
        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '1', 'direction' => 'outgoing', 'duration_seconds' => 60, 'occurred_at' => $today->copy()->addMinute()]);
        // Call 2 starts 9:06:00 (5 idle minutes after call 1 ended), ends 9:06:30.
        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '2', 'direction' => 'outgoing', 'duration_seconds' => 30, 'occurred_at' => $today->copy()->addMinutes(6)->addSeconds(30)]);
        // Call 3 starts 9:20:30 (14 idle minutes after call 2 ended), ends 9:21:00.
        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '3', 'direction' => 'outgoing', 'duration_seconds' => 30, 'occurred_at' => $today->copy()->addMinutes(21)]);

        $response = $this->actingAs($admin)->get(route('calls.analytics', [
            'date_from' => $today->toDateString(), 'date_to' => $today->toDateString(),
        ]));

        $response->assertOk();
        $gemmaRow = collect($response->viewData('rows'))->firstWhere('tsa.id', $gemma->id);
        // (300 + 840) / 2 = 570 seconds = 9m 30s.
        $this->assertSame(570, $gemmaRow['avg_gap_seconds']);
        $response->assertSee('9m 30s');
    }

    /** A TSA with no calls in range has nothing to compute a gap from —
     *  must show the empty-state dash, not 0/null coerced into something
     *  that reads as "back-to-back calls with zero gap". */
    public function test_a_tsa_with_no_calls_shows_no_gap(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();

        $response = $this->actingAs($admin)->get(route('calls.analytics'));

        $response->assertOk();
        $gemmaRow = collect($response->viewData('rows'))->firstWhere('tsa.id', $gemma->id);
        $this->assertNull($gemmaRow['avg_gap_seconds']);
    }

    /** A single call has nothing before it to gap against — same "no gap
     *  yet" state as zero calls, not a false 0-second gap. */
    public function test_a_tsas_single_call_has_no_gap_yet(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();

        CallEvent::create(['tsa_id' => $gemma->id, 'phone_number' => '1', 'direction' => 'outgoing', 'duration_seconds' => 60, 'occurred_at' => now('Asia/Manila')]);

        $response = $this->actingAs($admin)->get(route('calls.analytics'));

        $response->assertOk();
        $gemmaRow = collect($response->viewData('rows'))->firstWhere('tsa.id', $gemma->id);
        $this->assertNull($gemmaRow['avg_gap_seconds']);
    }

    public function test_a_lead_created_outside_the_date_range_is_excluded(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        // Scoped to pancake_created_at, not assigned_at (explicit fix,
        // 2026-09-16 — see AnalyticsController's own doc comment: a lead
        // CREATED long ago but only just assigned today, e.g. by
        // SyncPancakeLeads::catchUpUnassignedLeads() finally working through
        // an old backlog, must NOT count as today's lead just because
        // assigned_at happens to say today). assigned_at is set to NOW here
        // deliberately, to prove the exclusion really is driven by
        // pancake_created_at and not merely by assigned_at also being old.
        Lead::create(['pancake_order_id' => 'old', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'called', 'disposition' => 'TSD UPSELL - SINUXYL', 'pancake_created_at' => now()->subDays(10), 'assigned_at' => now(), 'called_at' => now()]);

        $response = $this->actingAs($admin)->get(route('calls.analytics'));

        $response->assertOk();
        $gemmaRow = collect($response->original->getData()['rows'])->firstWhere('tsa.id', $gemma->id);
        // Row still renders (every TSA always shows) but the order was
        // created 10 days ago, outside today's default range, so it's
        // excluded despite being assigned today.
        $this->assertSame(0, $gemmaRow['total']);
    }

    /** The regression this fix targets: a lead created long ago but only
     *  just assigned TODAY (e.g. by the round-robin catch-up sweep finally
     *  working through an old backlog) must not inflate today's Total Leads
     *  — confirmed live, 2026-09-16: several TSAs showed 1000+ "Total Leads"
     *  for a single day this way. */
    public function test_a_lead_created_long_ago_but_only_just_assigned_today_does_not_inflate_total_leads(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        Lead::create(['pancake_order_id' => 'backlog', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'pancake_created_at' => now()->subDays(30), 'assigned_at' => now()]);

        $response = $this->actingAs($admin)->get(route('calls.analytics'));

        $response->assertOk();
        $gemmaRow = collect($response->original->getData()['rows'])->firstWhere('tsa.id', $gemma->id);
        $this->assertSame(0, $gemmaRow['total']);
    }
}
