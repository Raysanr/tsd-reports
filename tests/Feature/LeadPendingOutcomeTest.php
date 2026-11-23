<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Unsaved-outcome warning before the next dial (2026-11-23) — LeadController::pendingOutcome(). */
class LeadPendingOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private function lead(TsaShift $tsa, string $orderId, string $status = 'assigned'): Lead
    {
        return Lead::create([
            'pancake_order_id' => $orderId, 'customer_name' => 'Cust ' . $orderId, 'phone_number' => '09171234567',
            'product_id' => Product::where('display_name', 'SINUXYL')->first()->id,
            'tsa_id' => $tsa->id, 'status' => $status,
        ]);
    }

    private function tsaUser(TsaShift $tsa): User
    {
        return User::create(['name' => $tsa->tsa_key . ' User', 'email' => strtolower($tsa->tsa_key) . '@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $tsa->id]);
    }

    public function test_last_dialed_lead_without_a_saved_outcome_is_returned(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user = $this->tsaUser($gemma);
        $a = $this->lead($gemma, '1001');
        $b = $this->lead($gemma, '1002');

        $this->actingAs($user)->postJson(route('calls.leads.call-click', $a))->assertOk();

        $this->actingAs($user)->getJson(route('calls.leads.pending-outcome', ['next' => $b->id]))
            ->assertOk()
            ->assertJsonPath('lead.id', $a->id)
            ->assertJsonPath('lead.orderId', '1001');
    }

    public function test_nothing_pending_once_the_outcome_is_saved(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user = $this->tsaUser($gemma);
        $a = $this->lead($gemma, '1001');
        $b = $this->lead($gemma, '1002');

        $this->actingAs($user)->postJson(route('calls.leads.call-click', $a))->assertOk();
        $a->update(['status' => 'called', 'disposition' => 'CONFIRMED VIA CALL', 'called_at' => now()]);

        $this->actingAs($user)->getJson(route('calls.leads.pending-outcome', ['next' => $b->id]))
            ->assertOk()->assertJsonPath('lead', null);
    }

    public function test_redialing_the_same_lead_is_not_warned(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user = $this->tsaUser($gemma);
        $a = $this->lead($gemma, '1001');

        $this->actingAs($user)->postJson(route('calls.leads.call-click', $a))->assertOk();

        $this->actingAs($user)->getJson(route('calls.leads.pending-outcome', ['next' => $a->id]))
            ->assertOk()->assertJsonPath('lead', null);
    }

    public function test_only_the_last_dial_counts_and_only_today(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user = $this->tsaUser($gemma);
        $old = $this->lead($gemma, '1001');
        $b = $this->lead($gemma, '1002');

        LeadActivity::create(['lead_id' => $old->id, 'type' => 'call_clicked', 'description' => 'x', 'user_id' => $user->id, 'created_at' => now()->subDay()]);

        $this->actingAs($user)->getJson(route('calls.leads.pending-outcome', ['next' => $b->id]))
            ->assertOk()->assertJsonPath('lead', null);
    }

    public function test_a_lead_moved_to_another_tsa_is_not_warned(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $user = $this->tsaUser($gemma);
        $a = $this->lead($gemma, '1001');
        $b = $this->lead($gemma, '1002');

        $this->actingAs($user)->postJson(route('calls.leads.call-click', $a))->assertOk();
        $a->update(['tsa_id' => $mariel->id]);

        $this->actingAs($user)->getJson(route('calls.leads.pending-outcome', ['next' => $b->id]))
            ->assertOk()->assertJsonPath('lead', null);
    }

    public function test_admin_without_a_tsa_is_never_warned(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'admin']);

        $this->actingAs($admin)->getJson(route('calls.leads.pending-outcome'))
            ->assertOk()->assertJsonPath('lead', null);
    }
}
