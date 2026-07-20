<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Leads Report filters/buckets by the order's real creation date
 * (pancake_inserted_at) so its daily totals match what POS's own Created-At
 * filter shows — NOT pancake_created_at, which is business-adjusted to "when
 * a TSA actually worked this" (see SyncTodayOrders::resolveWorkedAt()) and can
 * land on a different calendar day for a backlog lead. TSA Performance/Charts
 * intentionally keep reading pancake_created_at directly instead.
 */
class LeadsReportEffectiveDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_an_order_created_yesterday_but_worked_today_is_NOT_in_todays_leads_report(): void
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
        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('currentOrders', fn($orders) => $orders->isEmpty());
    }

    public function test_an_order_created_today_but_worked_yesterday_still_shows_in_todays_leads_report(): void
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
        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('currentOrders', fn($orders) => $orders->contains(
            fn($o) => $o->pancake_order_id === 'backlog-2'
        ));
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
        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('currentOrders', fn($orders) => $orders->contains(
            fn($o) => $o->pancake_order_id === 'legacy-1'
        ));
    }
}
