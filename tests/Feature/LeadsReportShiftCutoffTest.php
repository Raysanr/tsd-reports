<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request: hours before the team's own time-based window starts
 * show no Called/disposition/rate/Excess data (nobody's working yet — New
 * Leads is untouched), and the window-start hour absorbs the WHOLE
 * day-so-far backlog's disposition breakdown in one lump, so Called Leads can
 * exceed that hour's own New Leads and Excess can go negative there by
 * design. See LeadsReportController::buildHourlyRows().
 *
 * Cutoff hour is now TeamShiftWindow's own fixed boundary (2026-09-07) — 15
 * (3pm) for SH Naturals/Closing, 0 (midnight) for Eyecare/Opening — not a
 * per-TSA shift_start lookup, since Order.team is purely hour-derived now
 * and every SH Naturals order's own pancake_created_at is already >= 3pm.
 */
class LeadsReportShiftCutoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function order(string $id, string $time, ?string $disposition): void
    {
        Order::create([
            'pancake_order_id'    => $id,
            'team'                => 'SH Naturals',
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

    public function test_hours_before_shift_start_show_leads_but_no_disposition_data(): void
    {
        // 1pm: a lead that already has a disposition set — still forced
        // blank at its own hour, since Closing's window hasn't started yet.
        $this->order('cutoff-1', '2026-07-22 13:15:00', 'CONFIRMED VIA CALL');

        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            $sinuxyl = $tables->firstWhere(fn($t) => $t['product']->display_name === 'SINUXYL');
            $onePm   = collect($sinuxyl['hourlyRows'])->firstWhere(fn($h) => str_contains($h['label'], '1:00pm'));

            return $onePm['row']['total'] === 1
                && $onePm['row']['total_called'] === 0
                && $onePm['row']['confirmed_via_call'] === 0
                && $onePm['row']['pick_up_rate'] === null;
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

            // New Leads = just this hour's own (1), but Called Leads reflects
            // the whole backlog (the 2 already-called 1pm leads) — 2 > 1, and
            // Excess (1 - 2) goes negative.
            return $threePm['row']['total'] === 1
                && $threePm['row']['total_called'] === 2
                && $threePm['row']['excess'] === -1;
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

            // 8am is still blanked-not-cutoff (New Leads visible, no
            // disposition data) rather than treated as a normal hour, even
            // though a TSA is configured to start at 8am — the cutoff no
            // longer reads shift_start at all.
            return $eightAm['row']['total'] === 1 && $eightAm['row']['total_called'] === 0;
        });
    }
}
