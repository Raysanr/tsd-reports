<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-24): Leads Setup's "Assigned today" column only
 * ever showed the real today, with no way to review a past day's assignment
 * volume. Added a date picker ("like the Dashboard"), reusing TsaShift's
 * existing leadsAssignedOn() — a NEW, separate method from
 * leadsAssignedToday(), which stays hardcoded to the real today since that's
 * what RoundRobinAssigner actually enforces and must never be pointed at a
 * different day just because this page's picker is.
 */
class RoundRobinSetupDatePickerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_defaults_to_todays_assigned_count_with_no_date_param(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        Lead::create(['pancake_order_id' => 'today-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()]);
        Lead::create(['pancake_order_id' => 'yesterday-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()->subDay()]);

        $response = $this->actingAs($this->admin())->get(route('calls.round-robin-setup'));

        $response->assertOk();
        $response->assertSee('Assigned today');
        $tsas = collect($response->viewData('tsas'));
        $this->assertSame(1, $tsas->firstWhere('tsa.tsa_key', 'Gemma')['assigned_today']);
    }

    public function test_a_picked_past_date_shows_that_days_assigned_count_instead(): void
    {
        $gemma      = TsaShift::where('tsa_key', 'Gemma')->first();
        $product    = Product::where('display_name', 'SINUXYL')->first();
        $threeDaysAgo = now()->subDays(3);
        Lead::create(['pancake_order_id' => 'past-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => $threeDaysAgo]);
        Lead::create(['pancake_order_id' => 'past-2', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => $threeDaysAgo->copy()->addHour()]);
        Lead::create(['pancake_order_id' => 'today-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()]);

        $response = $this->actingAs($this->admin())->get(route('calls.round-robin-setup', [
            'date_from' => $threeDaysAgo->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee('Assigned — ' . $threeDaysAgo->format('M j, Y'));
        $tsas = collect($response->viewData('tsas'));
        $this->assertSame(2, $tsas->firstWhere('tsa.tsa_key', 'Gemma')['assigned_today']);
    }

    public function test_the_picked_date_never_affects_live_cap_enforcement(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['daily_lead_cap' => 1]);
        $product = Product::where('display_name', 'SINUXYL')->first();
        // 5 leads on a past date — if the picker's date leaked into
        // hasReachedDailyCap(), Gemma would wrongly read as still open today.
        for ($i = 0; $i < 5; $i++) {
            Lead::create(['pancake_order_id' => "past-{$i}", 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()->subDays(3)]);
        }
        Lead::create(['pancake_order_id' => 'today-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()]);

        $this->actingAs($this->admin())->get(route('calls.round-robin-setup', ['date_from' => now()->subDays(3)->toDateString()]));

        $this->assertTrue($gemma->fresh()->hasReachedDailyCap());
    }
}
