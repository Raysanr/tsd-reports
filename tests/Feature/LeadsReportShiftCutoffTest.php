<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request, revised 2026-09-07 (seven times — see history below):
 * hours OUTSIDE the team's own time-based window (before it starts, or
 * after it ends) show NOTHING for this team's table — not even New Leads,
 * since a "New Lead" outside the window can't really belong to this team
 * once Order.team is purely hour-derived.
 *
 * WITHIN that window, a backlog-catch-up lump is restored (seventh
 * revision — explicit request: "there will be still -(negative) in the
 * excess leads as before"): hours from the window's own start up to the
 * earliest active TSA's own configured shift_start that day (never
 * earlier than the window start) show New Leads only, blanked
 * disposition/rate/Excess; the shift_start hour itself absorbs that
 * WHOLE backlog's Called/disposition data in one lump, so Called Leads
 * there can exceed that hour's own New Leads and Excess can go negative
 * — same as before the redesign. If no TSA has a shift_start configured
 * at or after the window's own start, there's no extra lump and the
 * window-start hour behaves like any other real hour.
 *
 * History: an earlier version of the "outside window" fix folded every
 * outside-window order into the window's nearest edge hour, reasoning
 * that a same-team-owned product could still legitimately sell during
 * the other team's hours and those orders needed somewhere to go. That
 * broke in practice — "outside the window" isn't a small backlog, it's
 * the OTHER team's entire ordinary working day, so the fold just
 * relocated the same bug (confirmed live: 295 orders all landing in one
 * row as "67 New Leads"). Simplified to no folding at the EDGES — but a
 * per-team page ALSO briefly showed every product's row regardless of
 * team (browsable cross-team sales), which combined with the plain
 * per-team pool caused a different, worse symptom: a foreign-team
 * product's row showed a real, nonzero total that Grand Total quietly
 * excluded, reading as broken math. Reverted that too — each team's page
 * now only shows its OWN team's products, so this file's fixture orders
 * only need to use a product belonging to the team under test (see the
 * order() helper's own $product param). The backlog lump WITHIN the
 * window (this file's newest tests) is a separate, later, explicitly
 * requested restoration of the original pre-redesign behavior.
 *
 * Window bounds are TeamShiftWindow's own fixed boundary (2026-09-07) — SH
 * Naturals/Closing is 15-23 (3pm-11pm), Eyecare/Opening is 0-14
 * (12am-2pm). The window bounds themselves are NOT per-TSA — only the
 * WITHIN-window lump hour reads TsaShift.shift_start.
 */
class LeadsReportShiftCutoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function order(string $id, string $time, ?string $disposition, string $team = 'SH Naturals', string $product = 'SINUXYL'): void
    {
        Order::create([
            'pancake_order_id'    => $id,
            'team'                => $team,
            'tsa_name'            => 'Gemma',
            'product'             => $product,
            'disposition'         => $disposition,
            'is_upsell'           => false,
            'status_code'         => 1,
            'pancake_created_at'  => $time,
            'pancake_inserted_at' => $time,
            'synced_at'           => now(),
        ]);
    }

    public function test_hours_before_the_window_starts_show_no_row_at_all(): void
    {
        // 1pm: a lead that already has a disposition set — still fully
        // excluded from Closing's hourly table, since Closing's window
        // hasn't started yet (it never appears anywhere on this table).
        $this->order('cutoff-1', '2026-07-22 13:15:00', 'CONFIRMED VIA CALL');

        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            $sinuxyl = $tables->firstWhere(fn($t) => $t['product']->display_name === 'SINUXYL');
            $onePm   = collect($sinuxyl['hourlyRows'])->firstWhere(fn($h) => str_contains($h['label'], '1:00pm'));

            return $onePm === null;
        });
    }

    public function test_a_real_hour_inside_the_window_shows_only_its_own_orders(): void
    {
        // A pre-window order at 1pm — must not leak into 3pm's own row.
        $this->order('cutoff-2', '2026-07-22 13:00:00', 'CONFIRMED VIA CALL');
        $this->order('cutoff-3', '2026-07-22 13:30:00', 'CONFIRMED VIA CALL');
        // 3pm's own single real order — the only one that should show here.
        $this->order('cutoff-4', '2026-07-22 15:10:00', null);

        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            $sinuxyl = $tables->firstWhere(fn($t) => $t['product']->display_name === 'SINUXYL');
            $threePm = collect($sinuxyl['hourlyRows'])->firstWhere(fn($h) => str_contains($h['label'], '3:00pm'));

            return $threePm['row']['total'] === 1 && $threePm['row']['total_called'] === 0;
        });
    }

    public function test_day_total_is_unaffected_by_the_hourly_exclusion(): void
    {
        $this->order('cutoff-5', '2026-07-22 13:00:00', 'CONFIRMED VIA CALL');
        $this->order('cutoff-6', '2026-07-22 15:10:00', null);

        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        // Grand Total (the day's overall summary, not the hourly rows) tallies
        // the whole day directly — untouched by the hourly window exclusion.
        $response->assertViewHas('grandTotal', fn($grandTotal) => $grandTotal['total'] === 2 && $grandTotal['total_called'] === 1);
    }

    /** Bug fix (2026-09-07): the cutoff used to come from a TSA's own
     *  configured shift_start (e.g. 8am), which could sit well before 3pm —
     *  showing hourly rows for 8am-2pm on Closing's own page, none of which
     *  should be possible now that Order.team is purely hour-derived (an
     *  order created before 3pm can never be SH Naturals'). Confirmed live:
     *  Closing's hourly table showed non-zero New Leads at 1am-2pm. */
    public function test_the_cutoff_is_always_3pm_regardless_of_any_tsas_own_shift_start(): void
    {
        \App\Models\TsaShift::where('tsa_key', 'Gemma')->update(['shift_start' => '08:00']);

        $this->order('cutoff-7', '2026-07-22 08:00:00', 'CONFIRMED VIA CALL');
        $this->order('cutoff-8', '2026-07-22 15:05:00', null);

        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            $sinuxyl = $tables->firstWhere(fn($t) => $t['product']->display_name === 'SINUXYL');
            $eightAm = collect($sinuxyl['hourlyRows'])->firstWhere(fn($h) => str_contains($h['label'], '8:00am'));

            // 8am no longer shows a row at all, even though a TSA is
            // configured to start at 8am — the cutoff no longer reads
            // shift_start at all.
            return $eightAm === null;
        });
    }

    /** Bug fix (2026-09-07): mirror of the start-of-window exclusion at the
     *  other edge — Opening's table used to show an impossible row past
     *  2pm (e.g. "3:00pm-4:00pm") whenever an Opening-team order landed
     *  after 2pm (e.g. a backfill/edge-case timestamp). Confirmed live. */
    public function test_hours_after_the_window_ends_show_no_row_at_all(): void
    {
        $this->order('cutoff-9', '2026-07-22 14:30:00', 'CONFIRMED VIA CALL', 'Eyecare Team', 'PTERYGIUM');
        // An Eyecare-team order landing at 4pm, past Opening's own window —
        // must not appear anywhere on Opening's hourly table.
        $this->order('cutoff-10', '2026-07-22 16:00:00', null, 'Eyecare Team', 'PTERYGIUM');

        $response = $this->get(route('leads-report', [
            'team' => 'eyecare', 'range' => 'dates', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            $pterygium = $tables->firstWhere(fn($t) => $t['product']->display_name === 'PTERYGIUM');
            $fourPm    = collect($pterygium['hourlyRows'])->firstWhere(fn($h) => str_contains($h['label'], '4:00pm'));
            $twoPm     = collect($pterygium['hourlyRows'])->firstWhere(fn($h) => str_contains($h['label'], '2:00pm'));

            return $fourPm === null && $twoPm['row']['total'] === 1;
        });
    }

    /** Regression guard for the exact production bug (2026-09-07): many
     *  real Eyecare-hour orders (Opening's own ordinary working day, not a
     *  small backlog) that happen to match an SH-Naturals-owned product
     *  must NOT all pile into Closing's 3pm row — each stays invisible on
     *  Closing's hourly table, only the genuine 3pm order shows there. */
    public function test_a_full_days_worth_of_pre_window_orders_does_not_pile_into_the_cutoff_hour(): void
    {
        foreach (range(6, 14) as $hour) {
            $this->order("cutoff-bulk-{$hour}", "2026-07-22 {$hour}:00:00", 'CONFIRMED VIA CALL');
        }
        $this->order('cutoff-real-3pm', '2026-07-22 15:10:00', null);

        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            $sinuxyl = $tables->firstWhere(fn($t) => $t['product']->display_name === 'SINUXYL');
            $threePm = collect($sinuxyl['hourlyRows'])->firstWhere(fn($h) => str_contains($h['label'], '3:00pm'));

            return $threePm['row']['total'] === 1;
        });
    }

    /** Explicit request (2026-09-07, seventh revision): restores the
     *  original backlog-catch-up lump WITHIN the team's own window — a TSA
     *  starting her shift mid-window absorbs that day's backlog (since the
     *  window opened) in one lump, so Called Leads can exceed that hour's
     *  own New Leads and Excess can go negative, same as before the
     *  redesign. Bounded to stay inside the window: the lump can never
     *  start before $shiftCutoffHour (3pm here), so hours before 3pm still
     *  show nothing — the earlier "wrong hours leaking in" fix stays
     *  intact. Hours between the window start and the TSA's own shift
     *  start (3pm, 4pm here) still show their own real New Leads count
     *  (leads keep arriving regardless of whether anyone's working yet),
     *  just with disposition/rate/Excess blanked — same as the original
     *  pre-redesign behavior. */
    public function test_a_tsa_starting_mid_window_absorbs_that_days_backlog_since_the_window_opened(): void
    {
        \App\Models\TsaShift::where('tsa_key', 'Gemma')->update(['shift_start' => '17:00']);

        // Backlog since the window opened (3pm): 2 leads at 3pm and 4pm,
        // both already called — the calls don't show at their own hour.
        $this->order('cutoff-11', '2026-07-22 15:10:00', 'CONFIRMED VIA CALL');
        $this->order('cutoff-12', '2026-07-22 16:20:00', 'CONFIRMED VIA CALL');
        // Gemma's own shift-start hour's single new lead, not yet called.
        $this->order('cutoff-13', '2026-07-22 17:05:00', null);

        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            $sinuxyl = $tables->firstWhere(fn($t) => $t['product']->display_name === 'SINUXYL');
            $hourlyRows = collect($sinuxyl['hourlyRows']);

            // str_starts_with, not str_contains — "3:00pm – 4:00pm"'s own
            // label text contains "4:00pm" as its END boundary too, which
            // would otherwise make the 4pm lookup below wrongly match the
            // 3pm row (and the 5pm lookup wrongly match the 4pm row).
            $threePm = $hourlyRows->firstWhere(fn($h) => str_starts_with($h['label'], '3:00pm'));
            $fourPm  = $hourlyRows->firstWhere(fn($h) => str_starts_with($h['label'], '4:00pm'));
            $fivePm  = $hourlyRows->firstWhere(fn($h) => str_starts_with($h['label'], '5:00pm'));

            // 3pm and 4pm each show their own real New Leads (1 apiece),
            // but no disposition data — the TSA hasn't started yet, even
            // though we're already inside Closing's own window.
            if ($threePm['row']['total'] !== 1 || $threePm['row']['total_called'] !== 0) return false;
            if ($fourPm['row']['total'] !== 1 || $fourPm['row']['total_called'] !== 0) return false;

            // 5pm (Gemma's own shift start) absorbs the WHOLE 3pm-5pm
            // backlog's disposition data: New Leads stays at just its own
            // count (1), but Called Leads reflects the 2 already-called
            // backlog leads — 2 > 1, so Excess (1 - 2) goes negative,
            // exactly like before the redesign.
            return $fivePm['row']['total'] === 1
                && $fivePm['row']['total_called'] === 2
                && $fivePm['row']['excess'] === -1;
        });
    }

    /** The day's overall Grand Total stays a plain, un-lumped tally of
     *  every real order regardless of the hourly redistribution above —
     *  same invariant as the pre-lump-restoration tests. */
    public function test_day_total_is_unaffected_by_the_restored_backlog_lump(): void
    {
        \App\Models\TsaShift::where('tsa_key', 'Gemma')->update(['shift_start' => '17:00']);

        $this->order('cutoff-14', '2026-07-22 15:10:00', 'CONFIRMED VIA CALL');
        $this->order('cutoff-15', '2026-07-22 17:05:00', null);

        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('grandTotal', fn($grandTotal) => $grandTotal['total'] === 2 && $grandTotal['total_called'] === 1);
    }
}
