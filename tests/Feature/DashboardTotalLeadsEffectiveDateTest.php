<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-08): Dashboard's "Total Leads"/Pick-up Rate/
 * Upselling Rate must tally with TSA Performance's Grand Total, not Leads
 * Report's — reversing an earlier fix. Confirmed live that using Leads
 * Report's COALESCE(pancake_inserted_at, pancake_created_at) convention made
 * Dashboard disagree with TSA Performance's Grand Total (which has always
 * filtered by pancake_created_at/worked-at time alone) on any day with a
 * backlog lead — created one day, worked the next. Dashboard now filters the
 * same way TSA Performance always has: pancake_created_at only, no COALESCE.
 */
class DashboardTotalLeadsEffectiveDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_an_order_created_yesterday_but_worked_today_counts_in_todays_total_leads(): void
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
        $response->assertViewHas('stats', fn ($stats) => $stats['total_leads'] === 1);
    }

    public function test_an_order_created_today_but_worked_yesterday_does_not_count_in_todays_total_leads(): void
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
            'pancake_created_at'  => now()->subDay(),    // but worked yesterday
            'synced_at'           => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['total_leads'] === 0);
    }

    public function test_a_pre_backfill_order_with_no_pancake_inserted_at_still_counts_by_worked_at(): void
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
            'pancake_inserted_at' => null,   // never backfilled — irrelevant now, pancake_created_at is the only signal
            'pancake_created_at'  => now(),
            'synced_at'           => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['total_leads'] === 1);
    }
}
