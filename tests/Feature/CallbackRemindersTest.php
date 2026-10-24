<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Smart callback reminders (2026-10-24). */
class CallbackRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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
