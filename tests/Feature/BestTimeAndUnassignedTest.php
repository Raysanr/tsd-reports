<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadCallResult;
use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\BestTimeToCall;
use App\Support\HourFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Analytics' Best Time tab and the Dashboard's unassigned leads alert (2026-11-14). */
class BestTimeAndUnassignedTest extends TestCase
{
    use RefreshDatabase;

    public function test_best_time_ranks_hours_by_answer_rate_and_counts_retries(): void
    {
        $day = Carbon::parse('2026-11-10', 'Asia/Manila');
        $lead = fn () => Lead::create(['pancake_order_id' => (string) random_int(100000, 999999), 'product_id' => Product::first()->id, 'status' => 'called', 'pancake_created_at' => $day]);
        $result = fn (Lead $l, string $r, int $hour, int $min = 0) => LeadCallResult::create(['lead_id' => $l->id, 'result' => $r, 'occurred_at' => $day->copy()->setTime($hour, $min)]);

        // 10 AM: 10 calls, 8 answered. 2 PM: 10 calls, 3 answered. 6 AM: 1 call, answered (too few to rank).
        foreach (range(1, 10) as $i) {
            $result($lead(), $i <= 8 ? 'CONFIRMED VIA CALL' : 'NOT ANSWERING', 10);
            $result($lead(), $i <= 3 ? 'CALL BACK' : 'UNATTENDED', 14);
        }
        $result($lead(), 'CONFIRMED VIA CALL', 6);
        // A retry: unanswered at 9 AM, answered at 7 PM. Invalid number is ignored.
        $retry = $lead();
        $result($retry, 'NOT ANSWERING', 9);
        $result($retry, 'CONFIRMED VIA CALL', 19);
        $result($lead(), 'INVALID NUMBER', 10, 30);

        $best = BestTimeToCall::build($day->copy()->startOfDay(), $day->copy()->endOfDay());

        $this->assertSame(23, $best['calls']);
        $this->assertSame([HourFormatter::label(10), HourFormatter::label(14)], array_column($best['best'], 'label'));
        $this->assertEquals(80, $best['best'][0]['rate']);
        $seven = collect($best['rows'])->firstWhere('hour', 19);
        $this->assertSame([1, 1], [$seven['retryCalls'], $seven['retryAnswered']]);
        $this->assertSame([], $best['bestRetry']); // under MIN_CALLS retries

        Order::create(['pancake_order_id' => 'o1', 'team' => 'SH Naturals', 'product' => 'Sinuxyl', 'raw_tags' => [], 'is_upsell' => false,
            'status_code' => 1, 'pancake_created_at' => '2026-11-10 10:00:00', 'synced_at' => now()]); // the page needs data in range
        $this->actingAs(User::factory()->create())
            ->get(route('charts', ['date_from' => '2026-11-10', 'date_to' => '2026-11-10']))
            ->assertOk()->assertSee('Best Time to Call')->assertSee('bestTimeChart');
    }

    public function test_dashboard_alerts_admins_about_leads_with_no_tsa_and_why(): void
    {
        $tsa = TsaShift::where('team', 'SH Naturals')->firstOrFail();
        $order = fn (string $id, ?string $tsaName, array $extra = []) => Order::create($extra + [
            'pancake_order_id' => $id, 'team' => 'SH Naturals', 'tsa_name' => $tsaName, 'product' => 'Sinuxyl',
            'raw_tags' => [], 'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);
        $lead = fn (string $id, ?TsaShift $t) => Lead::create(['pancake_order_id' => $id, 'product_id' => Product::first()->id,
            'tsa_id' => $t?->id, 'status' => $t ? 'assigned' : 'unassigned', 'pancake_created_at' => now()]);

        $order('owned', $tsa->tsa_key);
        $order('waiting', null);       $lead('waiting', null);
        $order('untagged', null);      $lead('untagged', $tsa);
        $order('no-lead', '');
        $order('deleted', null, ['status_code' => 7]);

        $admin = User::factory()->create(['role' => 'admin']);
        $alert = $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('3 leads in this range have no TSA')->assertSee('Assign them in Call Tracker')
            ->viewData('unassignedLeads');
        $this->assertSame(['total' => 3, 'waiting' => 1, 'notTagged' => 1, 'notInTracker' => 1],
            array_intersect_key($alert, array_flip(['total', 'waiting', 'notTagged', 'notInTracker'])));

        $this->actingAs(User::factory()->create(['role' => 'normal']))->get(route('dashboard'))
            ->assertOk()->assertDontSee('have no TSA');
    }
}
