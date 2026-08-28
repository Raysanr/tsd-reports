<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Root-caused 2026-08-28: DashboardController::index() used to load and
 * match every order across the WHOLE selected range in one pass; a wide
 * range (e.g. 27 days) crashed the PHP process outright. Fixed by computing
 * the Grand Total one calendar day at a time and summing (see that method's
 * own comment). This test proves the day-bucketed sum is IDENTICAL to what
 * a single whole-range pass would produce, across a range wide enough to
 * span multiple days — including a cross-team combo order, which is the
 * one case a naive per-day split could plausibly double-count or drop if
 * the bucketing were wrong.
 */
class DashboardTotalLeadsMultiDayBucketingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_total_leads_across_a_multi_day_range_equals_the_sum_of_each_days_own_total(): void
    {
        $shShift  = TsaShift::where('team', 'SH Naturals')->first();
        $eyeShift = TsaShift::where('team', 'Eyecare Team')->first();

        $dayOne = now()->subDays(2);
        $dayTwo = now()->subDay();

        Order::create([
            'pancake_order_id' => 'day-one-plain', 'team' => 'SH Naturals', 'tsa_name' => $shShift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Sinuxyl',
            'raw_tags' => [strtoupper($shShift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => $dayOne, 'synced_at' => now(),
        ]);

        // Cross-team combo landing on a DIFFERENT day than the plain order
        // above — counts once under PTERYGIUM and once under SINUXYL (see
        // DashboardTotalLeadsMatchesLeadsReportTest's own combo case), so a
        // day-bucketed pass must still find it on day two and add 2, not 0
        // or 1 from a boundary mistake.
        Order::create([
            'pancake_order_id' => 'day-two-combo', 'team' => 'Eyecare Team', 'tsa_name' => $eyeShift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Pterygium',
            'bundle_description' => '10 Pterygium Drops + 10 Sinuxyl',
            'raw_tags' => [strtoupper($eyeShift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => $dayTwo, 'synced_at' => now(),
        ]);

        $range = $this->get(route('dashboard', [
            'date_from' => $dayOne->toDateString(),
            'date_to'   => $dayTwo->toDateString(),
            'team'      => 'all',
        ]));
        $dayOneOnly = $this->get(route('dashboard', [
            'date_from' => $dayOne->toDateString(),
            'date_to'   => $dayOne->toDateString(),
            'team'      => 'all',
        ]));
        $dayTwoOnly = $this->get(route('dashboard', [
            'date_from' => $dayTwo->toDateString(),
            'date_to'   => $dayTwo->toDateString(),
            'team'      => 'all',
        ]));

        $range->assertOk();

        $rangeTotal   = $range->viewData('stats')['total_leads'];
        $dayOneTotal  = $dayOneOnly->viewData('stats')['total_leads'];
        $dayTwoTotal  = $dayTwoOnly->viewData('stats')['total_leads'];

        $this->assertSame(3, $rangeTotal); // 1 (day one) + 2 (day two's combo)
        $this->assertSame($rangeTotal, $dayOneTotal + $dayTwoTotal);
    }
}
