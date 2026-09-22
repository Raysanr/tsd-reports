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
        Setting::set('overdue_threshold_minutes', 240);
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

    /**
     * Regression test (explicit report, 2026-09-17: "the callbacks is 68
     * but in the monitor tsa it is only 23") — root-caused live: an
     * UNASSIGNED lead (status='unassigned', no tsa_id — e.g. a product
     * with nobody eligible in its round-robin roster at the time) can
     * still carry a real due callback_at, stamped by SyncPancakeLeads::
     * backfillCallbackFromTags() noticing a Not Answering/Unattended tag
     * directly on the Pancake order — independent of whether anyone's
     * actually been assigned to it yet. The "Callbacks Due" tile's own
     * $leadCounts only ever sums PER-KNOWN-TSA callback counts
     * (Lead::where('tsa_id', $t->id)...), so these leads were invisible to
     * it even though the sidebar badge (no tsa_id filter at all for an
     * admin) correctly counted them — confirmed live on production: 23
     * (Monitor's per-TSA sum) + 47 (unassigned-with-due-callback) = 70,
     * matching the sidebar exactly.
     */
    public function test_callbacks_due_tile_includes_unassigned_leads_with_a_due_callback(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        // Assigned-TSA callback — already counted by $leadCounts' own sum.
        Lead::create([
            'pancake_order_id' => 'assigned-callback-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id,
            'status' => 'assigned', 'disposition' => 'Unattended', 'callback_at' => now()->subMinutes(5),
        ]);
        // Unassigned lead with a real due callback (e.g. backfilled from a
        // Pancake tag before any TSA ever picked it up) — the gap this
        // fix closes.
        Lead::create([
            'pancake_order_id' => 'unassigned-callback-1', 'product_id' => $product->id, 'tsa_id' => null,
            'status' => 'unassigned', 'disposition' => 'Unattended', 'callback_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($this->admin())->get(route('calls.monitor'));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('unassignedCallbacksDueCount'));
        // Both leads must be counted together on the team-wide tile — the
        // per-TSA sum alone would miss the unassigned one.
        $leadCounts = collect($response->viewData('leadCounts'));
        $this->assertSame(1, $leadCounts->sum('callbacks'));
        $this->assertSame(2, $leadCounts->sum('callbacks') + $response->viewData('unassignedCallbacksDueCount'));
    }

    /**
     * Regression test (explicit report, 2026-09-17: "this is today but it
     * is not same in the monitor tsa page" — Monitor's own Overdue Leads/
     * Callbacks Due tiles read a much HIGHER number than the sidebar
     * badge for the same "today") — root-caused: this query only checked
     * assigned_at/callback_at, missing the pancake_created_at-must-
     * actually-be-today guard NotificationController's own badge and
     * LeadController::index()'s Overdue/Callbacks views already have. A
     * lead whose real Pancake order is from weeks ago but only got
     * assigned_at/callback_at stamped today (e.g. a backlog catch-up)
     * inflated Monitor's tiles even though it correctly stayed OUT of the
     * sidebar badge and the actual Overdue/Callbacks pages.
     */
    public function test_overdue_and_callback_counts_exclude_an_old_order_only_touched_today(): void
    {
        Setting::set('overdue_threshold_minutes', 240);
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        // Old real order (pancake_created_at weeks ago), only just caught
        // up (assigned_at/callback_at stamped today) — must NOT count.
        Lead::create([
            'pancake_order_id' => 'old-overdue-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id,
            'status' => 'assigned', 'assigned_at' => now()->subHours(5),
            'pancake_created_at' => now()->subDays(30),
        ]);
        Lead::create([
            'pancake_order_id' => 'old-callback-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id,
            'status' => 'called', 'called_at' => now()->subHours(2), 'callback_at' => now()->subMinutes(5),
            'pancake_created_at' => now()->subDays(30),
        ]);

        $response = $this->actingAs($this->admin())->get(route('calls.monitor'));

        $response->assertOk();
        $leadCounts = collect($response->viewData('leadCounts'));
        $this->assertSame(0, $leadCounts->sum('overdue'));
        $this->assertSame(0, $leadCounts->sum('callbacks'));
    }

    /**
     * Explicit request, 2026-09-22: "add this like how many of the
     * callback of one TSA like COUNT OF CALLBACK, UNANSWERED CALLS, LEADS
     * CALLED" — clarified via follow-up: "i mean every leads of the tsa
     * is there's call back they tag or unanswered ... tag, so count it
     * every tsa like that" — deliberately the BROADER "currently carries
     * this disposition" count, not the narrower due-now "Callback Due"
     * pill already tested above. A Call Back lead with callback_at hours
     * in the future still counts here even though it wouldn't show as
     * due; an Unanswered-tagged lead counts regardless of callback_at
     * (that page never depends on it at all).
     */
    public function test_shows_a_tsas_broader_callback_and_unanswered_tag_counts_on_their_own_card(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        // Call Back tagged, but NOT due yet — still counts for Callback
        // Count, unlike the "Callback Due" pill.
        Lead::create([
            'pancake_order_id' => 'cb-count-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id,
            'status' => 'called', 'disposition' => 'Call Back', 'callback_at' => now()->addHours(3),
        ]);
        Lead::create([
            'pancake_order_id' => 'unanswered-count-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id,
            'status' => 'assigned', 'disposition' => 'Not Answering',
        ]);
        Lead::create([
            'pancake_order_id' => 'unanswered-count-2', 'product_id' => $product->id, 'tsa_id' => $gemma->id,
            'status' => 'assigned', 'disposition' => 'Invalid Number',
        ]);

        $response = $this->actingAs($this->admin())->get(route('calls.monitor'));

        $response->assertOk();
        $response->assertSee('Callback Count: 1', false);
        $response->assertSee('Unanswered Calls: 2', false);

        $leadCounts = collect($response->viewData('leadCounts'));
        $this->assertSame(1, $leadCounts[$gemma->id]['callbackCount']);
        $this->assertSame(2, $leadCounts[$gemma->id]['unansweredCount']);
    }

    /**
     * Explicit request, same day: "the LEADS CALLED is how many number
     * calls like click to call of all TSA" — clarified via follow-up as
     * per-TSA, not one team-wide total. Sourced from LeadActivity's own
     * user_id (the person who actually clicked), not lead.tsa_id — an
     * admin clicking on a TSA's behalf, or a different TSA picking up a
     * shared Callbacks lead, must attribute to whoever actually clicked.
     */
    public function test_shows_a_tsas_own_calls_called_count_on_their_card(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        $gemmaUser  = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);
        $marielUser = User::factory()->create(['role' => 'tsa', 'tsa_id' => $mariel->id]);

        $lead1 = Lead::create(['pancake_order_id' => 'call-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $lead2 = Lead::create(['pancake_order_id' => 'call-2', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $lead3 = Lead::create(['pancake_order_id' => 'call-3', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned']);

        $this->actingAs($gemmaUser)->post(route('calls.leads.call-click', $lead1));
        $this->actingAs($gemmaUser)->post(route('calls.leads.call-click', $lead2));
        $this->actingAs($marielUser)->post(route('calls.leads.call-click', $lead3));

        $response = $this->actingAs($this->admin())->get(route('calls.monitor'));

        $response->assertOk();
        $leadCounts = collect($response->viewData('leadCounts'));
        $this->assertSame(2, $leadCounts[$gemma->id]['callsCalled']);
        $this->assertSame(1, $leadCounts[$mariel->id]['callsCalled']);
    }

    /** A TSA with none of the 3 new tag/click counts shows none of these
     *  3 pills — same "quiet card, nothing to see" convention the
     *  Overdue/Callback Due pills already follow. */
    public function test_a_tsa_with_no_callback_unanswered_or_call_activity_shows_none_of_the_new_pills(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => 'quiet-1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        $response = $this->actingAs($this->admin())->get(route('calls.monitor'));

        $response->assertOk();
        $response->assertDontSee('bg-amber-50 dark:bg-amber-950/30', false);
        $response->assertDontSee('bg-blue-50 dark:bg-blue-950/30', false);
        $response->assertDontSee('bg-yellow-50 dark:bg-yellow-950/30', false);
    }

    public function test_lead_counts_use_the_pages_own_date_range_not_always_today(): void
    {
        Setting::set('overdue_threshold_minutes', 240);
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
