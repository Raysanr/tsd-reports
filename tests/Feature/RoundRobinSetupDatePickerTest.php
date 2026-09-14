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
 * volume. Added a date picker ("like the Dashboard"), then upgraded the same
 * day to a real two-calendar range ("can select multiple dates... 2 calendar
 * like in the dashboard") — TsaShift::leadsAssignedBetween() is a NEW,
 * separate method from leadsAssignedToday(), which stays hardcoded to the
 * real today since that's what RoundRobinAssigner actually enforces and must
 * never be pointed at a different day/range just because this page's picker
 * is.
 *
 * Fixtures set pancake_created_at (not just assigned_at) as of 2026-09-14 —
 * both leadsAssignedToday()/leadsAssignedBetween() now count by
 * pancake_created_at instead of assigned_at (explicit report: "for example
 * this today like is has 8 leads, it should be 8/75 right?" — a mass-dump
 * incident that same day had left weeks-old backlog orders counted as
 * "today" purely because their assigned_at got stamped today; see
 * TsaShift::leadsAssignedToday()'s own comment for the full story).
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
        Lead::create(['pancake_order_id' => 'today-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now(), 'pancake_created_at' => now()]);
        Lead::create(['pancake_order_id' => 'yesterday-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()->subDay(), 'pancake_created_at' => now()->subDay()]);

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
        Lead::create(['pancake_order_id' => 'past-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => $threeDaysAgo, 'pancake_created_at' => $threeDaysAgo]);
        Lead::create(['pancake_order_id' => 'past-2', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => $threeDaysAgo->copy()->addHour(), 'pancake_created_at' => $threeDaysAgo->copy()->addHour()]);
        Lead::create(['pancake_order_id' => 'today-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now(), 'pancake_created_at' => now()]);

        $response = $this->actingAs($this->admin())->get(route('calls.round-robin-setup', [
            'date_from' => $threeDaysAgo->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee('Assigned — ' . $threeDaysAgo->format('M j, Y'));
        $tsas = collect($response->viewData('tsas'));
        $this->assertSame(2, $tsas->firstWhere('tsa.tsa_key', 'Gemma')['assigned_today']);
    }

    public function test_a_picked_range_sums_the_whole_span_not_just_one_day(): void
    {
        $gemma      = TsaShift::where('tsa_key', 'Gemma')->first();
        $product    = Product::where('display_name', 'SINUXYL')->first();
        $rangeStart = now()->subDays(3);
        $rangeEnd   = now()->subDays(1);
        Lead::create(['pancake_order_id' => 'range-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => $rangeStart, 'pancake_created_at' => $rangeStart]);
        Lead::create(['pancake_order_id' => 'range-2', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => $rangeStart->copy()->addDay(), 'pancake_created_at' => $rangeStart->copy()->addDay()]);
        Lead::create(['pancake_order_id' => 'range-3', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => $rangeEnd, 'pancake_created_at' => $rangeEnd]);
        // Outside the picked range on both ends — must not be counted.
        Lead::create(['pancake_order_id' => 'before-range', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => $rangeStart->copy()->subDay(), 'pancake_created_at' => $rangeStart->copy()->subDay()]);
        Lead::create(['pancake_order_id' => 'today-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now(), 'pancake_created_at' => now()]);

        $response = $this->actingAs($this->admin())->get(route('calls.round-robin-setup', [
            'date_from' => $rangeStart->toDateString(), 'date_to' => $rangeEnd->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee('Assigned — ' . $rangeStart->format('M j') . ' to ' . $rangeEnd->format('M j, Y'));
        $tsas = collect($response->viewData('tsas'));
        $this->assertSame(3, $tsas->firstWhere('tsa.tsa_key', 'Gemma')['assigned_today']);
    }

    public function test_the_picked_date_never_affects_live_cap_enforcement(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['daily_lead_cap' => 1]);
        $product = Product::where('display_name', 'SINUXYL')->first();
        // 5 leads on a past date — if the picker's date leaked into
        // hasReachedDailyCap(), Gemma would wrongly read as still open today.
        for ($i = 0; $i < 5; $i++) {
            Lead::create(['pancake_order_id' => "past-{$i}", 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()->subDays(3), 'pancake_created_at' => now()->subDays(3)]);
        }
        Lead::create(['pancake_order_id' => 'today-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now(), 'pancake_created_at' => now()]);

        $this->actingAs($this->admin())->get(route('calls.round-robin-setup', ['date_from' => now()->subDays(3)->toDateString()]));

        $this->assertTrue($gemma->fresh()->hasReachedDailyCap());
    }

    /**
     * Regression test, 2026-09-14: "for example this today like is has 8
     * leads, it should be 8/75 right?" — Lika's real Leads page showed 8
     * genuine today-created leads, but Leads Setup showed "302/75" because
     * the OLD assigned_at-based count included 294 weeks-old backlog
     * orders her catch-up sweep had assigned to her that same day (see
     * RoundRobinAssigner's own comment for that incident). A lead created
     * long ago that only got assigned_at stamped today must NOT count
     * toward "Assigned today" or the cap — only a lead whose underlying
     * order is genuinely from today should.
     */
    public function test_an_old_order_only_assigned_today_does_not_count_toward_assigned_today(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        Lead::create([
            'pancake_order_id' => 'old-order', 'product_id' => $product->id, 'tsa_id' => $gemma->id,
            'status' => 'assigned', 'assigned_at' => now(), 'pancake_created_at' => now()->subDays(30),
        ]);
        Lead::create([
            'pancake_order_id' => 'fresh-order', 'product_id' => $product->id, 'tsa_id' => $gemma->id,
            'status' => 'assigned', 'assigned_at' => now(), 'pancake_created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get(route('calls.round-robin-setup'));

        $response->assertOk();
        $tsas = collect($response->viewData('tsas'));
        $this->assertSame(1, $tsas->firstWhere('tsa.tsa_key', 'Gemma')['assigned_today']);
    }
}
