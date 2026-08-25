<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Product;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-25, a "smart rotation" round-robin follow-up):
 * "gemma is logout already and she only catered 50 of [75 assigned] ... the
 * remaining 25 will be distribute to her other team automatically and
 * equally" — confirmed this means her own uncalled backlog (leads still
 * assigned to her, never actually called), split evenly across her
 * currently-working teammates the moment she logs out. See
 * LogoutLeadRedistributor's own doc comment for the full mechanics.
 */
class LogoutLeadRedistributorTest extends TestCase
{
    use RefreshDatabase;

    private function leadFor(TsaShift $tsa, string $status = 'assigned', ?string $orderId = null): Lead
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        return Lead::create([
            'pancake_order_id' => $orderId ?? (string) random_int(100000, 999999),
            'customer_name' => 'Juan', 'product_id' => $product->id,
            'tsa_id' => $tsa->id, 'status' => $status,
        ]);
    }

    public function test_logging_out_splits_uncalled_backlog_evenly_across_teammates(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel  = TsaShift::where('tsa_key', 'Mariel')->first();
        $kathleen = TsaShift::where('tsa_key', 'Kathleen')->first();
        $mariel->update(['status' => 'login']);
        $kathleen->update(['status' => 'login']);

        // 5 uncalled leads still sitting with Gemma when she logs out.
        $leads = collect(range(1, 5))->map(fn () => $this->leadFor($gemma));

        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);

        $byTsa = $leads->map(fn (Lead $l) => $l->fresh()->tsa_id)->countBy();
        // 5 leads / 2 teammates = 3 and 2 (round-robin gives the extra to
        // whichever teammate comes first), never left with Gemma.
        $this->assertEqualsCanonicalizing([3, 2], $byTsa->values()->all());
        $this->assertArrayNotHasKey($gemma->id, $byTsa->all());
    }

    public function test_already_called_leads_are_never_touched(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $mariel->update(['status' => 'login']);

        $called = $this->leadFor($gemma, 'called');

        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);

        $this->assertSame($gemma->id, $called->fresh()->tsa_id);
    }

    public function test_backlog_stays_put_when_no_teammates_are_currently_working(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        // Every other SH Naturals TSA also logged out — Mariel already is
        // by seed default; Kathleen needs setting explicitly.
        TsaShift::where('team', 'SH Naturals')->where('id', '!=', $gemma->id)->update(['status' => 'logout']);

        $lead = $this->leadFor($gemma);

        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);

        $this->assertSame($gemma->id, $lead->fresh()->tsa_id);
    }

    public function test_only_teammates_on_the_same_team_are_eligible(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first(); // SH Naturals
        $julie = TsaShift::where('tsa_key', 'Julie')->first(); // Eyecare Team
        $julie->update(['status' => 'login']);
        // Every other SH Naturals TSA logged out — the only one left
        // "working" is Julie, on a different team entirely.
        TsaShift::where('team', 'SH Naturals')->where('id', '!=', $gemma->id)->update(['status' => 'logout']);

        $lead = $this->leadFor($gemma);

        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);

        // No SH Naturals teammate is working — Julie's on a different team,
        // not eligible regardless of her own status.
        $this->assertSame($gemma->id, $lead->fresh()->tsa_id);
    }

    public function test_a_redundant_logout_does_not_re_trigger_redistribution(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $mariel->update(['status' => 'login']);
        $gemma->update(['status' => 'logout']); // already logged out

        $lead = $this->leadFor($gemma);
        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT); // no-op transition

        // Nothing moved — the backlog only exists AFTER the "already
        // logged out" update above, so a real trigger would still catch
        // it; this confirms the !$wasLoggedOut guard, not an empty-backlog
        // false negative.
        $this->assertSame($gemma->id, $lead->fresh()->tsa_id);
    }

    public function test_moved_leads_get_a_fresh_assigned_at_and_a_logged_activity(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $mariel->update(['status' => 'login']);

        $lead = $this->leadFor($gemma);
        $lead->update(['assigned_at' => now()->subHours(5)]);

        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);

        $lead->refresh();
        $this->assertSame($mariel->id, $lead->tsa_id);
        $this->assertTrue($lead->assigned_at->gt(now()->subMinute()));

        $activity = LeadActivity::where('lead_id', $lead->id)->where('type', 'transferred')->first();
        $this->assertNotNull($activity);
        $this->assertStringContainsString('logged out', $activity->description);
    }

    public function test_inactive_teammates_are_not_eligible_recipients(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $mariel->update(['status' => 'login', 'active' => false]);
        // Kathleen (the other SH Naturals TSA) also logged out, so Mariel
        // (working but inactive) is the only remaining candidate.
        TsaShift::where('tsa_key', 'Kathleen')->update(['status' => 'logout']);

        $lead = $this->leadFor($gemma);

        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);

        $this->assertSame($gemma->id, $lead->fresh()->tsa_id);
    }
}
