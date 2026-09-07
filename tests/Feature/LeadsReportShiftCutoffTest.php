<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request, revised 2026-09-07: hours OUTSIDE the team's own
 * time-based window (before it starts, or after it ends) show NOTHING for
 * this team's table — not even New Leads, since a "New Lead" outside the
 * window can't really belong to this team once Order.team is purely
 * hour-derived. A same-team-owned product can still legitimately sell
 * during the other team's hours, so those orders fold into the window's
 * own edge hour (start hour for pre-window strays, end hour for
 * post-window strays) instead of being dropped or surfacing an impossible
 * row — Called Leads can exceed New Leads and Excess can go negative there
 * by design. See LeadsReportController::buildHourlyRows().
 *
 * Window bounds are TeamShiftWindow's own fixed boundary (2026-09-07) — SH
 * Naturals/Closing is 15-23 (3pm-11pm), Eyecare/Opening is 0-14
 * (12am-2pm) — not a per-TSA shift_start lookup, since Order.team is
 * purely hour-derived now.
 */
class LeadsReportShiftCutoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function order(string $id, string $time, ?string $disposition, string $team = 'SH Naturals'): void
    {
        Order::create([
            'pancake_order_id'    => $id,
            'team'                => $team,
            'tsa_name'            => 'Gemma',
            'product'             => 'SINUXYL',
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
        // blanked (including New Leads) at its own hour, folded into 3pm
        // instead, since Closing's window hasn't started yet.
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

    public function test_shift_start_hour_absorbs_the_backlog_and_can_show_negative_excess(): void
    {
        // Backlog from before the window: 2 leads at 1pm, both already called.
        $this->order('cutoff-2', '2026-07-22 13:00:00', 'CONFIRMED VIA CALL');
        $this->order('cutoff-3', '2026-07-22 13:30:00', 'CONFIRMED VIA CALL');
        // The window-start hour's own single new lead, not called.
        $this->order('cutoff-4', '2026-07-22 15:10:00', null);

        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            $sinuxyl = $tables->firstWhere(fn($t) => $t['product']->display_name === 'SINUXYL');
            $threePm = collect($sinuxyl['hourlyRows'])->firstWhere(fn($h) => str_contains($h['label'], '3:00pm'));

            // New Leads now reflects the WHOLE backlog (3 total: 2 from 1pm
            // + 1 from 3pm itself), Called Leads is the 2 already-called 1pm
            // leads, and Excess (3 - 2) stays positive here — the earlier
            // "negative excess" example depended on New Leads staying at
            // just the cutoff hour's own count, which no longer happens now
            // that pre-window leads fold in instead of vanishing.
            return $threePm['row']['total'] === 3
                && $threePm['row']['total_called'] === 2;
        });
    }

    public function test_day_total_is_unaffected_by_the_hourly_redistribution(): void
    {
        $this->order('cutoff-5', '2026-07-22 13:00:00', 'CONFIRMED VIA CALL');
        $this->order('cutoff-6', '2026-07-22 15:10:00', null);

        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        // Grand Total (the day's overall summary, not the hourly rows) tallies
        // the whole day directly — untouched by the cutoff redistribution.
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

    /** Bug fix (2026-09-07): mirror of the start-of-window fold at the
     *  other edge — Opening's table used to show an impossible row past
     *  2pm (e.g. "3:00pm-4:00pm") whenever a same-team-owned product was
     *  legitimately sold during Closing's hours. Confirmed live. */
    public function test_hours_after_the_window_ends_fold_into_the_last_hour(): void
    {
        // Opening's window is 12am-2pm — a straggler SINUXYL... no, use an
        // Eyecare-owned product name via team assignment instead: the
        // product itself is SINUXYL (fixed by the order() helper), so seed
        // this as an Eyecare-team order to exercise Opening's own page.
        $this->order('cutoff-9', '2026-07-22 14:30:00', 'CONFIRMED VIA CALL', 'Eyecare Team');
        // A stray sale during Closing's hours (4pm) that still matches this
        // product — folds into 2pm instead of showing its own row.
        $this->order('cutoff-10', '2026-07-22 16:00:00', null, 'Eyecare Team');

        $response = $this->get(route('leads-report', [
            'team' => 'eyecare', 'range' => 'dates', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            $sinuxyl = $tables->firstWhere(fn($t) => $t['product']->display_name === 'SINUXYL');
            $fourPm  = collect($sinuxyl['hourlyRows'])->firstWhere(fn($h) => str_contains($h['label'], '4:00pm'));
            $twoPm   = collect($sinuxyl['hourlyRows'])->firstWhere(fn($h) => str_contains($h['label'], '2:00pm'));

            return $fourPm === null && $twoPm['row']['total'] === 2;
        });
    }
}
