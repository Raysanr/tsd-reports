<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\AutoCall;
use App\Support\RoundRobinAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Auto-call (2026-10-26): one global switch; Ready longest gets the next lead;
 *  if nobody can take it, it waits and the next Ready TSA claims it. */
class AutoCallTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // no real Pancake/SMS calls from tagging or auto-text
        $this->product = Product::first();
        TsaShift::query()->update(['status' => 'logout', 'dialer_host' => null]);
        \App\Models\Setting::set('auto_call_enabled', true);
    }

    private function tsa(string $key, string $status, bool $auto = true, ?Carbon $since = null): TsaShift
    {
        $t = TsaShift::where('tsa_key', $key)->first();
        // "auto" = has a Dialer address (auto-call applies); without one the TSA keeps round-robin
        $t->update(['status' => $status, 'dialer_host' => $auto ? '192.168.1.5:8080' : null, 'active' => true,
            'status_changed_at' => $since ?? now()]);
        $t->products()->syncWithoutDetaching([$this->product->id]);

        return $t->fresh();
    }

    private function lead(?TsaShift $tsa = null, array $extra = []): Lead
    {
        return Lead::create(array_merge([
            'pancake_order_id' => (string) random_int(100000, 999999), 'customer_name' => 'Juan', 'phone_number' => '09171234567',
            'product_id' => $this->product->id, 'tsa_id' => $tsa?->id, 'status' => $tsa ? 'assigned' : 'unassigned',
            'assigned_at' => $tsa ? now() : null, 'pancake_created_at' => now(),
        ], $extra));
    }

    public function test_the_tsa_ready_the_longest_gets_the_next_lead(): void
    {
        $this->tsa('Gemma', 'ready_to_call', true, now()->subMinutes(2));
        $julie = $this->tsa('Julie', 'ready_to_call', true, now()->subMinutes(9));
        $this->tsa('Mariel', 'calling', true, now()->subMinutes(20));   // busy — skipped

        $this->assertSame($julie->id, RoundRobinAssigner::next($this->product)->id);
    }

    public function test_a_ready_tsa_with_a_lead_already_waiting_is_skipped(): void
    {
        $gemma = $this->tsa('Gemma', 'ready_to_call', true, now()->subMinutes(9));
        $julie = $this->tsa('Julie', 'ready_to_call', true, now()->subMinutes(2));
        $this->lead($gemma); // Gemma's own lead still waiting to be dialed

        $this->assertSame($julie->id, RoundRobinAssigner::next($this->product)->id);
    }

    public function test_when_only_busy_auto_call_tsas_are_on_the_lead_waits(): void
    {
        $this->tsa('Gemma', 'calling');
        $this->tsa('Julie', 'wrap_up');

        $this->assertNull(RoundRobinAssigner::next($this->product));
    }

    public function test_tsas_without_auto_call_keep_the_normal_rotation(): void
    {
        $this->tsa('Gemma', 'calling');                 // auto-call, busy
        $normal = $this->tsa('Julie', 'calling', false); // auto-call off, busy — still gets leads like today

        $this->assertSame($normal->id, RoundRobinAssigner::next($this->product)->id);
    }

    public function test_a_ready_tsa_dials_their_own_waiting_lead_then_claims_the_oldest_in_line(): void
    {
        $gemma = $this->tsa('Gemma', 'ready_to_call');
        $own = $this->lead($gemma);
        $this->assertSame($own->id, AutoCall::next($gemma)->id);

        $own->update(['dialed_at' => now()]);
        $newer = $this->lead(null, ['pancake_created_at' => now()->subMinutes(1)]);
        $older = $this->lead(null, ['pancake_created_at' => now()->subMinutes(10)]);
        $claimed = AutoCall::next($gemma);
        $this->assertSame($older->id, $claimed->id);
        $this->assertSame('assigned', $older->fresh()->status);
        $this->assertSame($gemma->id, $older->fresh()->tsa_id);
        // asking again returns the same lead (not yet dialed), not a second one
        $this->assertSame($older->id, AutoCall::next($gemma)->id);
        $this->assertSame('unassigned', $newer->fresh()->status);
    }

    public function test_nothing_is_dialed_unless_ready_switched_on_and_for_a_handled_product(): void
    {
        $gemma = $this->tsa('Gemma', 'wrap_up');
        $this->lead();
        $this->assertNull(AutoCall::next($gemma));                // busy (wrap-up)
        $this->assertNull(AutoCall::next($this->tsa('Gemma', 'break')));    // on break

        $julie = $this->tsa('Julie', 'ready_to_call', false);
        $this->assertNull(AutoCall::next($julie));                // no Dialer address

        $mariel = $this->tsa('Mariel', 'ready_to_call');
        $mariel->products()->sync([]);
        $this->assertNull(AutoCall::next($mariel->fresh()));      // doesn't handle the product

        \App\Models\Setting::set('auto_call_enabled', false);
        $kath = $this->tsa('Kathleen', 'ready_to_call');
        $this->assertNull(AutoCall::next($kath));                 // switch off for everyone
        $this->assertNotNull(RoundRobinAssigner::next($this->product)); // and everyone is back on round-robin
    }

    public function test_endpoint_layout_hook_and_the_global_switch(): void
    {
        $gemma = $this->tsa('Gemma', 'ready_to_call');
        $lead = $this->lead($gemma);
        $user = User::create(['name' => 'Gemma U', 'email' => 'g@x.test', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->getJson(route('calls.auto-call.next'))->assertOk()
            ->assertJsonPath('lead.id', $lead->id)->assertJsonPath('lead.dialHost', '192.168.1.5:8080');
        $this->get(route('calls.leads.index'))->assertOk()->assertSee('name="auto-call"', false);

        // the admin turns it off for everyone from the TSA Management header
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('calls.tsa-management'))->assertOk()->assertSee('autoCallToggle', false);
        $this->postJson(route('calls.tsa-management.toggle-auto-call'), ['enabled' => '0'])->assertOk()->assertJson(['enabled' => false]);
        $this->assertFalse(AutoCall::enabled());

        $this->actingAs($user->fresh());
        $this->get(route('calls.leads.index'))->assertOk()->assertDontSee('name="auto-call"', false);
        $this->getJson(route('calls.auto-call.next'))->assertOk()->assertJsonPath('lead', null);
    }

    /** Monitor TSA's "Leads waiting" panel: unassigned leads with a product, newest first. */
    public function test_monitor_shows_the_leads_waiting_panel(): void
    {
        $this->lead(null, ['customer_name' => 'Newer Lead', 'pancake_created_at' => now()->subMinutes(2)]);
        $this->lead(null, ['customer_name' => 'Older Lead', 'pancake_created_at' => now()->subMinutes(20)]);
        $this->lead(null, ['customer_name' => 'No Product Lead', 'product_id' => null]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('calls.monitor'))->assertOk()
            ->assertSee('Leads waiting')
            ->assertSeeInOrder(['Newer Lead', 'Older Lead'])   // newest at the top
            ->assertDontSee('No Product Lead');                 // product not in Product Management
    }

    /** HIGH RTS leads are never claimed by auto-call or moved by a logout
     *  (2026-10-26); a note added on the order after the lead came in counts too. */
    public function test_high_rts_leads_are_never_auto_distributed(): void
    {
        $gemma = $this->tsa('Gemma', 'ready_to_call');
        $flagged = $this->lead(null, ['high_rts' => true, 'pancake_created_at' => now()->subMinutes(30)]);
        $this->assertNull(AutoCall::next($gemma));           // only a HIGH RTS lead waiting — not taken
        $this->assertSame('unassigned', $flagged->fresh()->status);

        $later = $this->lead();
        \App\Models\Order::create(['pancake_order_id' => $later->pancake_order_id, 'note' => 'HIGH RTS', 'synced_at' => now()]);
        $this->assertTrue(\App\Support\HighRts::lead($later));
        $this->assertTrue($later->fresh()->high_rts);

        // a supervisor-assigned HIGH RTS lead stays with its TSA when they log out
        $julie = $this->tsa('Julie', 'ready_to_call', false);
        $held = $this->lead($gemma, ['high_rts' => true]);
        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);
        \App\Support\LogoutLeadRedistributor::redistribute($gemma->fresh());
        $this->assertSame($gemma->id, $held->fresh()->tsa_id);
    }

    /** Logging in is enough — the first lead dials right away (2026-10-26). */
    public function test_a_tsa_who_just_logged_in_gets_auto_dialed(): void
    {
        $gemma = $this->tsa('Gemma', 'login');
        $waiting = $this->lead();

        $this->assertSame($waiting->id, AutoCall::next($gemma)->id);
        $this->assertTrue(AutoCall::canTakeNewLead($this->tsa('Julie', 'login')));
    }
}
