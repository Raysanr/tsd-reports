<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Product;
use App\Support\ProductPerformance;
use App\Support\TagConflicts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit-level coverage of the shared conflict-detection logic itself — the
 * method ProductPerformance::buildRow()'s counting guard and the tag-conflict
 * review queue (App\Http\Controllers\TagConflictReviewController) both call,
 * so they can never quietly disagree about what counts as a conflict. The
 * Feature-level behavior (via buildRow) is already covered by
 * LeadsReportStaleTagConflictTest; via the review queue by
 * TagConflictReviewTest.
 */
class TagConflictsTest extends TestCase
{
    use RefreshDatabase;

    private Product $clearSight;
    private Product $pterygium;

    protected function setUp(): void
    {
        parent::setUp();
        // The migration already seeds these two for "Eyecare Team" — fetch
        // rather than re-create, so this test breaks loudly if that seed ever
        // changes instead of silently testing stale fixture data.
        $this->clearSight = Product::where('team', 'Eyecare Team')->where('display_name', 'CLEARSIGHT')->firstOrFail();
        $this->pterygium  = Product::where('team', 'Eyecare Team')->where('display_name', 'PTERYGIUM')->firstOrFail();
    }

    public function test_conflicting_product_returns_null_when_the_products_own_keyword_matches_the_cart_item(): void
    {
        $order = Order::make(['team' => 'Eyecare Team', 'product' => 'Clear Sight 3.0']);
        $teamProducts = Product::where('team', 'Eyecare Team')->get();

        $this->assertNull(ProductPerformance::conflictingProduct($this->clearSight, $order, $teamProducts));
    }

    public function test_conflicting_product_returns_the_other_product_on_a_stale_tag_mismatch(): void
    {
        $order = Order::make(['team' => 'Eyecare Team', 'product' => 'Pterygium']);
        $teamProducts = Product::where('team', 'Eyecare Team')->get();

        $conflict = ProductPerformance::conflictingProduct($this->clearSight, $order, $teamProducts);

        $this->assertNotNull($conflict);
        $this->assertSame($this->pterygium->id, $conflict->id);
    }

    public function test_conflicting_product_returns_null_when_no_other_product_matches_either(): void
    {
        // Cart item matches nothing at all (e.g. a genuine upsell add-on name)
        // — not a conflict, just an order this product's tag alone still wins.
        $order = Order::make(['team' => 'Eyecare Team', 'product' => 'LUMICARE OIL']);
        $teamProducts = Product::where('team', 'Eyecare Team')->get();

        $this->assertNull(ProductPerformance::conflictingProduct($this->clearSight, $order, $teamProducts));
    }

    public function test_find_conflict_returns_null_for_an_order_with_no_tags(): void
    {
        $order = Order::make(['team' => 'Eyecare Team', 'product' => 'Pterygium', 'raw_tags' => []]);
        $products = Product::all();

        $this->assertNull(TagConflicts::findConflict($order, $products));
    }

    public function test_find_conflict_returns_the_tag_and_cart_product_pair(): void
    {
        $order = Order::make(['team' => 'Eyecare Team', 'product' => 'Pterygium', 'raw_tags' => ['CLEARSIGHT']]);
        $products = Product::all();

        $conflict = TagConflicts::findConflict($order, $products);

        $this->assertNotNull($conflict);
        $this->assertSame($this->clearSight->id, $conflict['tagProduct']->id);
        $this->assertSame($this->pterygium->id, $conflict['cartProduct']->id);
    }

    public function test_find_conflict_ignores_a_different_teams_product_with_the_same_keyword_coincidence(): void
    {
        // Guards against a false-positive if two unrelated teams ever end up
        // with overlapping keywords — findConflict must stay team-scoped.
        $order = Order::make(['team' => 'SH Naturals', 'product' => 'Pterygium', 'raw_tags' => ['CLEARSIGHT']]);
        $products = Product::all();

        $this->assertNull(TagConflicts::findConflict($order, $products));
    }
}
