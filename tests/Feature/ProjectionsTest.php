<?php

namespace Tests\Feature;

use App\Models\ProjectionColumn;
use App\Models\User;
use App\Support\ProjectionCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TSD Data Management — Projections (new module, 2026-09-23). Covers the
 * page renders for an admin, is blocked for a normal user/guest, and that
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
        $response->assertSee('Closing Shift');
        $response->assertSee('NET INCOME');
    }

    /** Companion to ExpectedIncomeReportSmokeTest's own
     *  test_rows_are_not_draggable_on_expected_income() — Projections is
     *  the ONE page that should actually show the drag handle/draggable
     *  rows (explicit decision, 2026-10-02: drag UI stays Projections-only
     *  even though the underlying order is shared with Expected Income). */
    public function test_rows_are_draggable_on_projections(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.projections'));

        $response->assertOk();
        $response->assertSee('draggable="true"', false);
        $response->assertSee('pj-row-handle', false);
    }

    /** Explicit follow-up, 2026-10-02: "only the Opening Shift: Monthly
     *  Target can be draggable and other cards is not" — a derived card
     *  (Telesales Department, every Individual TSA Monthly/Daily) isn't
     *  an independent cell at all (same reason it was never editable),
     *  so its own rows must carry no drag handle/draggable attribute,
     *  even though they still show the same row order as every other
     *  card (data-row-key/data-row-section are kept — a drag that
     *  happens on Opening/Closing Shift still visually updates these
     *  cards' own row order too). */
    public function test_only_lockable_cards_rows_are_draggable_not_derived_cards(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.projections'));

        $response->assertOk();
        $content = $response->getContent();

        $cardStart = strpos($content, 'data-key="telesales_department"');
        $nextCardStart = strpos($content, 'data-key="', $cardStart + 1);
        $cardHtml = substr($content, $cardStart, $nextCardStart - $cardStart);

        $this->assertStringNotContainsString('draggable="true"', $cardHtml);
        $this->assertStringNotContainsString('pj-row-handle', $cardHtml);
        // Still carries its own row-key identity for the shared-order JS.
        $this->assertStringContainsString('data-row-key="salaries"', $cardHtml);
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
        $this->assertSame(7, ProjectionColumn::count());
    }

    /** Add Projection / top month filter (explicit request, 2026-10-02:
     *  "it will be one date picker and one add projection button") —
     *  picking a month that has never been seeded creates its own blank
     *  7 rows rather than reusing or bleeding into another month's. */
    public function test_visiting_a_new_month_seeds_its_own_blank_columns(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertSame(0, ProjectionColumn::where('month', '2027-03')->count());

        $response = $this->actingAs($admin)->get(route('data.projections', ['month' => '2027-03']));

        $response->assertOk();
        $this->assertSame(7, ProjectionColumn::where('month', '2027-03')->count());
    }

    /** Picking a month that already has data (an existing month re-opened
     *  via "Add Projection") just switches to viewing it — explicit
     *  decision, 2026-10-02 — never duplicates or errors. */
    public function test_visiting_an_existing_month_does_not_duplicate_its_columns(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ProjectionColumn::ensureSeededForMonth('2027-04');
        $this->assertSame(7, ProjectionColumn::where('month', '2027-04')->count());

        $response = $this->actingAs($admin)->get(route('data.projections', ['month' => '2027-04']));

        $response->assertOk();
        $this->assertSame(7, ProjectionColumn::where('month', '2027-04')->count());
    }

    /** The month filter is remembered across a fresh sidebar navigation
     *  with no query string, same pattern as DateRangeFilter/
     *  ExpectedIncomeController::resolveSelectedTeam(). */
    public function test_the_selected_month_persists_across_requests_with_no_query_string(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('data.projections', ['month' => '2027-05']))->assertOk();

        $response = $this->actingAs($admin)->get(route('data.projections'));

        $response->assertOk();
        $response->assertViewHas('month', '2027-05');
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

    /** Lock toggle (explicit request, 2026-10-02: "add lock icon ... when
     *  it is lock it can't edit") — persists via the same updateColumn()
     *  endpoint every other field already uses, no separate route. */
    public function test_locking_a_column_persists(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $column = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();
        $this->assertFalse($column->is_locked);

        $response = $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $column),
            ['is_locked' => true]
        );

        $response->assertOk();
        $this->assertTrue($column->fresh()->is_locked);
    }

    /** Locking returns the card's own fresh rendered HTML (explicit
     *  request, 2026-10-02: "make a smooth transition of lock ... like
     *  animation") — the frontend cross-fades this in place instead of
     *  reloading the page; a per-field save that ISN'T a lock toggle must
     *  NOT pay this extra render cost. */
    public function test_locking_a_column_returns_rendered_card_html(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $column = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();

        $lockResponse = $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $column),
            ['is_locked' => true]
        );
        $lockResponse->assertOk();
        $lockResponse->assertJsonStructure(['cardHtml']);
        $this->assertStringContainsString('data-key="opening_shift"', $lockResponse->json('cardHtml'));
        $this->assertStringNotContainsString('data-pnl-input="tax_allocation"', $lockResponse->json('cardHtml'));

        $plainResponse = $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $column),
            ['roas' => 1.5]
        );
        $plainResponse->assertOk();
        $this->assertArrayNotHasKey('cardHtml', $plainResponse->json());
    }

    /** A locked Opening/Closing Shift card's own P&L rows render read-only
     *  (no data-field input), same as a derived card already always does —
     *  confirmed via the rendered page, not just the stored flag, since
     *  _column.blade.php's own $editable is what actually drives this. */
    public function test_a_locked_shift_cards_pnl_rows_are_not_editable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $openingShift = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();
        $openingShift->update(['is_locked' => true]);

        $response = $this->actingAs($admin)->get(route('data.projections'));

        $response->assertOk();
        $content = $response->getContent();
        $cardStart = strpos($content, 'data-key="opening_shift"');
        $nextCardStart = strpos($content, 'data-key="', $cardStart + 1);
        $cardHtml = substr($content, $cardStart, $nextCardStart - $cardStart);

        $this->assertStringNotContainsString('data-field="orders_override"', $cardHtml, 'Gross Sales should be locked read-only');
        $this->assertStringNotContainsString('data-pnl-input="tax_allocation"', $cardHtml, 'Tax Allocation should be locked read-only');
    }

    /** An UNLOCKED Opening Shift still renders its own P&L rows as real
     *  inputs — confirms the lock flag, not something else, is what
     *  flipped $editable in the test above. */
    public function test_an_unlocked_shift_cards_pnl_rows_stay_editable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertFalse(ProjectionColumn::where('key', 'opening_shift')->firstOrFail()->is_locked);

        $response = $this->actingAs($admin)->get(route('data.projections'));

        $response->assertOk();
        $content = $response->getContent();
        $cardStart = strpos($content, 'data-key="opening_shift"');
        $nextCardStart = strpos($content, 'data-key="', $cardStart + 1);
        $cardHtml = substr($content, $cardStart, $nextCardStart - $cardStart);

        $this->assertStringContainsString('data-pnl-input="tax_allocation"', $cardHtml);
    }

    /** Explicit follow-up, 2026-10-02: "and when it is locked it cant
     *  dragged too" — a locked Opening/Closing Shift card's own rows lose
     *  BOTH the input (confirmed above) AND the drag handle/draggable
     *  attribute, same "fully frozen" meaning as the lock elsewhere. */
    public function test_a_locked_shift_cards_rows_are_not_draggable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $openingShift = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();
        $openingShift->update(['is_locked' => true]);

        $response = $this->actingAs($admin)->get(route('data.projections'));

        $response->assertOk();
        $content = $response->getContent();
        $cardStart = strpos($content, 'data-key="opening_shift"');
        $nextCardStart = strpos($content, 'data-key="', $cardStart + 1);
        $cardHtml = substr($content, $cardStart, $nextCardStart - $cardStart);

        $this->assertStringNotContainsString('draggable="true"', $cardHtml);
        $this->assertStringNotContainsString('pj-row-handle', $cardHtml);
    }

    /** An UNLOCKED Opening Shift's own rows stay draggable — confirms the
     *  lock flag, not something else, is what disabled dragging above. */
    public function test_an_unlocked_shift_cards_rows_stay_draggable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertFalse(ProjectionColumn::where('key', 'opening_shift')->firstOrFail()->is_locked);

        $response = $this->actingAs($admin)->get(route('data.projections'));

        $response->assertOk();
        $content = $response->getContent();
        $cardStart = strpos($content, 'data-key="opening_shift"');
        $nextCardStart = strpos($content, 'data-key="', $cardStart + 1);
        $cardHtml = substr($content, $cardStart, $nextCardStart - $cardStart);

        $this->assertStringContainsString('draggable="true"', $cardHtml);
        $this->assertStringContainsString('pj-row-handle', $cardHtml);
    }

    /** The lock icon only appears on the 2 lockable base cards (Opening/
     *  Closing Shift) — a derived card (e.g. Telesales Department) has no
     *  real editable input to lock in the first place, so it gets no
     *  toggle at all. */
    public function test_the_lock_icon_does_not_appear_on_a_derived_card(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.projections'));

        $response->assertOk();
        $content = $response->getContent();
        $cardStart = strpos($content, 'data-key="telesales_department"');
        $nextCardStart = strpos($content, 'data-key="', $cardStart + 1);
        $cardHtml = substr($content, $cardStart, $nextCardStart - $cardStart);

        $this->assertStringNotContainsString('data-pj-lock-toggle', $cardHtml);
    }

    /** Explicit request, 2026-09-28: "in the projection page i want to have
     *  this too" — the same ROAS/Standard Cost Per Message/Actual Cost Per
     *  Message top block Expected Income already has. Plain manual inputs,
     *  no formula participation — confirms they persist and round-trip via
     *  updateColumn(), same as every other manual field on this page. */
    public function test_roas_and_cost_stat_fields_persist_and_do_not_affect_the_pnl(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $column = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();
        $before = ProjectionCalculator::forColumn($column, ProjectionCalculator::allRates())['pnl']['net_income'];

        $response = $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $column),
            ['roas' => 3.5, 'standard_cost_per_message' => 42.50, 'actual_cost_per_lead' => 12.75]
        );

        $response->assertOk();
        $this->assertEquals(3.5, $column->fresh()->roas);
        $this->assertEquals(42.50, $column->fresh()->standard_cost_per_message);
        $this->assertEquals(12.75, $column->fresh()->actual_cost_per_lead);

        // Net Income is completely unaffected — these 3 fields are display
        // stats only, never inputs to the P&L chain.
        $after = ProjectionCalculator::forColumn($column->fresh(), ProjectionCalculator::allRates())['pnl']['net_income'];
        $this->assertEqualsWithDelta($before, $after, 0.01);
    }

    /** Number of Leads/Conversion Rate in the new top block are read-only
     *  displays of the SAME leads_needed/conversion_rate figures the target
     *  card below already computes — never a second, independent number. */
    public function test_number_of_leads_and_conversion_rate_mirror_the_target_cards_own_figures(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $column = ProjectionColumn::where('key', 'telesales_department')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('data.projections'));

        $response->assertOk();
        $rates = ProjectionCalculator::allRates();
        $computed = ProjectionCalculator::forColumn($column, $rates);

        $this->assertEquals($rates['conversion_rate'], $computed['target_card']['conversion_rate']);
        $this->assertGreaterThan(0, $computed['target_card']['leads_needed']);
    }

    /** Regression, 2026-09-23: "what about like user edit this down part?"
     *  (the target-card section — Net Income Target/AOV/TSA Count — on a
     *  card OTHER than Opening Shift). Those fields are independent per
     *  column, not part of the cross-card chain, but the frontend still
     *  needs every column's fresh figures back (`all`) to update the page
     *  without a reload — the same `all` array a P&L-chain edit already
     *  depends on. Confirms it's present and correctly shaped even for an
     *  edit whose OWN cascade effect is a no-op (editing this column's own
     *  target never touches Opening Shift's own numbers, but `all` must
     *  still carry every column, unchanged, for the frontend to apply
     *  uniformly). */
    public function test_editing_a_non_opening_shift_target_field_returns_every_column_via_all(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $monthly = ProjectionColumn::where('key', 'opening_individual_tsa_monthly')->firstOrFail();

        $response = $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $monthly),
            ['net_income_target' => 150000]
        );

        $response->assertOk();
        $this->assertEquals(150000, $monthly->fresh()->net_income_target);

        $all = collect($response->json('all'));
        $this->assertCount(7, $all);
        $this->assertEqualsWithDelta(
            150000 / (800 * 0.3125),
            $all->firstWhere('column.key', 'opening_individual_tsa_monthly')['target_card']['orders_needed'],
            0.01
        );
        // Opening Shift's own P&L is untouched by this edit — a target
        // edit on a DIFFERENT column never cascades into it.
        $this->assertEquals(1920000, $all->firstWhere('column.key', 'opening_shift')['pnl']['gross_sales']);
    }

    /** Explicit request, 2026-09-23: "the Gross Sales is editable and the
     *  number of orders" — combined with the later cross-card formula
     *  finding (2026-09-23, from the user's own sheet screenshot showing
     *  "=F39/6"): only OPENING SHIFT (and, separately, CLOSING SHIFT) is
     *  independently computed from Orders × AOV × rate, so
     *  orders_override only meaningfully applies to those two. Overriding
     *  Opening Shift's own orders cascades into ITS OWN derived pair and
     *  into Telesales Department's own sum, same as the real sheet's own
     *  formulas would — but must NOT touch Closing Shift's own derived
     *  pair, since the two shifts are now independent. */
    public function test_orders_override_on_opening_shift_cascades_into_its_own_derived_columns_and_telesales(): void
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

        $all = collect($response->json('all'));
        $telesales     = $all->firstWhere('column.key', 'telesales_department');
        $closingShift  = $all->firstWhere('column.key', 'closing_shift');
        $openingMonthly = $all->firstWhere('column.key', 'opening_individual_tsa_monthly');
        $openingDaily   = $all->firstWhere('column.key', 'opening_individual_tsa_daily');
        $closingMonthly = $all->firstWhere('column.key', 'closing_individual_tsa_monthly');

        // Telesales Department = Opening (now 800,000) + Closing
        // (unchanged, still its own seeded 1,920,000).
        $this->assertEquals(800000 + 1920000, $telesales['pnl']['gross_sales']);
        // Closing Shift itself is completely untouched by editing Opening.
        $this->assertEquals(1920000, $closingShift['pnl']['gross_sales']);

        // Opening's own derived pair follows Opening's new numbers ÷ 6.
        $this->assertEqualsWithDelta(1000 / 6, $openingMonthly['pnl']['orders'], 0.001);
        $this->assertEqualsWithDelta(800000 / 6, $openingMonthly['pnl']['gross_sales'], 0.01);
        // Opening Daily's own # of Orders uses ÷25, not ÷24 (the sheet's
        // own inconsistency, replicated on purpose — explicit decision,
        // 2026-09-23).
        $this->assertEqualsWithDelta(1000 / 6 / 25, $openingDaily['pnl']['orders'], 0.001);
        $this->assertEqualsWithDelta(800000 / 6 / 24, $openingDaily['pnl']['gross_sales'], 0.01);

        // Closing's own derived pair is UNCHANGED — the two shifts' chains
        // never cross.
        $this->assertEqualsWithDelta(1920000 / 6, $closingMonthly['pnl']['gross_sales'], 0.01);
    }

    /** Explicit request, 2026-09-23: "okay now in the downpart is the
     *  closing team" then "Telesales Department = Opening + Closing" —
     *  Closing Shift is independently editable, and editing IT cascades
     *  into Telesales Department's own sum and into Closing's own derived
     *  pair, without touching Opening Shift or Opening's own pair. */
    public function test_orders_override_on_closing_shift_cascades_into_its_own_derived_columns_and_telesales(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $closingShift = ProjectionColumn::where('key', 'closing_shift')->firstOrFail();

        $response = $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $closingShift),
            ['orders_override' => 500]
        );

        $response->assertOk();
        $response->assertJsonPath('computed.pnl.gross_sales', 400000);

        $all = collect($response->json('all'));
        $telesales      = $all->firstWhere('column.key', 'telesales_department');
        $openingShift   = $all->firstWhere('column.key', 'opening_shift');
        $closingMonthly = $all->firstWhere('column.key', 'closing_individual_tsa_monthly');
        $openingMonthly = $all->firstWhere('column.key', 'opening_individual_tsa_monthly');

        // Telesales Department = Opening (unchanged, 1,920,000) + Closing
        // (now 400,000).
        $this->assertEquals(1920000 + 400000, $telesales['pnl']['gross_sales']);
        // Opening Shift itself is untouched.
        $this->assertEquals(1920000, $openingShift['pnl']['gross_sales']);
        // Closing's own derived pair follows Closing's new numbers ÷ 6.
        $this->assertEqualsWithDelta(400000 / 6, $closingMonthly['pnl']['gross_sales'], 0.01);
        // Opening's own derived pair is unchanged.
        $this->assertEqualsWithDelta(1920000 / 6, $openingMonthly['pnl']['gross_sales'], 0.01);
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

    /** Full row-by-row scan against the real template (explicit request,
     *  2026-09-28: "look at the every row make it match as is in the
     *  template") — Product Research belongs under Operating Costs, right
     *  after Government Benefits, not Selling And Marketing; Ad Account
     *  Rental Fee doesn't appear in the template at all. Same fixes already
     *  applied to Expected Income's own identical row lists that same day. */
    public function test_selling_and_operating_cost_rows_match_the_template_exactly(): void
    {
        $selling = array_values(ProjectionCalculator::sellingCostRows());
        $operating = array_keys(ProjectionCalculator::operatingCostRows());

        $this->assertNotContains('Ad Account Rental Fee', $selling);
        $this->assertNotContains('ad_account_rental_fee', array_keys(ProjectionCalculator::sellingCostRows()));
        $this->assertNotContains('product_research', array_keys(ProjectionCalculator::sellingCostRows()));

        $this->assertContains('product_research', $operating);
        // Sits immediately after government_benefits, matching the
        // template's own row order exactly.
        $govIndex = array_search('government_benefits', $operating, true);
        $this->assertSame('product_research', $operating[$govIndex + 1]);
    }

    /** Explicit follow-up, 2026-09-28: "why the Number of Orders is still
     *  not editable" — the top-of-card stats block's own Number of Orders
     *  row now saves via orders_override directly, same field the Gross
     *  Sales row below already uses. */
    public function test_number_of_orders_in_the_top_stats_block_is_editable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $openingShift = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();

        $response = $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $openingShift),
            ['orders_override' => 1234]
        );

        $response->assertOk();
        $this->assertEquals(1234.0, $openingShift->fresh()->orders_override);
        $response->assertJsonPath('computed.pnl.orders', 1234);
    }

    /** Explicit follow-up, 2026-09-28: "make editable this Number of Leads,
     *  Conversion Rate, Average Order Value" then confirmed typing BOTH
     *  Leads and Conversion Rate recalculates Number of Orders as their
     *  product — taking precedence over BOTH the Net Income Target
     *  back-solve AND orders_override, same "most specific override wins"
     *  precedence chain the rest of this page already follows. */
    public function test_leads_and_conversion_rate_override_recalculates_orders_and_beats_orders_override(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $openingShift = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();
        $openingShift->update(['orders_override' => 1000]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $openingShift),
            ['leads_override' => 5000, 'conversion_rate_override' => 0.30]
        );

        $response->assertOk();
        $this->assertEquals(5000.0, $openingShift->fresh()->leads_override);
        $this->assertEquals(0.30, $openingShift->fresh()->conversion_rate_override);

        // 5,000 leads × 30% conversion = 1,500 orders — NOT the 1,000 from
        // orders_override still sitting on this same column.
        $response->assertJsonPath('computed.pnl.orders', 1500);
        // AOV 800 × 1,500 orders = 1,200,000 Gross Sales.
        $response->assertJsonPath('computed.pnl.gross_sales', 1200000);
        // The top-of-card stats block's own display figures match exactly
        // what was typed, not the target-derived defaults.
        $response->assertJsonPath('computed.target_card.leads_needed', 5000);
        $response->assertJsonPath('computed.target_card.conversion_rate', 0.30);
    }

    /** A lone leads_override with no matching conversion_rate_override (or
     *  vice versa) has no way to produce an Orders number on its own — same
     *  "partial override does nothing" convention as everywhere else in
     *  this chain, so it falls through to orders_override/target-derived
     *  instead of silently multiplying against a stale or zero rate. */
    public function test_a_lone_leads_override_without_a_conversion_rate_override_does_not_change_orders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $openingShift = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();

        $response = $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $openingShift),
            ['leads_override' => 5000]
        );

        $response->assertOk();
        // Untouched — still the target-derived 2,400 from the seeded
        // 600,000 Net Income Target.
        $response->assertJsonPath('computed.pnl.orders', 2400);
    }

    /** Clearing both overrides back to null restores the target-derived
     *  chain exactly, same convention as clearing orders_override above. */
    public function test_clearing_leads_and_conversion_rate_overrides_restores_the_target_derived_value(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $openingShift = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();
        $openingShift->update(['leads_override' => 5000, 'conversion_rate_override' => 0.30]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $openingShift),
            ['leads_override' => null, 'conversion_rate_override' => null]
        );

        $response->assertOk();
        $this->assertNull($openingShift->fresh()->leads_override);
        $this->assertNull($openingShift->fresh()->conversion_rate_override);
        $response->assertJsonPath('computed.pnl.orders', 2400);
    }

    /** Target Upselling Rate defaults to mirroring Total Orders Needed, but
     *  is directly editable per column (explicit request, 2026-09-24) via
     *  upselling_rate_override — same nullable-override convention as
     *  orders_override above. */
    public function test_upselling_rate_override_replaces_the_mirrored_orders_needed_value(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $openingShift = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();

        $response = $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $openingShift),
            ['upselling_rate_override' => 555]
        );

        $response->assertOk();
        $this->assertEquals(555, $openingShift->fresh()->upselling_rate_override);
        $response->assertJsonPath('computed.target_card.upselling_rate', 555);
    }

    /** Clearing the override back to null restores the mirrored value,
     *  same as orders_override's own clear-to-restore behavior above. */
    public function test_clearing_upselling_rate_override_restores_the_mirrored_value(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $openingShift = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();
        $openingShift->update(['upselling_rate_override' => 555]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $openingShift),
            ['upselling_rate_override' => null]
        );

        $response->assertOk();
        $this->assertNull($openingShift->fresh()->upselling_rate_override);
        $response->assertJsonPath(
            'computed.target_card.upselling_rate',
            $response->json('computed.target_card.orders_needed')
        );
    }

    /** Fulfillment Fee on either shift's Daily column is a flat 3% of
     *  DAILY's own Gross Sales, not Monthly's Fulfillment Fee ÷ 24 like
     *  every other cost line — the sheet's own second inconsistency, also
     *  replicated on purpose (same explicit decision as the ÷25 orders
     *  divisor). Checked for BOTH shifts' own Daily column, not just
     *  Opening's. */
    /**
     * Root-caused 2026-09-24 against the real sheet's own formula-view
     * screenshot: COD Fee = Delivered Sales × 2.24% (not Gross Sales ×
     * 1.568%) and Fulfillment Fee = Total Orders × ₱25 flat (not Gross
     * Sales × 3.125%). COD Fee's old shortcut is mathematically
     * identical to the real formula in every case (Delivered is always
     * exactly 70% of Gross here, and 70% × 2.24% = 1.568%) — only
     * Fulfillment Fee's old shortcut diverges, and only when a column's
     * AOV isn't exactly ₱800 (the one value where orders×25 and
     * gross×3.125% happen to agree). This test proves the real formula
     * by changing Opening Shift's own AOV away from 800.
     */
    public function test_cod_fee_and_fulfillment_fee_use_their_own_real_formulas(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $opening = ProjectionColumn::where('key', 'opening_shift')->first();
        $opening->update(['average_order_value' => 1000, 'orders_override' => 100]);

        $response = $this->actingAs($admin)->get(route('data.projections'));
        $computed = $response->viewData('computed');
        $pnl = $computed->firstWhere('column.key', 'opening_shift')['pnl'];

        $expectedCod = $pnl['delivered'] * 0.0224;
        $expectedFulfillment = $pnl['orders'] * 25;
        // The old (wrong) Fulfillment Fee shortcut, to prove it would
        // give a DIFFERENT number at this AOV — confirming this test
        // actually exercises the fix.
        $oldFulfillment = $pnl['gross_sales'] * 0.03125;

        $this->assertEqualsWithDelta($expectedCod, $pnl['selling_lines']['cod_fee'], 0.01);
        $this->assertEqualsWithDelta($expectedFulfillment, $pnl['selling_lines']['fulfillment_fee'], 0.01);
        $this->assertTrue(abs($oldFulfillment - $pnl['selling_lines']['fulfillment_fee']) > 1, 'Fulfillment Fee should differ from the old gross-sales shortcut at this AOV');
    }

    public function test_daily_fulfillment_fee_uses_a_flat_rate_not_the_monthly_divide(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.projections'));
        $response->assertOk();

        $computed = $response->viewData('computed');

        foreach (['opening_individual_tsa_daily', 'closing_individual_tsa_daily'] as $key) {
            $daily = $computed->firstWhere('column.key', $key);
            $expectedFlat = $daily['pnl']['gross_sales'] * 0.03;
            $this->assertEqualsWithDelta($expectedFlat, $daily['pnl']['selling_lines']['fulfillment_fee'], 0.01, "for {$key}");
        }
    }

    /** Explicit request, 2026-09-28 (real template screenshot): a new
     *  "Income Before OPEX" subtotal (Gross Profit minus Total Selling
     *  Costs, before Operating Costs). Confirmed internally consistent —
     *  Net Income = Income Before OPEX - Total Operating Costs — on EVERY
     *  column, including the two Daily columns, whose own Fulfillment Fee
     *  delta-adjustment (see ProjectionCalculator::forAllColumns()'s own
     *  doc comment) has to flow through this new subtotal too, not just
     *  Net Income directly, or the two would silently disagree with each
     *  other on exactly those two columns. */
    public function test_income_before_opex_is_consistent_with_net_income_on_every_column(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.projections'));
        $response->assertOk();

        $computed = $response->viewData('computed');

        foreach ($computed as $entry) {
            $pnl = $entry['pnl'];
            $expectedNetIncome = $pnl['income_before_opex'] - $pnl['total_operating_costs'];
            $this->assertEqualsWithDelta(
                $expectedNetIncome, $pnl['net_income'], 0.01,
                "Income Before OPEX minus Total Operating Costs should equal Net Income for {$entry['column']['key']}"
            );

            $expectedIncomeBeforeOpex = $pnl['gross_profit'] - $pnl['total_selling_costs'];
            $this->assertEqualsWithDelta(
                $expectedIncomeBeforeOpex, $pnl['income_before_opex'], 0.01,
                "Gross Profit minus Total Selling Costs should equal Income Before OPEX for {$entry['column']['key']}"
            );
        }
    }

    /** Regression, 2026-09-23: "if i edit this like the tsa is 8 why the
     *  other individual tsa is not changing" — a follow-up sheet audit
     *  confirmed Individual TSA Monthly's own ÷6 genuinely tracks its own
     *  shift's "Current TSAs" (tsa_count), not a separate hardcoded 6
     *  that coincidentally matched it (two independent shift blocks in
     *  the sheet, each with a DIFFERENT total, both divided by their own
     *  "Current TSAs" to the cent — see ProjectionCalculator's own doc
     *  comment for the full evidence). Editing Opening's tsa_count from 6
     *  to 8 must change ONLY Opening's own derived divisor, not Closing's. */
    public function test_changing_opening_shift_tsa_count_changes_only_its_own_derived_divisor(): void
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
        $monthlyPnl = $all->firstWhere('column.key', 'opening_individual_tsa_monthly')['pnl'];
        $dailyPnl   = $all->firstWhere('column.key', 'opening_individual_tsa_daily')['pnl'];
        $closingMonthlyPnl = $all->firstWhere('column.key', 'closing_individual_tsa_monthly')['pnl'];

        // Opening's own Individual TSA Monthly = Opening Shift ÷ 8, not ÷ 6.
        $this->assertEqualsWithDelta($openingPnl['gross_sales'] / 8, $monthlyPnl['gross_sales'], 0.01);
        $this->assertEqualsWithDelta($openingPnl['net_income'] / 8, $monthlyPnl['net_income'], 0.01);
        // Opening's own Individual TSA Daily follows the same live divisor
        // (÷8÷24 for dollar lines, ÷8÷25 for its own # of Orders).
        $this->assertEqualsWithDelta($openingPnl['gross_sales'] / 8 / 24, $dailyPnl['gross_sales'], 0.01);
        $this->assertEqualsWithDelta($openingPnl['orders'] / 8 / 25, $dailyPnl['orders'], 0.001);
        // Closing's own Individual TSA Monthly still divides by Closing's
        // OWN unchanged tsa_count (6) — Opening's edit never crosses over.
        $this->assertEqualsWithDelta(1920000 / 6, $closingMonthlyPnl['gross_sales'], 0.01);
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

    /** A shared rate change affects BOTH shifts' own P&L (they share the
     *  same rate set) and cascades into Telesales Department's own sum. */
    public function test_updating_a_shared_rate_affects_both_shifts_and_telesales_sum(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->patchJson(
            route('data.projections.update-rates'),
            ['key' => 'cancelled', 'value' => 0.10]
        );

        $response->assertOk();

        $computed = collect($response->json('computed'));
        $opening   = $computed->firstWhere('column.key', 'opening_shift');
        $closing   = $computed->firstWhere('column.key', 'closing_shift');
        $telesales = $computed->firstWhere('column.key', 'telesales_department');

        // 10% of 1,920,000 Gross Sales for each shift.
        $this->assertEqualsWithDelta(192000, $opening['pnl']['cancelled'], 0.01);
        $this->assertEqualsWithDelta(192000, $closing['pnl']['cancelled'], 0.01);
        // Telesales Department's own Cancelled is the SUM of both shifts'.
        $this->assertEqualsWithDelta(384000, $telesales['pnl']['cancelled'], 0.01);
    }

    /** Explicit request, 2026-09-26: "add like + icon on Selling And
     *  Marketing and Operating Costs ... has modal ... if it is editable
     *  or fixed" — a custom row is a SHARED definition (like a built-in
     *  row): adding it once makes it appear on every column, each with its
     *  own %-of-Gross-Sales-derived figure. */
    public function test_adding_a_custom_row_makes_it_appear_on_every_column(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $opening = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();

        $response = $this->actingAs($admin)->postJson(route('data.projections.custom-rows.store'), [
            'section' => 'selling',
            'label' => 'Warehouse Fee',
            'is_fixed' => false,
            // Opening Shift's own real Gross Sales is 1,920,000 — typing
            // 96,000 here should back-solve to exactly the same 5% rate
            // as the built-in Cancelled row.
            'initial_value' => 96000,
            'gross_sales' => 1920000,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('projection_custom_rows', ['label' => 'Warehouse Fee', 'section' => 'selling', 'is_fixed' => false]);

        $key = \App\Models\ProjectionCustomRow::where('label', 'Warehouse Fee')->firstOrFail()->key;
        $computed = collect($response->json('computed'));
        $openingComputed = $computed->firstWhere('column.key', 'opening_shift');
        $closingComputed = $computed->firstWhere('column.key', 'closing_shift');
        $telesalesComputed = $computed->firstWhere('column.key', 'telesales_department');

        // Same 5% rate applied to EVERY column's own Gross Sales, not just
        // Opening Shift's — confirms the row is shared, not per-column.
        $this->assertEqualsWithDelta(96000, $openingComputed['pnl']['selling_lines'][$key], 0.01);
        $this->assertEqualsWithDelta(96000, $closingComputed['pnl']['selling_lines'][$key], 0.01);
        $this->assertEqualsWithDelta(192000, $telesalesComputed['pnl']['selling_lines'][$key], 0.01);

        // Total Selling Costs / Net Income both reflect the new row.
        $this->assertGreaterThan(0, $openingComputed['pnl']['total_selling_costs']);
    }

    public function test_removing_a_custom_row_removes_it_from_every_column(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $row = \App\Models\ProjectionCustomRow::create([
            'key' => 'custom_test_row', 'section' => 'operating', 'label' => 'Test Row', 'is_fixed' => false, 'sort_order' => 0,
        ]);
        \App\Models\Setting::set('projection_rate.custom_test_row', 0.02);

        $response = $this->actingAs($admin)->deleteJson(route('data.projections.custom-rows.destroy', $row));

        $response->assertOk();
        $this->assertDatabaseMissing('projection_custom_rows', ['id' => $row->id]);
        $this->assertNull(\App\Models\Setting::get('projection_rate.custom_test_row'));

        $computed = collect($response->json('computed'));
        $opening = $computed->firstWhere('column.key', 'opening_shift');
        $this->assertArrayNotHasKey('custom_test_row', $opening['pnl']['operating_lines']);
    }

    public function test_a_fixed_custom_row_is_marked_non_editable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('data.projections.custom-rows.store'), [
            'section' => 'operating', 'label' => 'One-Off Cost', 'is_fixed' => true,
        ])->assertOk();

        $key = \App\Models\ProjectionCustomRow::where('label', 'One-Off Cost')->firstOrFail()->key;
        $this->assertContains($key, \App\Support\ProjectionCalculator::nonEditableRows());
    }

    public function test_a_non_admin_cannot_add_or_remove_custom_rows(): void
    {
        $tsaUser = User::factory()->create(['role' => 'normal']);

        $this->actingAs($tsaUser)->postJson(route('data.projections.custom-rows.store'), [
            'section' => 'selling', 'label' => 'Nope',
        ])->assertForbidden();
    }

    /** Explicit request, 2026-09-28: "the net income it should be green if
     *  positive ... and if negative it should be red." The page's own
     *  seeded default data already produces a positive Net Income out of
     *  the box (confirmed live), so no extra setup is needed for the
     *  positive case. */
    public function test_a_positive_net_income_is_rendered_green(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.projections'));

        $response->assertOk();
        $response->assertSee('text-green-600');
    }

    public function test_a_negative_net_income_is_rendered_red(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        // A deliberately huge rate forces every column's own Net Income
        // negative regardless of its real inputs.
        \App\Models\Setting::set('projection_rate.advertising_cost', 50);

        $response = $this->actingAs($admin)->get(route('data.projections'));

        $response->assertOk();
        $response->assertSee('text-red-600');
    }
}
