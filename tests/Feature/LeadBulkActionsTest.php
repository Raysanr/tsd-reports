<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request, 2026-08-26: "can you make this can select and can bulk
 * action too like that for the example" (Product Management's own checkbox +
 * bulk-bar pattern). Confirmed scope: bulk Pin/Unpin and bulk Transfer — the
 * two per-row actions (togglePin()/transfer()) that already exist and are
 * local-only (no Pancake writes), unlike Product Management's Hide/Unhide/
 * Move/Delete, none of which have a Lead equivalent.
 */
class LeadBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function leadFor(TsaShift $tsa, string $status = 'assigned'): Lead
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        return Lead::create([
            'pancake_order_id' => (string) random_int(100000, 999999),
            'customer_name' => 'Juan', 'product_id' => $product->id,
            'tsa_id' => $tsa->id, 'status' => $status,
        ]);
    }

    public function test_an_admin_sees_the_bulk_select_checkboxes_on_the_leads_page(): void
    {
        $this->actingAs($this->admin());
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $this->leadFor($gemma);

        $response = $this->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('leadCheckbox', false);
        $response->assertSee('selectAllLeadsCheckbox', false);
        $response->assertSee('bulkLeadsBar', false);
    }

    public function test_a_tsa_never_sees_the_bulk_select_checkboxes(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);
        $this->actingAs($user);
        $this->leadFor($gemma);

        $response = $this->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertDontSee('leadCheckbox', false);
        $response->assertDontSee('selectAllLeadsCheckbox', false);
        $response->assertDontSee('bulkLeadsBar', false);
    }

    public function test_an_admin_can_bulk_pin_leads(): void
    {
        $this->actingAs($this->admin());
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead1 = $this->leadFor($gemma);
        $lead2 = $this->leadFor($gemma);

        $response = $this->postJson(route('calls.leads.bulk-pin'), [
            'lead_ids' => [$lead1->id, $lead2->id],
            'pin'      => true,
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertNotNull($lead1->fresh()->pinned_at);
        $this->assertNotNull($lead2->fresh()->pinned_at);
    }

    public function test_an_admin_can_bulk_unpin_leads(): void
    {
        $this->actingAs($this->admin());
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead = $this->leadFor($gemma);
        $lead->update(['pinned_at' => now()]);

        $response = $this->postJson(route('calls.leads.bulk-pin'), [
            'lead_ids' => [$lead->id],
            'pin'      => false,
        ]);

        $response->assertOk();
        $this->assertNull($lead->fresh()->pinned_at);
    }

    /**
     * Explicit correction, 2026-08-26 (same day): bulk actions are
     * admin-only across the board now, not just Transfer — a TSA keeps
     * the single-row pin button (togglePin(), untouched) but never sees
     * a checkbox to bulk-select with in the first place.
     */
    public function test_a_tsa_cannot_bulk_pin_even_their_own_leads(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);
        $this->actingAs($user);

        $ownLead = $this->leadFor($gemma);

        $this->postJson(route('calls.leads.bulk-pin'), [
            'lead_ids' => [$ownLead->id],
            'pin'      => true,
        ])->assertForbidden();

        $this->assertNull($ownLead->fresh()->pinned_at);
    }

    public function test_bulk_pin_requires_at_least_one_lead_id(): void
    {
        $this->actingAs($this->admin());

        $this->postJson(route('calls.leads.bulk-pin'), ['lead_ids' => [], 'pin' => true])
            ->assertStatus(422);
    }

    public function test_an_admin_can_bulk_transfer_leads(): void
    {
        $this->actingAs($this->admin());
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $lead1 = $this->leadFor($gemma);
        $lead2 = $this->leadFor($gemma, 'unassigned');

        $response = $this->postJson(route('calls.leads.bulk-transfer'), [
            'lead_ids' => [$lead1->id, $lead2->id],
            'tsa_id'   => $mariel->id,
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame($mariel->id, $lead1->fresh()->tsa_id);
        $this->assertSame($mariel->id, $lead2->fresh()->tsa_id);
        // An unassigned lead flips to 'assigned' on transfer, same as the
        // single-row version — a pickup, not a no-op reassignment.
        $this->assertSame('assigned', $lead2->fresh()->status);
    }

    public function test_bulk_transfer_logs_an_activity_per_lead(): void
    {
        $this->actingAs($this->admin());
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $lead1 = $this->leadFor($gemma);
        $lead2 = $this->leadFor($gemma);

        $this->postJson(route('calls.leads.bulk-transfer'), [
            'lead_ids' => [$lead1->id, $lead2->id],
            'tsa_id'   => $mariel->id,
        ]);

        $this->assertSame(1, LeadActivity::where('lead_id', $lead1->id)->where('type', 'transferred')->count());
        $this->assertSame(1, LeadActivity::where('lead_id', $lead2->id)->where('type', 'transferred')->count());
    }

    public function test_bulk_transfer_skips_a_lead_already_on_the_target_tsa(): void
    {
        $this->actingAs($this->admin());
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $alreadyThere = $this->leadFor($mariel);
        $toMove       = $this->leadFor($gemma);

        $response = $this->postJson(route('calls.leads.bulk-transfer'), [
            'lead_ids' => [$alreadyThere->id, $toMove->id],
            'tsa_id'   => $mariel->id,
        ]);

        $response->assertOk()->assertJsonFragment(['message' => "Transferred 1 lead to {$mariel->display_name}."]);
        $this->assertSame(0, LeadActivity::where('lead_id', $alreadyThere->id)->where('type', 'transferred')->count());
    }

    public function test_a_tsa_cannot_bulk_transfer(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);
        $this->actingAs($user);
        $lead = $this->leadFor($gemma);

        $this->postJson(route('calls.leads.bulk-transfer'), [
            'lead_ids' => [$lead->id],
            'tsa_id'   => $mariel->id,
        ])->assertForbidden();

        $this->assertSame($gemma->id, $lead->fresh()->tsa_id);
    }

    public function test_bulk_transfer_rejects_a_nonexistent_tsa(): void
    {
        $this->actingAs($this->admin());
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead = $this->leadFor($gemma);

        $this->postJson(route('calls.leads.bulk-transfer'), [
            'lead_ids' => [$lead->id],
            'tsa_id'   => 999999,
        ])->assertStatus(422);
    }
}
