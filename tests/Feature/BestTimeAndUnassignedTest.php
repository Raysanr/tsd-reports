<?php

namespace Tests\Feature;

use App\Models\CallEvent;
use App\Models\Lead;
use App\Models\LeadActivity;
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
        $at = fn (int $hour, int $min = 0) => $day->copy()->setTime($hour, $min);
        $result = fn (Lead $l, string $r, int $hour, int $min = 0) => LeadCallResult::create(['lead_id' => $l->id, 'result' => $r, 'occurred_at' => $at($hour, $min)]);
        // A click-to-call: the activity plus the outgoing call event it logs (one dial).
        $dial = function (Lead $l, int $hour, int $min = 0) use ($at) {
            LeadActivity::create(['lead_id' => $l->id, 'type' => 'call_clicked', 'description' => 'clicked to call', 'created_at' => $at($hour, $min)]);
            CallEvent::create(['tsa_id' => TsaShift::first()->id, 'lead_id' => $l->id, 'phone_number' => '0917', 'direction' => 'outgoing', 'occurred_at' => $at($hour, $min)->addMinute()]);
        };

        // 10 AM: 10 dials, 8 answered. 2 PM: 10 dials, 3 answered. 6 AM: 1 dial, answered (too few to rank).
        foreach (range(1, 10) as $i) {
            $l = $lead(); $dial($l, 10); $result($l, $i <= 8 ? 'CONFIRMED VIA CALL' : 'NOT ANSWERING', 10, 5);
            $l = $lead(); $dial($l, 14); $result($l, $i <= 3 ? 'CALL BACK' : 'UNATTENDED', 14, 5);
        }
        $l = $lead(); $dial($l, 6); $result($l, 'CONFIRMED VIA CALL', 6, 5);

        // Retries: tagged Not answering at 9 AM; called again at 11 AM with no new
        // tag (still unanswered — invisible in tag history), then answered at 7 PM.
        $retry = $lead();
        $dial($retry, 9); $result($retry, 'NOT ANSWERING', 9, 5);
        $dial($retry, 11);
        $dial($retry, 19); $result($retry, 'CONFIRMED VIA CALL', 19, 5);
        // Invalid number is left out.
        $l = $lead(); $dial($l, 10, 30); $result($l, 'INVALID NUMBER', 10, 35);

        $best = BestTimeToCall::build($day->copy()->startOfDay(), $day->copy()->endOfDay());

        $this->assertSame(24, $best['calls']);
        $this->assertSame([HourFormatter::label(10), HourFormatter::label(14)], array_column($best['best'], 'label'));
        $this->assertEquals(80, $best['best'][0]['rate']);
        $eleven = collect($best['rows'])->firstWhere('hour', 11);
        $this->assertSame([1, 0], [$eleven['retryCalls'], $eleven['retryAnswered']]); // failed retry counted
        $seven = collect($best['rows'])->firstWhere('hour', 19);
        $this->assertSame([1, 1], [$seven['retryCalls'], $seven['retryAnswered']]);
        $this->assertSame([], $best['bestRetry']); // under MIN_CALLS retries

        Order::create(['pancake_order_id' => 'o1', 'team' => 'SH Naturals', 'product' => 'Sinuxyl', 'raw_tags' => [], 'is_upsell' => false,
            'status_code' => 1, 'pancake_created_at' => '2026-11-10 10:00:00', 'synced_at' => now()]); // the page needs data in range
        $this->actingAs(User::factory()->create())
            ->get(route('charts', ['date_from' => '2026-11-10', 'date_to' => '2026-11-10']))
            ->assertOk()->assertSee('Best Time to Call')->assertSee('bestTimeChart');
    }

    public function test_phone_calls_count_by_number_and_a_connected_call_with_no_tag_is_answered(): void
    {
        $day = Carbon::parse('2026-11-10', 'Asia/Manila');
        $tsa = TsaShift::first()->id;
        $lead = Lead::create(['pancake_order_id' => '777', 'product_id' => Product::first()->id, 'status' => 'called',
            'phone_number' => '0917 123 4567', 'pancake_created_at' => $day->copy()->setTime(8, 0)]);
        $phone = fn (int $hour, int $min, int $seconds) => CallEvent::create(['tsa_id' => $tsa, 'lead_id' => null,
            'phone_number' => '+639171234567', 'direction' => 'outgoing', 'duration_seconds' => $seconds,
            'occurred_at' => $day->copy()->setTime($hour, $min)->addSeconds($seconds)]); // reported when it ends

        $phone(9, 0, 0);    // dialed from the phone, nobody picked up
        $phone(15, 0, 300); // 5-minute call, no new tag (the TSA forgot)
        // Click-to-call at 17:00 plus the phone's end-of-call report 4 minutes later: one dial.
        LeadActivity::create(['lead_id' => $lead->id, 'type' => 'call_clicked', 'description' => 'clicked', 'created_at' => $day->copy()->setTime(17, 0)]);
        $phone(17, 0, 240);

        $rows = collect(BestTimeToCall::build($day->copy()->startOfDay(), $day->copy()->endOfDay())['rows'])->keyBy('hour');

        $this->assertSame([1, 0], [$rows[9]['calls'], $rows[9]['answered']]);
        $this->assertSame([1, 1, 1, 1], [$rows[15]['calls'], $rows[15]['answered'], $rows[15]['retryCalls'], $rows[15]['retryAnswered']]);
        $this->assertSame([1, 1], [$rows[17]['calls'], $rows[17]['answered']]);
        $this->assertSame(3, $rows->sum('calls'));
    }

    public function test_with_no_tag_history_the_final_result_decides_the_last_dial(): void
    {
        $day = Carbon::parse('2026-11-10', 'Asia/Manila');
        $click = fn (Lead $l, int $hour) => LeadActivity::create(['lead_id' => $l->id, 'type' => 'call_clicked', 'description' => 'clicked', 'created_at' => $day->copy()->setTime($hour, 0)]);
        $lead = fn (string $id, string $disposition) => Lead::create(['pancake_order_id' => $id, 'product_id' => Product::first()->id,
            'status' => 'called', 'disposition' => $disposition, 'pancake_created_at' => $day->copy()->setTime(7, 0)]);

        $confirmed = $lead('901', 'CONFIRMED VIA CALL'); // dialed at 9 and 13, no tag history
        $click($confirmed, 9);
        $click($confirmed, 13);
        $never = $lead('902', 'NOT ANSWERING');
        $click($never, 9);
        $invalid = $lead('903', 'INVALID NUMBER');
        $click($invalid, 9);

        $rows = collect(BestTimeToCall::build($day->copy()->startOfDay(), $day->copy()->endOfDay())['rows'])->keyBy('hour');

        $this->assertSame([2, 0], [$rows[9]['calls'], $rows[9]['answered']]);          // invalid left out
        $this->assertSame([1, 1, 1, 1], [$rows[13]['calls'], $rows[13]['answered'], $rows[13]['retryCalls'], $rows[13]['retryAnswered']]);
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
