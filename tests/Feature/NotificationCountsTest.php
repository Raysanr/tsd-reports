<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift, routes -> calls.*. */
class NotificationCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tsa_only_gets_counts_scoped_to_their_own_leads(): void
    {
        Setting::set('overdue_threshold_minutes', 240);
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel  = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => 'n1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()->subHours(5)]);
        Lead::create(['pancake_order_id' => 'n2', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned', 'assigned_at' => now()->subHours(5)]);
        Lead::create(['pancake_order_id' => 'n3', 'product_id' => null, 'status' => 'unassigned']);

        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->getJson(route('calls.notifications.counts'));

        $response->assertOk();
        $response->assertJson(['assigned' => 1, 'overdue' => 1, 'unassigned' => 0]);
    }

    /** Reversed for callbacks specifically (explicit request, 2026-09-08: "i
     *  want to make it like it is visible to all of the TSA's the
     *  callbacks") — this sidebar badge must agree with the Callbacks page
     *  itself, which now shows every TSA's due callbacks to any TSA (see
     *  LeadControllerTest's matching test). assigned/overdue stay scoped to
     *  the viewer's own leads, unchanged. */
    public function test_a_tsa_sees_every_tsas_due_callbacks_not_just_their_own(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel  = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => 'n7', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'callback_at' => now()->subHour()]);
        Lead::create(['pancake_order_id' => 'n8', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned', 'callback_at' => now()->subHour()]);

        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->getJson(route('calls.notifications.counts'));

        $response->assertOk();
        $response->assertJson(['callbacks' => 2]);
    }

    public function test_an_admin_sees_counts_across_every_tsa_plus_unassigned(): void
    {
        Setting::set('overdue_threshold_minutes', 240);
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel  = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => 'n4', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()->subHours(5)]);
        Lead::create(['pancake_order_id' => 'n5', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned', 'assigned_at' => now()->subHours(5)]);
        Lead::create(['pancake_order_id' => 'n6', 'product_id' => null, 'status' => 'unassigned']);

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->getJson(route('calls.notifications.counts'));

        $response->assertOk();
        $response->assertJson(['assigned' => 2, 'overdue' => 2, 'unassigned' => 1]);
    }

    /**
     * Regression test, 2026-09-14: "why the overdue is not today? it
     * should be today only" — the sidebar's own Overdue badge counted a
     * weeks-old order the same way the Overdue page itself used to: once
     * SyncPancakeLeads' catch-up sweep stamped assigned_at with today's
     * timestamp, the badge counted it as "today's overdue" even though the
     * underlying order was created long before today. This badge must
     * agree with what the Overdue page (LeadController::index()) actually
     * shows, or the two silently disagree again.
     */
    public function test_overdue_and_assigned_counts_exclude_an_old_order_assigned_today(): void
    {
        Setting::set('overdue_threshold_minutes', 240);
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create([
            'pancake_order_id' => 'n9', 'product_id' => $product->id, 'tsa_id' => $gemma->id,
            'status' => 'assigned', 'assigned_at' => now()->subHours(5),
            'pancake_created_at' => now()->subDays(30),
        ]);

        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->getJson(route('calls.notifications.counts'));

        $response->assertOk();
        $response->assertJson(['assigned' => 0, 'overdue' => 0]);
    }

    /**
     * Regression test (explicit report, 2026-09-17: "the callbacks is 65
     * but in the monitor tsa it is only 23") — root-caused: this badge
     * counted every callback SCHEDULED for today's date range, including
     * one due later today (e.g. 6pm), missing the same "due now or already
     * past due, not someday in the future" clause LeadController::index()'s
     * own Callbacks view and Monitor's own per-TSA callback count both
     * already have — so this badge could read higher than what the actual
     * Callbacks page shows.
     */
    public function test_callbacks_count_excludes_one_scheduled_for_later_today(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => 'n10', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'callback_at' => now()->subHour()]);
        Lead::create(['pancake_order_id' => 'n11', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'callback_at' => now()->addHours(3)]);

        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->getJson(route('calls.notifications.counts'));

        $response->assertOk();
        $response->assertJson(['callbacks' => 1]);
    }

    /**
     * Regression test, real production incident 2026-09-21: a client-side
     * bug elsewhere (initLiveLeadsSearch()'s own URL construction) briefly
     * let a malformed date_from like "2026-09-21?tsa=" reach this endpoint
     * — polled every 30s on every page — and Carbon::parse() throwing on
     * it 500'd the whole sidebar badge poll. This endpoint has no way to
     * fully control what a browser's own JS/localStorage state sends it,
     * so an unparseable date must fail open to today() (matching this
     * class's own "fail open" convention elsewhere) rather than crash.
     */
    public function test_an_unparseable_date_from_falls_back_to_today_instead_of_erroring(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => 'n12', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()]);

        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->getJson(route('calls.notifications.counts', ['date_from' => '2026-09-21?tsa=', 'date_to' => '2026-09-21?tsa=']));

        $response->assertOk();
        // Falls back to today() exactly as if no date_from/date_to were
        // sent at all — the lead created "now" above still counts.
        $response->assertJson(['assigned' => 1]);
    }
}
