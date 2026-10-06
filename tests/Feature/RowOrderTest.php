<?php

namespace Tests\Feature;

use App\Models\ProjectionColumn;
use App\Models\ProjectionCustomRow;
use App\Models\RowSortOrder;
use App\Models\User;
use App\Support\ExpectedIncomeCalculator;
use App\Support\ProjectionCalculator;
use App\Support\RowOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TSD Data Management — row drag-reorder (explicit request, 2026-10-02:
 * "can you make the row can be draggable and can change the position by
 * other row", confirmed to cover every row, built-in and custom, with ONE
 * shared order across Projections AND Expected Income). See RowOrder's own
 * doc comment and create_row_sort_orders_table's own for the full design —
 * this table is the single ordering source both calculators'
 * sellingCostRows()/operatingCostRows() read, replacing a plain array_merge
 * that always sorted every built-in before every custom row.
 */
class RowOrderTest extends TestCase
{
    use RefreshDatabase;

    /** A never-touched section lazily seeds in the SAME order the source
     *  PHP constant already had — dragging never happened yet, so nothing
     *  should look reordered on a first load. */
    public function test_a_fresh_section_preserves_the_constants_own_order(): void
    {
        $selling = array_keys(ProjectionCalculator::sellingCostRows());

        $this->assertSame(array_keys(ProjectionCalculator::SELLING_COST_ROWS), $selling);
    }

    /** The exact example from the request: dragging Communication Allowance
     *  to sit after SIL moves it there, and nothing else shifts out of its
     *  relative order. */
    public function test_moving_a_row_after_another_reorders_it_there(): void
    {
        RowOrder::rows(ProjectionCalculator::OPERATING_COST_ROWS, 'operating');

        RowOrder::moveAfter('communication_allowance', 'sil', 'operating');

        $operating = array_keys(ProjectionCalculator::operatingCostRows());
        $silIndex = array_search('sil', $operating, true);
        $this->assertSame('communication_allowance', $operating[$silIndex + 1]);
        // thirteenth_month_allowance (originally BETWEEN communication_allowance
        // and sil) keeps its own relative order against sil, just no longer
        // against communication_allowance.
        $this->assertLessThan(array_search('sil', $operating, true), array_search('thirteenth_month_allowance', $operating, true));
    }

    /** Moving to the very front of the section ($afterRowKey = null). */
    public function test_moving_a_row_to_the_front_with_no_after_key(): void
    {
        RowOrder::rows(ProjectionCalculator::OPERATING_COST_ROWS, 'operating');

        RowOrder::moveAfter('rent', null, 'operating');

        $operating = array_keys(ProjectionCalculator::operatingCostRows());
        $this->assertSame('rent', $operating[0]);
    }

    /** Regression: Collection::search() returns `false` (not null) on no
     *  match, so a stale/wrong-section $afterRowKey (the row it was
     *  meant to land after got deleted between the drag starting and the
     *  PATCH landing, or a client bug) must fall back to the END of the
     *  list, not silently land at position 1 via `false ?? x` never
     *  actually catching the false. */
    public function test_moving_after_a_nonexistent_key_appends_to_the_end_instead_of_position_one(): void
    {
        RowOrder::rows(ProjectionCalculator::OPERATING_COST_ROWS, 'operating');

        RowOrder::moveAfter('rent', 'this_key_does_not_exist', 'operating');

        $operating = array_keys(ProjectionCalculator::operatingCostRows());
        $this->assertSame('rent', end($operating));
        $this->assertNotSame('rent', $operating[1]);
    }

    /** The whole point of this feature — one shared order, not two
     *  independent ones. Dragging on Projections' own row list reorders
     *  what Expected Income reads too, since both read the SAME
     *  row_sort_orders table. */
    public function test_a_reorder_is_shared_between_projections_and_expected_income(): void
    {
        RowOrder::rows(ProjectionCalculator::SELLING_COST_ROWS, 'selling');

        RowOrder::moveAfter('shipping_fee', null, 'selling');

        $projectionsOrder = array_keys(ProjectionCalculator::sellingCostRows());
        $expectedIncomeOrder = array_keys(ExpectedIncomeCalculator::sellingCostRows());

        $this->assertSame('shipping_fee', $projectionsOrder[0]);
        $this->assertSame('shipping_fee', $expectedIncomeOrder[0]);
    }

    /** Expected Income's own SELLING_COST_ROWS lacks a couple of keys
     *  Projections' own copy has, or vice versa (confirmed divergence,
     *  2026-10-02: cod_fee/fulfillment_fee now exist in BOTH after this
     *  feature, but the two lists aren't guaranteed identical forever) —
     *  a page only ever renders keys ITS OWN constant actually has, never
     *  a key borrowed from the other page's row_sort_orders entries. */
    public function test_a_page_only_renders_its_own_known_row_keys(): void
    {
        RowSortOrder::create(['row_key' => 'a_key_only_the_other_page_has', 'section' => 'selling', 'sort_order' => -100]);

        $rows = ProjectionCalculator::sellingCostRows();

        $this->assertArrayNotHasKey('a_key_only_the_other_page_has', $rows);
    }

    /** A brand-new custom row (added via the + icon) still appends after
     *  every already-seeded row, same "new rows go last" behavior as
     *  before this feature — RowOrder::ensureSeeded() must give it a
     *  sort_order past the current max, not 0/colliding with a built-in. */
    public function test_a_new_custom_row_still_appends_to_the_end(): void
    {
        RowOrder::rows(ProjectionCalculator::OPERATING_COST_ROWS, 'operating');

        ProjectionCustomRow::create(['key' => 'custom_warehouse_fee', 'section' => 'operating', 'label' => 'Warehouse Fee', 'is_fixed' => false, 'sort_order' => 999]);

        $operating = array_keys(ProjectionCalculator::operatingCostRows());
        $this->assertSame('custom_warehouse_fee', end($operating));
    }

    /** The reorder endpoint persists via RowOrder::moveAfter() and is
     *  shared by both pages (same route, 'data.rows.reorder') — an admin
     *  dragging on either page's own markup hits this one PATCH. */
    public function test_the_reorder_endpoint_persists_a_moved_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        RowOrder::rows(ProjectionCalculator::OPERATING_COST_ROWS, 'operating');

        $response = $this->actingAs($admin)->patchJson(route('data.rows.reorder'), [
            'row_key' => 'electricity', 'after_row_key' => 'rent', 'section' => 'operating',
        ]);

        $response->assertOk();
        $operating = array_keys(ProjectionCalculator::operatingCostRows());
        $rentIndex = array_search('rent', $operating, true);
        $this->assertSame('electricity', $operating[$rentIndex + 1]);
    }

    public function test_a_non_admin_cannot_reorder_rows(): void
    {
        $normal = User::factory()->create(['role' => 'normal']);

        $response = $this->actingAs($normal)->patchJson(route('data.rows.reorder'), [
            'row_key' => 'electricity', 'after_row_key' => 'rent', 'section' => 'operating',
        ]);

        $response->assertForbidden();
    }

    /**
     * 2026-10-06 (explicit request: "is it possible that row in the
     * Selling And Marketing can change like it can drag to Operating
     * Costs rows ... vise versa") — a built-in row can now genuinely cross
     * sections: it stops appearing in its OLD section's rows and starts
     * appearing in the NEW one, which is what makes it count toward that
     * section's own Total (Total Selling Costs vs Total Operating Costs)
     * instead of just visually reordering.
     */
    public function test_moving_a_built_in_row_across_sections_relocates_it(): void
    {
        RowOrder::rows(ProjectionCalculator::SELLING_COST_ROWS, 'selling', ProjectionCalculator::OPERATING_COST_ROWS);
        RowOrder::rows(ProjectionCalculator::OPERATING_COST_ROWS, 'operating', ProjectionCalculator::SELLING_COST_ROWS);

        RowOrder::moveAfter('advertising_cost', 'rent', 'operating');

        $selling = ProjectionCalculator::sellingCostRows();
        $operating = array_keys(ProjectionCalculator::operatingCostRows());

        $this->assertArrayNotHasKey('advertising_cost', $selling);
        $rentIndex = array_search('rent', $operating, true);
        $this->assertSame('advertising_cost', $operating[$rentIndex + 1]);
    }

    /** The reverse direction — an Operating row dragged into Selling. */
    public function test_moving_a_built_in_row_from_operating_to_selling(): void
    {
        RowOrder::rows(ProjectionCalculator::SELLING_COST_ROWS, 'selling', ProjectionCalculator::OPERATING_COST_ROWS);
        RowOrder::rows(ProjectionCalculator::OPERATING_COST_ROWS, 'operating', ProjectionCalculator::SELLING_COST_ROWS);

        RowOrder::moveAfter('rent', 'shipping_fee', 'selling');

        $operating = ProjectionCalculator::operatingCostRows();
        $selling = array_keys(ProjectionCalculator::sellingCostRows());

        $this->assertArrayNotHasKey('rent', $operating);
        $shippingIndex = array_search('shipping_fee', $selling, true);
        $this->assertSame('rent', $selling[$shippingIndex + 1]);
    }

    /** A row that crosses sections also recomputes which total it feeds —
     *  the whole point, not just a visual reorder. Rent moved into Selling
     *  must stop counting toward Total Operating Costs and start counting
     *  toward Total Selling Costs. */
    public function test_a_cross_section_move_changes_which_total_the_row_feeds(): void
    {
        RowOrder::rows(ProjectionCalculator::SELLING_COST_ROWS, 'selling', ProjectionCalculator::OPERATING_COST_ROWS);
        RowOrder::rows(ProjectionCalculator::OPERATING_COST_ROWS, 'operating', ProjectionCalculator::SELLING_COST_ROWS);

        $sellingKeysBefore = array_keys(ProjectionCalculator::sellingCostRows());
        $operatingKeysBefore = array_keys(ProjectionCalculator::operatingCostRows());
        $this->assertNotContains('rent', $sellingKeysBefore);
        $this->assertContains('rent', $operatingKeysBefore);

        RowOrder::moveAfter('rent', null, 'selling');

        $sellingKeysAfter = array_keys(ProjectionCalculator::sellingCostRows());
        $operatingKeysAfter = array_keys(ProjectionCalculator::operatingCostRows());
        $this->assertContains('rent', $sellingKeysAfter);
        $this->assertNotContains('rent', $operatingKeysAfter);
    }

    /** Moving a row out of a section closes the gap it left behind — the
     *  remaining rows there renumber to a clean 0,10,20... sequence rather
     *  than keeping a hole where the moved row used to sit. */
    public function test_a_cross_section_move_closes_the_gap_in_the_origin_section(): void
    {
        RowOrder::rows(ProjectionCalculator::OPERATING_COST_ROWS, 'operating', ProjectionCalculator::SELLING_COST_ROWS);
        RowOrder::rows(ProjectionCalculator::SELLING_COST_ROWS, 'selling', ProjectionCalculator::OPERATING_COST_ROWS);

        RowOrder::moveAfter('rent', null, 'selling');

        $remainingOperating = RowSortOrder::where('section', 'operating')->orderBy('sort_order')->pluck('sort_order')->values()->all();
        foreach ($remainingOperating as $i => $sortOrder) {
            $this->assertSame($i * 10, $sortOrder);
        }
    }

    /** The one carve-out: COD Fee and Fulfillment Fee can never cross into
     *  Operating Costs, no matter what the request says — their dollar
     *  value is a hardcoded formula that always lands in Total Selling
     *  Costs, so letting them "move" would double-count or orphan them
     *  (see RowOrder::LOCKED_TO_SELLING's own doc comment). The move is
     *  silently ignored, not an error — same as the existing
     *  nonexistent-after-key fallback's own "degrade gracefully" shape. */
    public function test_cod_fee_cannot_cross_into_operating_costs(): void
    {
        RowOrder::rows(ProjectionCalculator::SELLING_COST_ROWS, 'selling', ProjectionCalculator::OPERATING_COST_ROWS);
        RowOrder::rows(ProjectionCalculator::OPERATING_COST_ROWS, 'operating', ProjectionCalculator::SELLING_COST_ROWS);

        RowOrder::moveAfter('cod_fee', 'rent', 'operating');

        $selling = ProjectionCalculator::sellingCostRows();
        $operating = ProjectionCalculator::operatingCostRows();
        $this->assertArrayHasKey('cod_fee', $selling);
        $this->assertArrayNotHasKey('cod_fee', $operating);
    }

    /** Same carve-out for Fulfillment Fee, the other LOCKED_TO_SELLING key. */
    public function test_fulfillment_fee_cannot_cross_into_operating_costs(): void
    {
        RowOrder::rows(ProjectionCalculator::SELLING_COST_ROWS, 'selling', ProjectionCalculator::OPERATING_COST_ROWS);
        RowOrder::rows(ProjectionCalculator::OPERATING_COST_ROWS, 'operating', ProjectionCalculator::SELLING_COST_ROWS);

        RowOrder::moveAfter('fulfillment_fee', null, 'operating');

        $selling = ProjectionCalculator::sellingCostRows();
        $operating = ProjectionCalculator::operatingCostRows();
        $this->assertArrayHasKey('fulfillment_fee', $selling);
        $this->assertArrayNotHasKey('fulfillment_fee', $operating);
    }

    /** A custom row (user-added via the + icon) can cross sections too —
     *  the feature isn't limited to built-ins. */
    public function test_a_custom_row_can_cross_sections(): void
    {
        RowOrder::rows(ProjectionCalculator::SELLING_COST_ROWS, 'selling', ProjectionCalculator::OPERATING_COST_ROWS);
        RowOrder::rows(ProjectionCalculator::OPERATING_COST_ROWS, 'operating', ProjectionCalculator::SELLING_COST_ROWS);
        ProjectionCustomRow::create(['key' => 'custom_warehouse_fee', 'section' => 'selling', 'label' => 'Warehouse Fee', 'is_fixed' => false, 'sort_order' => 999]);
        RowOrder::rows(ProjectionCalculator::SELLING_COST_ROWS, 'selling', ProjectionCalculator::OPERATING_COST_ROWS);

        RowOrder::moveAfter('custom_warehouse_fee', null, 'operating');

        $selling = ProjectionCalculator::sellingCostRows();
        $operating = ProjectionCalculator::operatingCostRows();
        $this->assertArrayNotHasKey('custom_warehouse_fee', $selling);
        $this->assertArrayHasKey('custom_warehouse_fee', $operating);
        $this->assertSame('Warehouse Fee', $operating['custom_warehouse_fee']);
    }

    /** The reorder endpoint itself, end to end — the response carries the
     *  row's resolved section so the frontend can tell whether its own
     *  optimistic guess should be trusted or a reload is needed. */
    public function test_the_reorder_endpoint_moves_a_row_across_sections_and_reports_its_new_section(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        RowOrder::rows(ProjectionCalculator::SELLING_COST_ROWS, 'selling', ProjectionCalculator::OPERATING_COST_ROWS);
        RowOrder::rows(ProjectionCalculator::OPERATING_COST_ROWS, 'operating', ProjectionCalculator::SELLING_COST_ROWS);

        $response = $this->actingAs($admin)->patchJson(route('data.rows.reorder'), [
            'row_key' => 'advertising_cost', 'after_row_key' => 'rent', 'section' => 'operating',
        ]);

        $response->assertOk();
        $response->assertJson(['section' => 'operating']);
        $this->assertArrayHasKey('advertising_cost', ProjectionCalculator::operatingCostRows());
    }

    /** Same endpoint, but for a LOCKED_TO_SELLING row — the response's
     *  'section' reports where the row ACTUALLY ended up ('selling', the
     *  request was ignored), not what the request asked for, so the
     *  frontend can detect the mismatch and correct itself. */
    public function test_the_reorder_endpoint_reports_the_unchanged_section_when_a_locked_row_is_refused(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        RowOrder::rows(ProjectionCalculator::SELLING_COST_ROWS, 'selling', ProjectionCalculator::OPERATING_COST_ROWS);
        RowOrder::rows(ProjectionCalculator::OPERATING_COST_ROWS, 'operating', ProjectionCalculator::SELLING_COST_ROWS);

        $response = $this->actingAs($admin)->patchJson(route('data.rows.reorder'), [
            'row_key' => 'cod_fee', 'after_row_key' => 'rent', 'section' => 'operating',
        ]);

        $response->assertOk();
        $response->assertJson(['section' => 'selling']);
    }

    /**
     * 2026-10-06 (explicit follow-up: "when i drag to another row i want
     * to make it it will not reload the whole page") — a genuine
     * cross-section move returns every card's own freshly rendered HTML
     * (keyed by column key) so the frontend can swap cards in place
     * instead of reloading. Confirms the response actually carries
     * 'cardsHtml' AND that the rendered markup reflects the row's NEW
     * section (Operating Costs' own dropzone, not Selling's) — not just
     * that the key is present.
     */
    public function test_a_cross_section_move_returns_every_cards_fresh_html(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $month = now()->format('Y-m');
        ProjectionColumn::ensureSeededForMonth($month);
        RowOrder::rows(ProjectionCalculator::SELLING_COST_ROWS, 'selling', ProjectionCalculator::OPERATING_COST_ROWS);
        RowOrder::rows(ProjectionCalculator::OPERATING_COST_ROWS, 'operating', ProjectionCalculator::SELLING_COST_ROWS);

        $response = $this->actingAs($admin)->patchJson(route('data.rows.reorder'), [
            'row_key' => 'advertising_cost', 'after_row_key' => 'rent', 'section' => 'operating',
        ]);

        $response->assertOk();
        $body = $response->json();
        $this->assertArrayHasKey('cardsHtml', $body);
        $this->assertArrayHasKey('opening_shift', $body['cardsHtml']);

        $openingShiftHtml = $body['cardsHtml']['opening_shift'];
        // The row now renders inside Operating Costs' own dropzone, not
        // Selling's — a plain "the label appears somewhere" assertion
        // wouldn't catch a row stuck in the wrong section's markup.
        $operatingDropzone = substr($openingShiftHtml, strpos($openingShiftHtml, 'data-row-dropzone="operating"'));
        $this->assertStringContainsString('data-row-key="advertising_cost"', $operatingDropzone);
    }

    /** A same-section drag must NOT carry 'cardsHtml' at all — the
     *  frontend's own instant optimistic DOM move already handles that
     *  case, and returning a full re-render for every ordinary reorder
     *  would be needless server work on the common path. */
    public function test_a_same_section_move_does_not_return_cardshtml(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        RowOrder::rows(ProjectionCalculator::OPERATING_COST_ROWS, 'operating', ProjectionCalculator::SELLING_COST_ROWS);

        $response = $this->actingAs($admin)->patchJson(route('data.rows.reorder'), [
            'row_key' => 'electricity', 'after_row_key' => 'rent', 'section' => 'operating',
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('cardsHtml', $response->json());
    }
}
