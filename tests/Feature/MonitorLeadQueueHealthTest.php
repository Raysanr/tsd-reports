<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-21): give Monitor TSA visibility into lead-queue
 * health, not just TSA status/time — it previously had zero way to tell a
 * supervisor whether any TSA actually had leads piling up. Overdue/callback
 * counts reuse the exact same definitions LeadController's own Overdue/
 * Callbacks views and NotificationController's sidebar badges already use
 * (see MonitorController::index()'s own comment), so these numbers can
 * never drift from what a TSA's own Leads page shows.
 */
class MonitorLeadQueueHealthTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_shows_a_tsas_overdue_and_callback_counts_on_their_own_card(): void
    {
        Setting::set('overdue_threshold_hours', 4);
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        // Overdue: assigned, uncalled, past the threshold.
        Lead::create([
            'pancake_order_id' => 'overdue-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id,
            'status' => 'assigned', 'assigned_at' => now()->subHours(5),
        ]);
        // A due-now callback.
        Lead::create([
            'pancake_order_id' => 'callback-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id,
            'status' => 'called', 'called_at' => now()->subHours(2), 'callback_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($this->admin())->get(route('calls.monitor'));

        $response->assertOk();
        $response->assertSee('1 Overdue', false);
        $response->assertSee('1 Callback Due', false);
    }

    public function test_a_tsa_with_no_overdue_or_callback_leads_shows_no_pills(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        // Assigned, but well within the threshold — not overdue.
        Lead::create([
            'pancake_order_id' => 'fresh-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id,
            'status' => 'assigned', 'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get(route('calls.monitor'));

        $response->assertOk();
        // The team-wide "Overdue Leads"/"Callbacks Due" summary tiles always
        // render (0 is still a valid count to show there) — it's only the
        // per-TSA pill, identified by its own bg class, that must be absent
        // when a TSA has nothing to flag.
        $response->assertDontSee('bg-red-50 dark:bg-red-950/30', false);
        $response->assertDontSee('bg-orange-50 dark:bg-orange-950/30', false);
    }

    public function test_unassigned_leads_show_on_a_team_wide_summary_tile(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create([
            'pancake_order_id' => 'unassigned-1', 'product_id' => $product->id, 'tsa_id' => null,
            'status' => 'unassigned', 'pancake_created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get(route('calls.monitor', ['team' => 'sh-naturals']));

        $response->assertOk();
        $unassignedCount = $response->viewData('unassignedLeadsCount');
        $this->assertSame(1, $unassignedCount);
    }

    public function test_unassigned_count_is_team_scoped_via_the_leads_own_product(): void
    {
        $shProduct  = Product::where('display_name', 'SINUXYL')->first();
        $eyeProduct = Product::where('display_name', 'PTERYGIUM')->first();

        Lead::create(['pancake_order_id' => 'sh-unassigned', 'product_id' => $shProduct->id, 'tsa_id' => null, 'status' => 'unassigned', 'pancake_created_at' => now()]);
        Lead::create(['pancake_order_id' => 'eye-unassigned', 'product_id' => $eyeProduct->id, 'tsa_id' => null, 'status' => 'unassigned', 'pancake_created_at' => now()]);

        $response = $this->actingAs($this->admin())->get(route('calls.monitor', ['team' => 'sh-naturals']));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('unassignedLeadsCount'));
    }

    public function test_lead_counts_use_the_pages_own_date_range_not_always_today(): void
    {
        Setting::set('overdue_threshold_hours', 4);
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        $pastDate = now()->subDays(3);
        Lead::create([
            'pancake_order_id' => 'past-overdue-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id,
            'status' => 'assigned', 'assigned_at' => $pastDate->copy()->subHours(5),
        ]);

        $response = $this->actingAs($this->admin())->get(route('calls.monitor', [
            'date_from' => $pastDate->toDateString(), 'date_to' => $pastDate->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee('1 Overdue', false);
    }
}
