<?php

namespace Tests\Feature;

use App\Models\CallEvent;
use App\Models\Lead;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\AutoCall;
use App\Support\MissedCalls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Missed-call callback queue (2026-11-14). */
class MissedCallsTest extends TestCase
{
    use RefreshDatabase;

    private TsaShift $gemma;

    private TsaShift $julie;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $this->julie = TsaShift::where('tsa_key', 'Julie')->first();
    }

    private function lead(string $phone, ?TsaShift $tsa = null, array $extra = []): Lead
    {
        return Lead::create(array_merge([
            'pancake_order_id' => (string) random_int(100000, 999999), 'customer_name' => 'Juan', 'phone_number' => $phone,
            'product_id' => Product::first()->id, 'tsa_id' => $tsa?->id, 'status' => $tsa ? 'called' : 'unassigned',
            'pancake_created_at' => now()->subHours(3), 'dialed_at' => now()->subHours(2),
        ], $extra));
    }

    private function phoneCall(TsaShift $tsa, string $phone, string $direction, $at): CallEvent
    {
        return CallEvent::create(['tsa_id' => $tsa->id, 'phone_number' => $phone, 'direction' => $direction, 'occurred_at' => $at]);
    }

    public function test_a_missed_call_waits_until_the_number_is_called_back(): void
    {
        $lead = $this->lead('0917 123 4567', $this->gemma);
        $this->phoneCall($this->gemma, '+639171234567', 'missed', now()->subMinutes(30));
        $this->phoneCall($this->gemma, '09171234567', 'missed', now()->subMinutes(10));

        $pending = MissedCalls::pending();
        $this->assertCount(1, $pending);
        $this->assertSame($lead->id, $pending[0]['lead']->id);
        $this->assertSame(2, $pending[0]['count']);

        $this->phoneCall($this->gemma, '09171234567', 'outgoing', now()->subMinutes(5));
        $this->assertSame([], MissedCalls::leadIds());
    }

    public function test_unknown_numbers_and_old_missed_calls_are_left_out(): void
    {
        $this->lead('09171234567', $this->gemma);
        $this->phoneCall($this->gemma, '09998887777', 'missed', now()->subMinutes(10)); // not a customer
        $this->phoneCall($this->gemma, '09171234567', 'missed', now()->subDays(MissedCalls::DAYS + 1));

        $this->assertSame([], MissedCalls::leadIds());
    }

    public function test_a_tsa_sees_calls_on_her_own_phone_and_can_open_the_lead(): void
    {
        $lead = $this->lead('09171234567', $this->julie); // Julie's lead, but the customer rang Gemma
        $this->phoneCall($this->gemma, '09171234567', 'missed', now()->subMinutes(10));
        $gemmaUser = User::factory()->create(['role' => 'tsa', 'tsa_id' => $this->gemma->id]);
        $julieUser = User::factory()->create(['role' => 'tsa', 'tsa_id' => $this->julie->id]);

        $page = $this->actingAs($gemmaUser)->get(route('calls.leads.index', ['view' => 'missed_calls']))->assertOk();
        $this->assertSame([$lead->id], $page->viewData('leads')->pluck('id')->all());
        $this->actingAs($gemmaUser)->getJson(route('calls.notifications.counts'))->assertJsonPath('missed_calls', 1);
        $this->actingAs($julieUser)->getJson(route('calls.notifications.counts'))->assertJsonPath('missed_calls', 0);

        $this->actingAs($gemmaUser)->postJson(route('calls.leads.call-click', $lead))->assertOk();
        $this->assertSame([], MissedCalls::leadIds()); // the dial cleared it
    }

    public function test_auto_call_dials_the_missed_caller_first(): void
    {
        \App\Models\Setting::set('auto_call_enabled', true);
        $this->gemma->update(['status' => 'ready_to_call', 'dialer_host' => '192.168.1.5:8080', 'active' => true]);
        $this->lead('09170000000', $this->gemma, ['status' => 'assigned', 'assigned_at' => now(), 'dialed_at' => null, 'pancake_created_at' => now()]);
        $caller = $this->lead('09171234567', $this->gemma);
        $this->phoneCall($this->gemma, '09171234567', 'missed', now()->subMinutes(10));

        $this->assertSame($caller->id, AutoCall::next($this->gemma->fresh())->id);
    }
}
