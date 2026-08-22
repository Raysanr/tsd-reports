<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirmed in production (2026-07-28): an order can carry a genuine upsell
 * tag timestamped hours before that TSA's shift actually starts (Pancake's
 * own automation stamps the tag at order creation, not a human working that
 * hour) — the Hourly Activity chart showed "1 call" at 6am even though the
 * earliest shift anywhere starts at 8am. Same shift-cutoff treatment as
 * Leads Report's hourly breakdown (LeadsReportController::buildHourlyRows):
 * hours before the earliest active TSA's shift start show 0 calls, and the
 * shift-start hour absorbs the whole pre-shift backlog instead.
 */
class DashboardHourlyShiftCutoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        // Wednesday — not Gemma's seeded rest day (Monday) — so her shift
        // start is the active cutoff with no other TSA configured earlier.
        TsaShift::where('tsa_key', 'Gemma')->update(['shift_start' => '08:00']);
    }

    private function order(string $id, string $time, bool $isUpsell): void
    {
        Order::create([
            'pancake_order_id'    => $id,
            'team'                => 'SH Naturals',
            'tsa_name'            => 'Gemma',
            'product'             => 'SINUXYL',
            'disposition'         => $isUpsell ? null : 'CONFIRMED VIA CALL',
            'raw_tags'            => $isUpsell ? ['GEMMA', 'UPSELL TSD (SINUXYL)'] : ['GEMMA', 'CONFIRMED VIA CALL'],
            'is_upsell'           => $isUpsell,
            'status_code'         => 1,
            'pancake_created_at'  => $time,
            'pancake_inserted_at' => $time,
            'synced_at'           => now(),
        ]);
    }

    public function test_pre_shift_hour_shows_zero_calls_even_with_a_genuine_upsell_tag(): void
    {
        // 6am: a genuine upsell order, hours before Gemma's 8am shift start.
        $this->order('cutoff-1', '2026-07-22 06:47:00', isUpsell: true);

        $response = $this->get(route('dashboard', ['date_from' => '2026-07-22', 'date_to' => '2026-07-22']));

        $response->assertOk();
        $response->assertViewHas('hourlyActivity', fn ($activity) => $activity[6] === 0);
    }

    public function test_shift_start_hour_absorbs_the_pre_shift_backlog(): void
    {
        $this->order('cutoff-2', '2026-07-22 06:47:00', isUpsell: true);
        $this->order('cutoff-3', '2026-07-22 08:10:00', isUpsell: false);

        $response = $this->get(route('dashboard', ['date_from' => '2026-07-22', 'date_to' => '2026-07-22']));

        $response->assertOk();
        // Both the 6am upsell and the 8am confirmed-via-call order count as
        // "called" — both land in the 8am (shift-start) bucket.
        $response->assertViewHas('hourlyActivity', fn ($activity) => $activity[6] === 0 && $activity[8] === 2);
    }

    public function test_a_multi_day_range_skips_the_shift_cutoff_entirely(): void
    {
        $this->order('cutoff-4', '2026-07-22 06:47:00', isUpsell: true);

        $response = $this->get(route('dashboard', ['date_from' => '2026-07-21', 'date_to' => '2026-07-22']));

        $response->assertOk();
        // No cutoff applied for a date range — the 6am order's own real call
        // counts at its own hour, same as before this fix.
        $response->assertViewHas('hourlyActivity', fn ($activity) => $activity[6] === 1);
    }

    /**
     * Explicit request (2026-08-22): "make this hourly leads" — a second
     * chart alongside Hourly Activity, showing raw lead ARRIVAL volume per
     * hour. Deliberately NOT shift-cutoff-zeroed like hourlyActivity above —
     * an order genuinely arriving/getting tagged before the team's shift
     * starts is real data worth seeing (the whole point of this chart), not
     * a "nobody's working yet" artifact to hide.
     */
    public function test_hourly_leads_is_not_affected_by_the_shift_cutoff(): void
    {
        // Same 6am pre-shift order the test above proves shows 0 in
        // hourlyActivity — hourlyLeads must still count it at its own hour.
        $this->order('cutoff-5', '2026-07-22 06:47:00', isUpsell: true);

        $response = $this->get(route('dashboard', ['date_from' => '2026-07-22', 'date_to' => '2026-07-22']));

        $response->assertOk();
        $response->assertViewHas('hourlyActivity', fn ($activity) => $activity[6] === 0);
        $response->assertViewHas('hourlyLeads', fn ($leads) => $leads[6] === 1);
    }
}
