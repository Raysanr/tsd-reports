<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request: hours before the team's earliest working TSA's shift
 * start show no Called/disposition/rate/Excess data (nobody's working yet —
 * New Leads is untouched), and the shift-start hour absorbs the WHOLE
 * day-so-far backlog's disposition breakdown in one lump, so Called Leads can
 * exceed that hour's own New Leads and Excess can go negative there by
 * design. See LeadsReportController::buildHourlyRows().
 */
class LeadsReportShiftCutoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        // Wednesday — not Gemma's seeded rest day (monday) — so her shift
        // start is the active cutoff for SH Naturals with no other TSA
        // configured earlier.
        TsaShift::where('tsa_key', 'Gemma')->update(['shift_start' => '08:00']);
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
        // 6am: a lead that already has a disposition set — still forced
        // blank at its own hour, since nobody was working yet at 6am.
        $this->order('cutoff-1', '2026-07-22 06:15:00', 'CONFIRMED VIA CALL');

        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            $sinuxyl = $tables->firstWhere(fn($t) => $t['product']->display_name === 'SINUXYL');
            $sixAm   = collect($sinuxyl['hourlyRows'])->firstWhere(fn($h) => str_contains($h['label'], '6am'));

            return $sixAm['row']['total'] === 1
                && $sixAm['row']['total_called'] === 0
                && $sixAm['row']['confirmed_via_call'] === 0
                && $sixAm['row']['pick_up_rate'] === null;
        });
    }

    public function test_shift_start_hour_absorbs_the_backlog_and_can_show_negative_excess(): void
    {
        // Backlog from before the shift: 2 leads at 6am, both already called.
        $this->order('cutoff-2', '2026-07-22 06:00:00', 'CONFIRMED VIA CALL');
        $this->order('cutoff-3', '2026-07-22 06:30:00', 'CONFIRMED VIA CALL');
        // The shift-start hour's own single new lead, not called.
        $this->order('cutoff-4', '2026-07-22 08:10:00', null);

        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            $sinuxyl = $tables->firstWhere(fn($t) => $t['product']->display_name === 'SINUXYL');
            $eightAm = collect($sinuxyl['hourlyRows'])->firstWhere(fn($h) => str_contains($h['label'], '8am'));

            // New Leads = just this hour's own (1), but Called Leads reflects
            // the whole backlog (the 2 already-called 6am leads) — 2 > 1, and
            // Excess (1 - 2) goes negative.
            return $eightAm['row']['total'] === 1
                && $eightAm['row']['total_called'] === 2
                && $eightAm['row']['excess'] === -1;
        });
    }

    public function test_day_total_is_unaffected_by_the_hourly_redistribution(): void
    {
        $this->order('cutoff-5', '2026-07-22 06:00:00', 'CONFIRMED VIA CALL');
        $this->order('cutoff-6', '2026-07-22 08:10:00', null);

        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        // Grand Total (the day's overall summary, not the hourly rows) tallies
        // the whole day directly — untouched by the cutoff redistribution.
        $response->assertViewHas('grandTotal', fn($grandTotal) => $grandTotal['total'] === 2 && $grandTotal['total_called'] === 1);
    }
}
