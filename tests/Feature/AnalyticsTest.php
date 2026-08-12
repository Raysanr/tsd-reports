<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift, routes -> calls.*.
 *
 * NOTE: this file wasn't explicitly named in the Phase 9 plan's port list
 * (only Analytics*Controller* was named as ported in Phase 2) — porting it
 * anyway since AnalyticsController genuinely was ported and had zero test
 * coverage otherwise. No filename collision with any existing tsd-reports
 * test.
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tsa_cannot_reach_analytics(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->get(route('calls.analytics'))->assertForbidden();
    }

    public function test_it_computes_confirm_rate_no_answer_rate_and_average_response_time(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        // 2 confirmed, 1 not answering, 1 still assigned (not called) — all
        // assigned "today" so they fall inside the default date range.
        // 'Not Answering' (not 'No Answer' — see AnalyticsController's own
        // comment, 2026-08-12: 'No Answer' isn't a real disposition this
        // app's own Outcome picker or Pancake tag catalog ever produces).
        Lead::create(['pancake_order_id' => 'a1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'called', 'disposition' => 'Confirmed', 'assigned_at' => now()->subMinutes(30), 'called_at' => now()->subMinutes(20)]);
        Lead::create(['pancake_order_id' => 'a2', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'called', 'disposition' => 'Confirmed', 'assigned_at' => now()->subMinutes(20), 'called_at' => now()->subMinutes(10)]);
        Lead::create(['pancake_order_id' => 'a3', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'called', 'disposition' => 'Not Answering', 'assigned_at' => now()->subMinutes(10), 'called_at' => now()]);
        Lead::create(['pancake_order_id' => 'a4', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()]);

        $response = $this->actingAs($admin)->get(route('calls.analytics'));

        $response->assertOk();
        $response->assertSee($gemma->display_name);
        $response->assertSee('66.7%'); // 2/3 confirmed
        $response->assertSee('33.3%'); // 1/3 not answering
        $response->assertSee('10 min'); // avg of 10, 10, 10
    }

    /** A real logged outcome can be several comma-joined tags at once (see
     *  LeadController::splitTags()) — an exact-equals check would miss these
     *  entirely, which is exactly what happened before this fix. */
    public function test_a_multi_tag_disposition_still_counts_toward_confirm_and_no_answer_rate(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => 'm1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'called', 'disposition' => 'Confirmed, Repeat Order', 'assigned_at' => now(), 'called_at' => now()]);
        Lead::create(['pancake_order_id' => 'm2', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'called', 'disposition' => 'Not Answering, Duplicate', 'assigned_at' => now(), 'called_at' => now()]);

        $response = $this->actingAs($admin)->get(route('calls.analytics'));

        $response->assertOk();
        $response->assertSee('50%'); // 1/2 confirmed
    }

    public function test_a_lead_assigned_outside_the_date_range_is_excluded(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        Lead::create(['pancake_order_id' => 'old', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'called', 'disposition' => 'Confirmed', 'assigned_at' => now()->subDays(10), 'called_at' => now()->subDays(10)]);

        $response = $this->actingAs($admin)->get(route('calls.analytics'));

        $response->assertOk();
        $gemmaRow = collect($response->original->getData()['rows'])->firstWhere('tsa.id', $gemma->id);
        // Row still renders (every TSA always shows) but the lead assigned
        // 10 days ago falls outside today's default range, so it's excluded.
        $this->assertSame(0, $gemmaRow['total']);
    }
}
