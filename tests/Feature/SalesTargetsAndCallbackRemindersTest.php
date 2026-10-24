<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Order;
use App\Models\ProjectionColumn;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\SalesTargets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** "This month vs target" + smart callback reminders (2026-10-24). */
class SalesTargetsAndCallbackRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(TsaShift $tsa, float $amount, string $date): void
    {
        Order::create([
            'pancake_order_id' => (string) random_int(100000, 999999), 'team' => $tsa->team, 'tsa_name' => $tsa->tsa_key,
            'product' => 'CANPRO', 'is_upsell' => true, 'amount' => $amount, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);
    }

    /** Net income vs Projections' Net income target (2026-10-24: "their net
     *  income every tsa should be 100,000 so it is their target"). */
    public function test_target_tracker_uses_projections_net_income_targets(): void
    {
        Carbon::setTestNow('2026-10-11 09:00:00'); // 10 full days done of 31
        ProjectionColumn::ensureSeededForMonth('2026-10');
        $plan = SalesTargets::projectionTargets('2026-10');
        $this->assertSame(100000.0, $plan['opening']['tsa']);
        $this->assertSame(600000.0, $plan['opening']['team']);
        $this->assertSame(100000.0, $plan['closing']['tsa']);

        $tsa = TsaShift::where('active', true)->where('is_hidden', false)->where('team', 'SH Naturals')->first(); // Team Opening by default
        $this->sale($tsa, 20000, '2026-10-03');

        $data = SalesTargets::forMonth('2026-10');
        $row = $data['tsas']->firstWhere('id', $tsa->id);
        $expectedNet = (float) (\App\Support\LiveTsaBoard::monthFigures(Carbon::parse('2026-10-01'))[$tsa->id]['net'] ?? 0);
        $this->assertSame(100000.0, $row['target']);
        $this->assertEqualsWithDelta($expectedNet, $row['actual'], 0.01);   // same net income as the Scoreboard
        $this->assertSame(21, $data['daysLeft']);                             // Oct 11–31
        $this->assertSame(600000.0, $data['teams']->firstWhere('order_team', 'SH Naturals')['target']);

        // status follows net income vs where they should be today
        $this->assertSame($expectedNet / 100000 >= (10 / 31) - 0.02 ? 'on_track' : 'behind', $row['status']);

        // only on the Leads Reports Dashboard — not in Call Tracker
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('dashboard'))->assertOk()->assertSee('This month vs target')->assertDontSee('Set targets');
        $tsaUser = User::create(['name' => 'TSA U', 'email' => 't@x.test', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $tsa->id]);
        $this->actingAs($tsaUser)->get(route('calls.leads.index'))->assertOk()->assertDontSee('My target');

        // a later month without its own Projections uses the latest earlier one
        $this->assertSame($plan, SalesTargets::projectionTargets('2026-12'));
    }

    public function test_callback_reminders_list_the_tsas_due_callbacks_and_snooze_them(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $user = User::create(['name' => 'Gemma U', 'email' => 'g@x.test', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);
        $lead = fn (TsaShift $t, $at, $name) => Lead::create(['pancake_order_id' => (string) random_int(100000, 999999), 'customer_name' => $name, 'tsa_id' => $t->id, 'status' => 'assigned', 'callback_at' => $at]);
        $due = $lead($gemma, now()->subMinutes(2), 'Due Now');
        $lead($gemma, now()->addMinutes(3), 'Soon');
        $lead($gemma, now()->addHour(), 'Later');          // not yet
        $lead($julie, now()->subMinute(), 'Not Mine');      // someone else's

        $list = $this->actingAs($user)->getJson(route('calls.callback-reminders'))->assertOk()->json('reminders');
        $this->assertSame(['Due Now', 'Soon'], array_column($list, 'customer'));
        $this->assertTrue($list[0]['due']);
        $this->assertFalse($list[1]['due']);

        $this->postJson(route('calls.leads.callback-snooze', $due), ['minutes' => 10])->assertOk();
        $this->assertTrue($due->fresh()->callback_at->greaterThan(now()->addMinutes(9)));
        // can't snooze another TSA's lead
        $other = Lead::where('customer_name', 'Not Mine')->first();
        $this->postJson(route('calls.leads.callback-snooze', $other), ['minutes' => 10])->assertForbidden();
        // the layout only polls for a TSA
        $this->get(route('calls.leads.index'))->assertSee('name="callback-reminders"', false);
    }
}
