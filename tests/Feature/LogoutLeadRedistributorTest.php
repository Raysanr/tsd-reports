<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Order;
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
        // Everyone else logged out — only Mariel and Kathleen are online
        // to receive the split.
        TsaShift::whereNotIn('id', [$gemma->id, $mariel->id, $kathleen->id])->update(['status' => 'logout']);
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
        // Every other TSA on either team logged out — nobody anywhere to
        // hand the backlog to.
        TsaShift::where('id', '!=', $gemma->id)->update(['status' => 'logout']);

        $lead = $this->leadFor($gemma);

        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);

        $this->assertSame($gemma->id, $lead->fresh()->tsa_id);
    }

    /**
     * Team preference removed entirely 2026-09-16 (explicit follow-up: "i
     * want to make it like not closing and opening ... it should be like
     * depends to the who's online and depends to the products that is
     * checked to the tsa management") — a cross-team teammate is exactly
     * as eligible as a same-team one now; there is no team-based tier
     * ordering left at all. See LogoutLeadRedistributor's own doc comment
     * for the full history.
     */
    public function test_redistributes_to_an_online_teammate_on_the_other_team_just_the_same(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first(); // SH Naturals
        $julie = TsaShift::where('tsa_key', 'Julie')->first(); // Eyecare Team — checked for Sinuxyl
        $julie->update(['status' => 'login']);
        $julie->products()->sync([Product::where('display_name', 'SINUXYL')->first()->id]);
        // Every other SH Naturals TSA logged out — the only one left
        // "working" is Julie, on a different team entirely.
        TsaShift::where('team', 'SH Naturals')->where('id', '!=', $gemma->id)->update(['status' => 'logout']);

        $lead = $this->leadFor($gemma);

        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);

        $lead->refresh();
        $this->assertSame($julie->id, $lead->tsa_id);

        $activity = LeadActivity::where('lead_id', $lead->id)->where('type', 'transferred')->first();
        $this->assertStringNotContainsString('fallback', $activity->description);
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

    /**
     * Root-caused 2026-08-26 from real examples (#1347621, #1347619, and
     * others): redistributing a lead whose order already resolved on its
     * own (Received/Returned/Returning/Partial return/Canceled/Collected
     * money) reset assigned_at to now() for a dead lead, which made it
     * reappear at the top of the receiving TSA's Overdue queue looking
     * urgent even though there's nothing left to call about.
     */
    public function test_a_lead_whose_order_already_resolved_is_left_with_the_logged_out_tsa(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $mariel->update(['status' => 'login']);

        $lead = $this->leadFor($gemma, 'assigned', '1347621');
        Order::create(['pancake_order_id' => '1347621', 'status_code' => 5, 'pancake_created_at' => now(), 'pancake_inserted_at' => now(), 'synced_at' => now()]);
        $originalAssignedAt = $lead->assigned_at;

        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);

        $lead->refresh();
        $this->assertSame($gemma->id, $lead->tsa_id);
        $this->assertTrue($lead->assigned_at?->eq($originalAssignedAt) ?? $originalAssignedAt === null);
    }

    public function test_a_lead_whose_order_is_still_live_still_redistributes_normally(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $mariel->update(['status' => 'login']);

        $lead = $this->leadFor($gemma, 'assigned', '1357999');
        Order::create(['pancake_order_id' => '1357999', 'status_code' => 1, 'pancake_created_at' => now(), 'pancake_inserted_at' => now(), 'synced_at' => now()]);

        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);

        $this->assertSame($mariel->id, $lead->fresh()->tsa_id);
    }

    public function test_a_lead_with_no_synced_order_yet_still_redistributes_normally(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $mariel->update(['status' => 'login']);

        $lead = $this->leadFor($gemma);

        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);

        $this->assertSame($mariel->id, $lead->fresh()->tsa_id);
    }

    public function test_inactive_teammates_are_not_eligible_recipients(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $mariel->update(['status' => 'login', 'active' => false]);
        // Kathleen (the other SH Naturals TSA) also logged out, so Mariel
        // (working but inactive) is the only remaining SH Naturals
        // candidate. Every Eyecare TSA logged out too (2026-09-08, same
        // reasoning as test_backlog_stays_put_when_no_teammates_are_
        // currently_working above) — otherwise the cross-team fallback
        // would silently redistribute there instead of proving inactive
        // teammates are correctly excluded.
        TsaShift::where('tsa_key', 'Kathleen')->update(['status' => 'logout']);
        TsaShift::where('team', 'Eyecare Team')->update(['status' => 'logout']);

        $lead = $this->leadFor($gemma);

        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);

        $this->assertSame($gemma->id, $lead->fresh()->tsa_id);
    }

    /**
     * Regression test, 2026-09-14: "i think there's a leads that is
     * removed like that, and there's slow in some tsa... i want you to
     * dig on that if there's a bug like that" — root-caused live: Angel
     * Margallo, checked in TSA Management for only AudiCure/Ginseng
     * Serum/Scar Cream/Scar Erase, received 18 Pterylief leads (a product
     * she has never been checked for) because she was the only TSA online
     * when a Pterylief-roster teammate logged out with them still
     * uncalled — the pre-fix "any online TSA, regardless of product
     * setup" rule handed them to her anyway. Explicit confirmation: "keep
     * it, but only hand off to a teammate who handles that product." This
     * proves tier 1 (checked for this product) is preferred over an
     * online TSA who ISN'T checked for it, even though the old code would
     * have picked either one interchangeably. Team no longer factors in
     * at all (2026-09-16) — Kathleen here is cross-team on purpose, to
     * prove product eligibility alone decides it.
     */
    public function test_prefers_a_teammate_who_actually_handles_the_leads_product_regardless_of_team(): void
    {
        $gemma    = TsaShift::where('tsa_key', 'Gemma')->first();    // SH Naturals
        $mariel   = TsaShift::where('tsa_key', 'Mariel')->first();   // SH Naturals — checked for Sinuxyl
        $julie    = TsaShift::where('tsa_key', 'Julie')->first();    // Eyecare Team — NOT checked for Sinuxyl
        $mariel->update(['status' => 'login']);
        $julie->update(['status' => 'login']);
        $mariel->products()->sync([Product::where('display_name', 'SINUXYL')->first()->id]);
        $julie->products()->sync([]);

        $lead = $this->leadFor($gemma); // Sinuxyl product, per leadFor()'s own default

        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);

        $this->assertSame($mariel->id, $lead->fresh()->tsa_id);
        $activity = LeadActivity::where('lead_id', $lead->id)->where('type', 'transferred')->first();
        $this->assertStringNotContainsString('fallback', $activity->description);
    }

    /** Absolute last resort (tier 2) still applies when NOBODY online —
     *  same team or otherwise — is checked for the product: any online
     *  TSA, regardless of product setup, same "someone answers the phone"
     *  reasoning as before this fix. */
    public function test_falls_back_to_any_online_teammate_when_nobody_anywhere_handles_the_product(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first(); // SH Naturals
        $julie = TsaShift::where('tsa_key', 'Julie')->first(); // Eyecare Team — not checked for Sinuxyl
        $julie->update(['status' => 'login']);
        $julie->products()->sync([]);
        TsaShift::where('team', 'SH Naturals')->where('id', '!=', $gemma->id)->update(['status' => 'logout']);

        $lead = $this->leadFor($gemma);

        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);

        $this->assertSame($julie->id, $lead->fresh()->tsa_id);
        $activity = LeadActivity::where('lead_id', $lead->id)->where('type', 'transferred')->first();
        $this->assertStringContainsString('any-online fallback', $activity->description);
    }

    /**
     * Regression test, 2026-09-15: "look at this it is catered but it is
     * redistributed to marsha" — root-caused live: order #1368220 had
     * already been fully worked (upsell added, delivery updated, tagged,
     * status moved to "Ordered"/status_code 20) but the TSA never logged
     * a Lead-level disposition, so Lead.status stayed 'assigned'; because
     * status_code 20 wasn't in Order::RESOLVED_STATUSES, the redistributor
     * treated it as untouched backlog and handed it to a teammate anyway.
     */
    public function test_a_lead_whose_order_was_already_purchased_is_left_with_the_logged_out_tsa(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $mariel->update(['status' => 'login']);

        $lead = $this->leadFor($gemma, 'assigned', '1368220');
        Order::create(['pancake_order_id' => '1368220', 'status_code' => 20, 'pancake_created_at' => now(), 'pancake_inserted_at' => now(), 'synced_at' => now()]);
        $originalAssignedAt = $lead->assigned_at;

        $gemma->applyStatusChange(TsaShift::STATUS_LOGOUT);

        $lead->refresh();
        $this->assertSame($gemma->id, $lead->tsa_id);
        $this->assertTrue($lead->assigned_at?->eq($originalAssignedAt) ?? $originalAssignedAt === null);
    }
}
