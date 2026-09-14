<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TsaShift;
use App\Support\RoundRobinAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift. */
class RoundRobinAssignerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * NOTE (adapted, not verbatim): unlike call-tracker's original
     * migrations (which seeded product_tsa directly, since that app owned
     * fresh products/tsas tables), the merged app's product_tsa table is
     * deliberately NOT seeded by any migration — it's wired up by the
     * one-time `calltracker:reconcile-roster` command (Phase 4), matching
     * call-tracker's 7 seed products/TSAs against tsd-reports' pre-existing
     * rows by name/key (see ReconcileCallTrackerRosterTest). Every ported
     * test here that exercises round-robin rotation needs that reconciler
     * to have run first, or every product's roster is empty.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('calltracker:reconcile-roster');
    }

    public function test_rotates_through_a_products_tsas_in_position_order(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();

        // Seeded order for SH Naturals products is Gemma, Mariel, Kathleen.
        $this->assertSame('Gemma', RoundRobinAssigner::next($product)->tsa_key);
        $this->assertSame('Mariel', RoundRobinAssigner::next($product)->tsa_key);
        $this->assertSame('Kathleen', RoundRobinAssigner::next($product)->tsa_key);
        // Wraps back to the start.
        $this->assertSame('Gemma', RoundRobinAssigner::next($product)->tsa_key);
    }

    public function test_two_different_products_rotate_independently(): void
    {
        $sinuxyl = Product::where('display_name', 'SINUXYL')->first();
        $audicure = Product::where('display_name', 'AUDICURE')->first();

        RoundRobinAssigner::next($sinuxyl); // Gemma
        RoundRobinAssigner::next($sinuxyl); // Mariel

        // AudiCure's own rotation hasn't been touched yet — starts fresh at Gemma.
        $this->assertSame('Gemma', RoundRobinAssigner::next($audicure)->tsa_key);
    }

    public function test_returns_null_when_a_product_has_no_active_tsas(): void
    {
        $product = Product::create(['display_name' => 'ORPHAN PRODUCT', 'match_keyword' => 'ORPHAN', 'team' => 'SH Naturals']);

        $this->assertNull(RoundRobinAssigner::next($product));
    }

    public function test_skips_a_deactivated_tsa(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        TsaShift::where('tsa_key', 'Mariel')->update(['active' => false]);

        $this->assertSame('Gemma', RoundRobinAssigner::next($product)->tsa_key);
        // Mariel is skipped — goes straight to Kathleen.
        $this->assertSame('Kathleen', RoundRobinAssigner::next($product)->tsa_key);
    }

    /** Explicit request (2026-08-08): a TSA on Break/DNA Huddle/Coaching/
     *  Logout (TsaShift::status, set via the topbar dropdown) is skipped just
     *  like a deactivated one — still `active` in the long-term sense, just
     *  not available for a live call right now. */
    public function test_skips_a_tsa_who_is_not_logged_in(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        TsaShift::where('tsa_key', 'Mariel')->update(['status' => TsaShift::STATUS_BREAK]);

        $this->assertSame('Gemma', RoundRobinAssigner::next($product)->tsa_key);
        // Mariel is skipped — goes straight to Kathleen.
        $this->assertSame('Kathleen', RoundRobinAssigner::next($product)->tsa_key);
    }

    public function test_returns_null_when_every_tsa_on_the_roster_is_not_logged_in(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        TsaShift::query()->update(['status' => TsaShift::STATUS_LOGOUT]);

        $this->assertNull(RoundRobinAssigner::next($product));
    }

    /** Locked (admin-only, see TsaShift::STATUS_LOCKED) is still just a value of
     *  the same `status` column round-robin already filters on — no special
     *  casing needed, but worth its own explicit test since it's the state
     *  most likely to matter here (an admin forcibly pulling someone out). */
    public function test_skips_a_tsa_whose_status_is_locked(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        TsaShift::where('tsa_key', 'Mariel')->update(['status' => TsaShift::STATUS_LOCKED]);

        $this->assertSame('Gemma', RoundRobinAssigner::next($product)->tsa_key);
        $this->assertSame('Kathleen', RoundRobinAssigner::next($product)->tsa_key);
    }

    public function test_a_tsa_who_logs_back_in_is_eligible_again(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        TsaShift::where('tsa_key', 'Mariel')->update(['status' => TsaShift::STATUS_BREAK]);
        RoundRobinAssigner::next($product); // Gemma
        RoundRobinAssigner::next($product); // Kathleen (Mariel skipped)

        TsaShift::where('tsa_key', 'Mariel')->update(['status' => TsaShift::STATUS_LOGIN]);

        // Wraps back to Gemma next, same rotation position as before — Mariel
        // rejoins the roster but the pointer isn't rewound to find her sooner.
        $this->assertSame('Gemma', RoundRobinAssigner::next($product)->tsa_key);
    }

    /** A capped TSA is skipped in favor of an uncapped one online for the
     *  same product — the ordinary case this cap exists for. */
    public function test_skips_a_tsa_who_has_reached_their_daily_lead_cap(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['daily_lead_cap' => 1]);
        \App\Models\Lead::create([
            'pancake_order_id' => 'cap-1', 'customer_name' => 'Already Assigned',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
            'assigned_at' => now(), 'pancake_created_at' => now(),
        ]);

        // Gemma already has 1 assigned today == her cap of 1 — skipped.
        $this->assertSame('Mariel', RoundRobinAssigner::next($product)->tsa_key);
    }

    /**
     * Regression test, 2026-09-14 — this is the REVERSAL of a same-day fix
     * (b44cfc9) that briefly bypassed the cap when the capped TSA was the
     * only one online. That bypass caused a real production incident the
     * same day: catchUpUnassignedLeads() sweeps the entire backlog with no
     * batch limit, and with the cap bypassed, nothing stopped it from
     * giving a sole online TSA lead after lead after lead — Gemma alone
     * online absorbed 1336 leads in one sweep (cap 75). Explicit follow-up
     * confirmed: "remove the bypass — cap is a hard limit again." A capped
     * TSA now gets null even when they're the ONLY one online for a
     * product — the lead stays unassigned until someone else logs in or
     * the cap resets at midnight, exactly like every other "nobody
     * eligible" case this class already returns null for.
     */
    public function test_a_capped_tsa_gets_nothing_even_when_they_are_the_only_one_online(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        TsaShift::whereIn('tsa_key', ['Mariel', 'Kathleen'])->update(['status' => TsaShift::STATUS_LOGOUT]);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['daily_lead_cap' => 1]);
        \App\Models\Lead::create([
            'pancake_order_id' => 'cap-2', 'customer_name' => 'Already Assigned',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
            'assigned_at' => now(), 'pancake_created_at' => now(),
        ]);

        // Gemma is capped AND the only one online — no bypass, stays null.
        $this->assertNull(RoundRobinAssigner::next($product));
    }
}
