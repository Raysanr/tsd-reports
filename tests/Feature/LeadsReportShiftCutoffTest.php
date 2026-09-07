<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request, revised 2026-09-07 (twice — see history below): hours
 * OUTSIDE the team's own time-based window (before it starts, or after it
 * ends) show NOTHING for this team's table — not even New Leads, since a
 * "New Lead" outside the window can't really belong to this team once
 * Order.team is purely hour-derived. Each hour INSIDE the window shows only
 * its own real orders — a genuine 3:10pm order shows only in the
 * 3:00pm-4:00pm row on Closing's page, nowhere else.
 *
 * History: an earlier version of this fix folded every outside-window
 * order into the window's nearest edge hour (start hour for pre-window
 * strays, end hour for post-window strays), reasoning that a same-team-
 * owned product can still legitimately sell during the other team's hours
 * (ProductPerformance::matchingOrders() trusts an order's own item over its
 * hour-derived team) and those orders needed somewhere to go. That was
 * wrong in practice: on a real day, "outside the window" isn't a small
 * backlog — it's the OTHER team's entire, ordinary working day, so the
 * fold just relocated the exact same "whole day's leads dumped into one
 * row" bug from many hours to a single edge hour (confirmed live: 295
 * orders across Opening's working hours all landing in Closing's own
 * 3:00pm-4:00pm row as "67 New Leads"). Simplified to no folding at all —
 * outside-window orders just don't appear in this team's HOURLY breakdown
 * (they still count in this team's own Grand Total day-sum via
 * ProductPerformance::matchingOrders()'s existing cross-team trust, and
 * still show on other views like TSA Performance).
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
     *  2pm (e.g. "3:00pm-4:00pm") whenever a same-team-owned product was
     *  legitimately sold during Closing's hours. Confirmed live. */
    public function test_hours_after_the_window_ends_show_no_row_at_all(): void
    {
        $this->order('cutoff-9', '2026-07-22 14:30:00', 'CONFIRMED VIA CALL', 'Eyecare Team');
        // A stray sale during Closing's hours (4pm) that still matches this
        // product (SINUXYL, an SH Naturals product, seeded here on an
        // Eyecare-team order to reach Opening's own page) — must not appear
        // anywhere on Opening's hourly table.
        $this->order('cutoff-10', '2026-07-22 16:00:00', null, 'Eyecare Team');

        $response = $this->get(route('leads-report', [
            'team' => 'eyecare', 'range' => 'dates', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            $sinuxyl = $tables->firstWhere(fn($t) => $t['product']->display_name === 'SINUXYL');
            $fourPm  = collect($sinuxyl['hourlyRows'])->firstWhere(fn($h) => str_contains($h['label'], '4:00pm'));
            $twoPm   = collect($sinuxyl['hourlyRows'])->firstWhere(fn($h) => str_contains($h['label'], '2:00pm'));

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
}
