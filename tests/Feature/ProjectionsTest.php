<?php

namespace Tests\Feature;

use App\Models\ProjectionColumn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TSD Data Management — Projections (new module, 2026-09-23). Covers the
 * page renders for an admin, is blocked for a normal user, and that
 * auto-save (updateColumn/updateRates) both persists and returns freshly
 * recomputed figures — see ProjectionCalculator's own doc comment for the
 * formula chain this exercises.
 */
class ProjectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_projections_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.projections'));

        $response->assertOk();
        $response->assertSee('Telesales Department');
        $response->assertSee('NET INCOME');
    }

    /** Regression: the page rendered with zero columns and no error when
     *  the table was migrated but empty (a real dev-environment failure —
     *  a DB reset after the seeding migration already ran, so it never
     *  re-inserted its rows). ProjectionController::index() now calls
     *  ProjectionColumn::ensureSeeded() first. */
    public function test_page_self_heals_when_table_is_empty(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ProjectionColumn::query()->delete();
        $this->assertSame(0, ProjectionColumn::count());

        $response = $this->actingAs($admin)->get(route('data.projections'));

        $response->assertOk();
        $response->assertSee('Telesales Department');
        $this->assertSame(4, ProjectionColumn::count());
    }

    public function test_normal_user_is_blocked(): void
    {
        $user = User::factory()->create(['role' => 'normal']);

        $response = $this->actingAs($user)->get(route('data.projections'));

        $response->assertForbidden();
    }

    /** Explicit request, 2026-09-23: "make it like admin only can edit
     *  like that... the normal user and guess can't edit" — the whole
     *  /data route group already sits inside the outer auth middleware
     *  (routes/web.php) AND its own role:super_admin,admin gate, so a
     *  guest never reaches the role check at all (auth redirects to login
     *  first); confirms that redirect explicitly rather than only testing
     *  the already-logged-in normal-user case above. */
    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('data.projections'));

        $response->assertRedirect(route('login'));
    }

    /** A normal user is blocked from VIEWING (test above), but also from
     *  the write endpoints directly — belt-and-suspenders in case someone
     *  ever guesses/reuses a PATCH URL without going through the page. */
    public function test_normal_user_cannot_update_a_column_or_rate(): void
    {
        $user = User::factory()->create(['role' => 'normal']);
        $column = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();

        $this->actingAs($user)
            ->patchJson(route('data.projections.update-column', $column), ['net_income_target' => 999])
            ->assertForbidden();

        $this->actingAs($user)
            ->patchJson(route('data.projections.update-rates'), ['key' => 'cancelled', 'value' => 0.5])
            ->assertForbidden();

        $this->assertNotEquals(999, $column->fresh()->net_income_target);
    }

    public function test_updating_a_column_field_persists_and_returns_recomputed_figures(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $column = ProjectionColumn::where('key', 'telesales_department')->firstOrFail();

        $response = $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $column),
            ['net_income_target' => 2400000]
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertEquals(2400000, $column->fresh()->net_income_target);

        // Orders Needed = Net Income Target / (AOV * target_margin) = 2,400,000 / (800 * 0.3125) = 9600
        $response->assertJsonPath('computed.target_card.orders_needed', 9600);
    }

    /** Regression, 2026-09-23: "what about like user edit this down part?"
     *  (the target-card section — Net Income Target/AOV/TSA Count — on a
     *  card OTHER than Opening Shift). Those fields are independent per
     *  column, not part of the ×2/÷6/÷24 chain, but the frontend still
     *  needs every column's fresh figures back (`all`) to update the page
     *  without a reload — the same `all` array a P&L-chain edit already
     *  depends on. Confirms it's present and correctly shaped even for an
     *  edit whose OWN cascade effect is a no-op (editing Telesales
     *  Department's own target never touches the other 3 cards' numbers,
     *  but `all` must still carry all 4, unchanged, for the frontend to
     *  apply uniformly). */
    public function test_editing_a_non_opening_shift_target_field_returns_every_column_via_all(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $monthly = ProjectionColumn::where('key', 'individual_tsa_monthly')->firstOrFail();

        $response = $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $monthly),
            ['net_income_target' => 150000]
        );

        $response->assertOk();
        $this->assertEquals(150000, $monthly->fresh()->net_income_target);

        $all = collect($response->json('all'));
        $this->assertCount(4, $all);
        $this->assertEqualsWithDelta(
            150000 / (800 * 0.3125),
            $all->firstWhere('column.key', 'individual_tsa_monthly')['target_card']['orders_needed'],
            0.01
        );
        // Opening Shift's own P&L is untouched by this edit — a target
        // edit on a DIFFERENT column never cascades into it.
        $this->assertEquals(1920000, $all->firstWhere('column.key', 'opening_shift')['pnl']['gross_sales']);
    }

    /** Explicit request, 2026-09-23: "the Gross Sales is editable and the
     *  number of orders" — combined with the later cross-card formula
     *  finding (2026-09-23, from the user's own sheet screenshot showing
     *  "=F39/6"): only OPENING SHIFT is independently computed from
     *  Orders × AOV × rate, so orders_override only meaningfully applies
     *  there. Overriding it cascades into the 3 DERIVED cards (×2/÷6/
     *  ÷6÷24), same as the real sheet's own formulas would. */
    public function test_orders_override_on_opening_shift_cascades_into_every_derived_column(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $openingShift = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();
        // AOV 800 → overriding to 1000 orders gives Gross Sales 800,000.

        $response = $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $openingShift),
            ['orders_override' => 1000]
        );

        $response->assertOk();
        $this->assertEquals(1000.0, $openingShift->fresh()->orders_override);

        $response->assertJsonPath('computed.pnl.orders', 1000);
        $response->assertJsonPath('computed.pnl.gross_sales', 800000);
        // The target card's own orders_needed (from the 600,000 target,
        // untouched by this edit) stays exactly what it always was.
        $response->assertJsonPath('computed.target_card.orders_needed', 2400);

        $telesales = collect($response->json('all'))->firstWhere('column.key', 'telesales_department');
        $monthly   = collect($response->json('all'))->firstWhere('column.key', 'individual_tsa_monthly');
        $daily     = collect($response->json('all'))->firstWhere('column.key', 'individual_tsa_daily');

        // Telesales Department = Opening Shift × 2.
        $this->assertEquals(2000.0, $telesales['pnl']['orders']);
        $this->assertEquals(1600000.0, $telesales['pnl']['gross_sales']);
        // Individual TSA Monthly = Opening Shift ÷ 6.
        $this->assertEqualsWithDelta(1000 / 6, $monthly['pnl']['orders'], 0.001);
        $this->assertEqualsWithDelta(800000 / 6, $monthly['pnl']['gross_sales'], 0.01);
        // Individual TSA Daily's own # of Orders uses ÷25, not ÷24 (the
        // sheet's own inconsistency, replicated on purpose — explicit
        // decision, 2026-09-23).
        $this->assertEqualsWithDelta(1000 / 6 / 25, $daily['pnl']['orders'], 0.001);
        $this->assertEqualsWithDelta(800000 / 6 / 24, $daily['pnl']['gross_sales'], 0.01);
    }

    /** Clearing orders_override back to null restores the target-derived
     *  # of Orders — nullable, not defaulted to 0, so "never overridden"
     *  stays distinguishable from "overridden to zero". */
    public function test_clearing_orders_override_restores_the_target_derived_value(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $openingShift = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();
        $openingShift->update(['orders_override' => 1000]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $openingShift),
            ['orders_override' => null]
        );

        $response->assertOk();
        $this->assertNull($openingShift->fresh()->orders_override);
        $response->assertJsonPath('computed.pnl.orders', 2400);
    }

    /** Fulfillment Fee on the Daily column is a flat 3% of DAILY's own
     *  Gross Sales, not Monthly's Fulfillment Fee ÷ 24 like every other
     *  cost line — the sheet's own second inconsistency, also replicated
     *  on purpose (same explicit decision as the ÷25 orders divisor). */
    public function test_daily_fulfillment_fee_uses_a_flat_rate_not_the_monthly_divide(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.projections'));
        $response->assertOk();

        $computed = $response->viewData('computed');
        $daily = $computed->firstWhere('column.key', 'individual_tsa_daily');

        $expectedFlat = $daily['pnl']['gross_sales'] * 0.03;
        $this->assertEqualsWithDelta($expectedFlat, $daily['pnl']['selling_lines']['fulfillment_fee'], 0.01);
    }

    /** Regression, 2026-09-23: "if i edit this like the tsa is 8 why the
     *  other individual tsa is not changing" — a follow-up sheet audit
     *  confirmed Individual TSA Monthly's own ÷6 genuinely tracks Opening
     *  Shift's own "Current TSAs" (tsa_count), not a separate hardcoded 6
     *  that coincidentally matched it (two independent shift blocks in
     *  the sheet, each with a DIFFERENT total, both divided by their own
     *  "Current TSAs" to the cent — see ProjectionCalculator's own doc
     *  comment for the full evidence). Editing tsa_count from 6 to 8 must
     *  change the divisor live. */
    public function test_changing_opening_shift_tsa_count_changes_individual_monthly_divisor(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $openingShift = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();

        $response = $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $openingShift),
            ['tsa_count' => 8]
        );

        $response->assertOk();
        $this->assertEquals(8, $openingShift->fresh()->tsa_count);

        $all = collect($response->json('all'));
        $openingPnl = $all->firstWhere('column.key', 'opening_shift')['pnl'];
        $monthlyPnl = $all->firstWhere('column.key', 'individual_tsa_monthly')['pnl'];
        $dailyPnl   = $all->firstWhere('column.key', 'individual_tsa_daily')['pnl'];

        // Individual TSA Monthly = Opening Shift ÷ 8, not ÷ 6.
        $this->assertEqualsWithDelta($openingPnl['gross_sales'] / 8, $monthlyPnl['gross_sales'], 0.01);
        $this->assertEqualsWithDelta($openingPnl['net_income'] / 8, $monthlyPnl['net_income'], 0.01);
        // Individual TSA Daily follows the same live divisor (÷8÷24 for
        // dollar lines, ÷8÷25 for its own # of Orders).
        $this->assertEqualsWithDelta($openingPnl['gross_sales'] / 8 / 24, $dailyPnl['gross_sales'], 0.01);
        $this->assertEqualsWithDelta($openingPnl['orders'] / 8 / 25, $dailyPnl['orders'], 0.001);
    }

    public function test_updating_a_shared_rate_recomputes_every_column(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->patchJson(
            route('data.projections.update-rates'),
            ['key' => 'target_margin', 'value' => 0.25]
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('rates.target_margin', 0.25);

        // Telesales Department: 1,200,000 / (800 * 0.25) = 6000
        $telesales = collect($response->json('computed'))
            ->firstWhere('column.key', 'telesales_department');
        $this->assertEquals(6000.0, $telesales['target_card']['orders_needed']);
    }
}
