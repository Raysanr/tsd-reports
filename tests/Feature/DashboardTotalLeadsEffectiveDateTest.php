<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request: Dashboard's "Total Leads" must tally with Leads Report's
 * Grand Total on any date, not just by coincidence — confirmed live that a
 * backlog lead (created one day, worked by a TSA the next) inflated Dashboard's
 * count for the worked day while Leads Report excluded it, since Leads Report
 * filters by COALESCE(pancake_inserted_at, pancake_created_at) (real creation
 * date, matching Pancake POS's own Created-At filter) while Dashboard's Total
 * Leads was filtering by pancake_created_at alone (worked-at time) — see
 * DashboardController::index()'s $leadOrders comment. Same effective-date rule
 * already covered for Leads Report itself in LeadsReportEffectiveDateTest.
 */
class DashboardTotalLeadsEffectiveDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_an_order_created_yesterday_but_worked_today_is_NOT_in_todays_total_leads(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        Order::create([
            'pancake_order_id'    => 'backlog-1',
            'team'                => 'SH Naturals',
            'tsa_name'            => $shift->tsa_key,
            'disposition'         => 'CONFIRMED VIA CALL',
            'product'             => 'Sinuxyl',
            'raw_tags'            => ['SINUXYL', strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell'           => false,
            'status_code'         => 1,
            'pancake_inserted_at' => now()->subDay(),   // created yesterday
            'pancake_created_at'  => now(),              // but worked today
            'synced_at'           => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['total_leads'] === 0);
    }

    public function test_an_order_created_today_but_worked_yesterday_still_counts_in_todays_total_leads(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        Order::create([
            'pancake_order_id'    => 'backlog-2',
            'team'                => 'SH Naturals',
            'tsa_name'            => $shift->tsa_key,
            'disposition'         => 'CONFIRMED VIA CALL',
            'product'             => 'Sinuxyl',
            'raw_tags'            => ['SINUXYL', strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell'           => false,
            'status_code'         => 1,
            'pancake_inserted_at' => now(),              // created today
            'pancake_created_at'  => now()->subDay(),    // but somehow tagged yesterday
            'synced_at'           => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['total_leads'] === 1);
    }

    public function test_a_pre_backfill_order_with_no_pancake_inserted_at_falls_back_to_worked_at(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        Order::create([
            'pancake_order_id'    => 'legacy-1',
            'team'                => 'SH Naturals',
            'tsa_name'            => $shift->tsa_key,
            'disposition'         => 'CONFIRMED VIA CALL',
            'product'             => 'Sinuxyl',
            'raw_tags'            => ['SINUXYL', strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell'           => false,
            'status_code'         => 1,
            'pancake_inserted_at' => null,   // never backfilled
            'pancake_created_at'  => now(),
            'synced_at'           => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['total_leads'] === 1);
    }
}
