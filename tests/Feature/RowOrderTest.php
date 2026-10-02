<?php

namespace Tests\Feature;

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
}
