<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\TsaStatusLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift, routes -> calls.*.
 * Explicit request (2026-08-08): a TSA's own real-time status dropdown
 * (LOGIN/BREAK/DNA HUDDLE/COACHING/LOGOUT), topbar — controls round-robin
 * eligibility (see RoundRobinAssignerTest) and is timestamped for the TSA
 * Logs page.
 */
class TsaStatusControllerTest extends TestCase
{
    use RefreshDatabase;

    private function tsaUser(string $tsaKey = 'Gemma'): User
    {
        $tsa = TsaShift::where('tsa_key', $tsaKey)->first();
        return User::create(['name' => $tsa->display_name, 'email' => strtolower($tsaKey) . '@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $tsa->id]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_a_tsa_can_switch_their_own_status(): void
    {
        $user = $this->tsaUser('Gemma');

        $response = $this->actingAs($user)->postJson('/calls/tsa-status', ['status' => 'break']);

        $response->assertOk()->assertJson(['success' => true, 'status' => 'break']);
        $this->assertSame('break', $user->tsa->fresh()->status);
    }

    public function test_switching_status_writes_a_timestamped_log_entry(): void
    {
        $user = $this->tsaUser('Gemma');

        $this->actingAs($user)->postJson('/calls/tsa-status', ['status' => 'coaching']);

        $log = TsaStatusLog::where('tsa_id', $user->tsa_id)->first();
        $this->assertNotNull($log);
        $this->assertSame('coaching', $log->status);
    }

    public function test_switching_back_to_login_makes_the_tsa_eligible_for_round_robin_again(): void
    {
        $user = $this->tsaUser('Gemma');
        $user->tsa->update(['status' => 'break']);

        $this->actingAs($user)->postJson('/calls/tsa-status', ['status' => 'login']);

        $this->assertSame('login', $user->tsa->fresh()->status);
    }

    public function test_rejects_a_status_that_is_not_one_of_the_five_real_ones(): void
    {
        $user = $this->tsaUser('Gemma');

        $response = $this->actingAs($user)->postJson('/calls/tsa-status', ['status' => 'napping']);

        $response->assertStatus(422);
        $this->assertSame('login', $user->tsa->fresh()->status); // unchanged
    }

    public function test_an_admin_with_no_tsa_of_their_own_cannot_switch_a_status(): void
    {
        $response = $this->actingAs($this->admin())->postJson('/calls/tsa-status', ['status' => 'break']);

        $response->assertForbidden();
    }

    /** Explicit request (2026-08-08): admins can also set a TSA's status,
     *  not just the TSA themselves — TSA Management's per-row panel. */
    public function test_an_admin_can_switch_any_tsas_status_by_id(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/calls/tsa-status', ['status' => 'coaching', 'tsa_id' => $gemma->id]);

        $response->assertOk()->assertJson(['success' => true, 'status' => 'coaching', 'tsa_id' => $gemma->id]);
        $this->assertSame('coaching', $gemma->fresh()->status);
    }

    /** Mirrors Pancake's own admin-only "Lock" conversation-receive-mode
     *  option (confirmed live in Pancake's UI): only an admin can set it. */
    public function test_a_non_admin_tsa_cannot_lock_their_own_status(): void
    {
        $user = $this->tsaUser('Gemma');

        $response = $this->actingAs($user)->postJson('/calls/tsa-status', ['status' => 'locked']);

        $response->assertForbidden();
        $this->assertSame('login', $user->tsa->fresh()->status); // unchanged
    }

    public function test_an_admin_can_lock_a_tsas_status_and_it_records_who_locked_them(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/calls/tsa-status', ['status' => 'locked', 'tsa_id' => $gemma->id]);

        $response->assertOk();
        $gemma->refresh();
        $this->assertSame('locked', $gemma->status);
        $this->assertSame($admin->id, $gemma->status_locked_by);
    }

    /** Once locked, the TSA can't change themselves out of it — matches
     *  Pancake's own "and changing this status" wording on the Lock option. */
    public function test_a_locked_tsa_cannot_change_their_own_status(): void
    {
        $user = $this->tsaUser('Gemma');
        $user->tsa->update(['status' => 'locked', 'status_locked_by' => $this->admin()->id]);

        $response = $this->actingAs($user)->postJson('/calls/tsa-status', ['status' => 'login']);

        $response->assertForbidden();
        $this->assertSame('locked', $user->tsa->fresh()->status);
    }

    /** An admin CAN unlock a TSA — and doing so clears status_locked_by so
     *  it never falsely claims a TSA is still locked by someone. */
    public function test_an_admin_can_unlock_a_tsa_which_clears_who_locked_them(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $admin = $this->admin();
        $gemma->update(['status' => 'locked', 'status_locked_by' => $admin->id]);

        $this->actingAs($admin)->postJson('/calls/tsa-status', ['status' => 'login', 'tsa_id' => $gemma->id]);

        $gemma->refresh();
        $this->assertSame('login', $gemma->status);
        $this->assertNull($gemma->status_locked_by);
    }

    public function test_a_tsa_cannot_reach_the_admin_only_tsa_logs_page(): void
    {
        $user = $this->tsaUser('Gemma');

        $response = $this->actingAs($user)->get(route('calls.tsa-logs'));

        $response->assertForbidden();
    }

    public function test_tsa_logs_page_lists_status_changes_newest_first(): void
    {
        $user = $this->tsaUser('Gemma');
        $this->actingAs($user)->postJson('/calls/tsa-status', ['status' => 'break']);
        $this->actingAs($user)->postJson('/calls/tsa-status', ['status' => 'login']);

        $response = $this->actingAs($this->admin())->get(route('calls.tsa-logs'));

        $response->assertOk();
        $logs = $response->viewData('logs');
        $this->assertSame('login', $logs->first()->status); // most recent change first
        $this->assertSame(2, $logs->total());
    }

    public function test_tsa_logs_page_filters_by_tsa(): void
    {
        $gemma  = $this->tsaUser('Gemma');
        $mariel = $this->tsaUser('Mariel');
        $this->actingAs($gemma)->postJson('/calls/tsa-status', ['status' => 'break']);
        $this->actingAs($mariel)->postJson('/calls/tsa-status', ['status' => 'break']);

        $response = $this->actingAs($this->admin())->get(route('calls.tsa-logs', ['tsa' => $gemma->tsa_id]));

        $response->assertOk();
        $logs = $response->viewData('logs');
        $this->assertSame(1, $logs->total());
        $this->assertSame($gemma->tsa_id, $logs->first()->tsa_id);
    }

    /** 2026-08-10: explicit request — clicking a customer's phone number
     *  should show up on TSA Logs too, not just real status changes (see
     *  LeadController::logCallClick()). */
    public function test_tsa_logs_page_includes_call_click_events_alongside_status_changes(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'customer_name' => 'Melody Ligang']);
        LeadActivity::log($lead, 'call_clicked', 'Gemma De Guzman clicked to call Melody Ligang (09101908357).');

        $response = $this->actingAs($this->admin())->get(route('calls.tsa-logs'));

        $response->assertOk();
        $logs = $response->viewData('logs');
        $this->assertSame(1, $logs->total());
        $this->assertSame('call', $logs->first()->kind);
        $this->assertSame($gemma->id, $logs->first()->tsa_id);
        $this->assertStringContainsString('Melody Ligang', $logs->first()->detail);
    }

    /** Both event types share one merged, real-recency ordering — a call
     *  click that happened after the most recent status change must sort
     *  above it, not get appended after all status rows. */
    public function test_tsa_logs_page_merges_both_event_types_by_true_recency(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        TsaStatusLog::create(['tsa_id' => $gemma->id, 'status' => 'break', 'created_at' => now()->subMinutes(10)]);
        LeadActivity::create(['lead_id' => $lead->id, 'type' => 'call_clicked', 'description' => 'Called the lead.', 'created_at' => now()->subMinutes(5)]);
        TsaStatusLog::create(['tsa_id' => $gemma->id, 'status' => 'login', 'created_at' => now()]);

        $response = $this->actingAs($this->admin())->get(route('calls.tsa-logs'));

        $logs = $response->viewData('logs')->values();
        $this->assertSame(3, $logs->count());
        $this->assertSame('status', $logs[0]->kind);
        $this->assertSame('login', $logs[0]->status);
        $this->assertSame('call', $logs[1]->kind);
        $this->assertSame('status', $logs[2]->kind);
        $this->assertSame('break', $logs[2]->status);
    }

    /**
     * Explicit request (2026-08-22): the topbar badge should reflect
     * Calling/Wrap Up — both system-only, set automatically — without the
     * TSA reloading the page. This endpoint is what the badge polls.
     */
    public function test_own_status_returns_the_authenticated_tsas_current_status(): void
    {
        $tsa  = TsaShift::where('tsa_key', 'Gemma')->first();
        $user = $this->tsaUser('Gemma');
        $tsa->applyStatusChange(TsaShift::STATUS_CALLING);

        $response = $this->actingAs($user)->getJson(route('calls.tsa-status.own'));

        $response->assertOk();
        $response->assertJson(['status' => 'calling', 'label' => 'Calling', 'dot_class' => 'bg-red-500', 'readonly' => false]);
    }

    public function test_own_status_reflects_wrap_up(): void
    {
        $tsa  = TsaShift::where('tsa_key', 'Gemma')->first();
        $user = $this->tsaUser('Gemma');
        $tsa->applyStatusChange(TsaShift::STATUS_WRAP_UP);

        $response = $this->actingAs($user)->getJson(route('calls.tsa-status.own'));

        $response->assertOk();
        $response->assertJson(['status' => 'wrap_up', 'dot_class' => 'bg-orange-500']);
    }

    public function test_own_status_is_forbidden_for_a_user_with_no_tsa(): void
    {
        $response = $this->actingAs($this->admin())->getJson(route('calls.tsa-status.own'));

        $response->assertForbidden();
    }
}
