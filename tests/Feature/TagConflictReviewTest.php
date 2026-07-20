<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TagConflictReview;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The review queue surfaces exactly the orders ProductPerformance::buildRow()'s
 * $conflictsWithAnotherProduct guard silently excludes from every product's
 * counts (see LeadsReportStaleTagConflictTest) — a tag matches one product,
 * but the order's own cart item (`product` column) matches a DIFFERENT
 * product, almost always because a TSA left a stale tag on the order in
 * Pancake POS itself.
 */
class TagConflictReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function conflictingOrder(array $overrides = []): Order
    {
        $shift = TsaShift::where('team', 'Eyecare Team')->first();

        // Cart item is literally Pterygium, but the CLEARSIGHT tag is still
        // attached — the exact production pattern this page exists for.
        return Order::create(array_merge([
            'pancake_order_id'   => 'conflict-1',
            'team'               => 'Eyecare Team',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'product'            => 'Pterygium',
            'raw_tags'           => [strtoupper($shift->tsa_key), 'CLEARSIGHT', 'CONFIRMED VIA CALL'],
            'is_upsell'          => false,
            'status_code'        => 1,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ], $overrides));
    }

    public function test_normal_role_user_cannot_access_tag_conflicts(): void
    {
        $this->actingAs(User::factory()->normal()->create());
        $this->get(route('tag-conflicts'))->assertForbidden();
    }

    public function test_page_lists_an_order_whose_tag_and_cart_item_point_to_different_products(): void
    {
        $order = $this->conflictingOrder();

        $response = $this->get(route('tag-conflicts'));

        $response->assertOk();
        $response->assertViewHas('totalConflicts', 1);
        $response->assertSee('#' . $order->pancake_order_id, false);
        $response->assertSee('CLEARSIGHT', false);
        $response->assertSee('PTERYGIUM', false);
    }

    public function test_a_genuine_upsell_addon_is_not_flagged_as_a_conflict(): void
    {
        // LUMICARE OIL isn't its own team product — it never matches another
        // product's keyword — so this must never appear in the queue.
        $shift = TsaShift::where('team', 'Eyecare Team')->first();

        Order::create([
            'pancake_order_id'   => 'real-upsell-1',
            'team'               => 'Eyecare Team',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'product'            => 'LUMICARE OIL',
            'raw_tags'           => [strtoupper($shift->tsa_key), 'CLEARSIGHT', 'UPSELL TSD - CLEARSIGHT + LUMICARE OIL'],
            'is_upsell'          => true,
            'status_code'        => 1,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $response = $this->get(route('tag-conflicts'));

        $response->assertOk();
        $response->assertViewHas('totalConflicts', 0);
    }

    public function test_an_order_whose_tag_and_cart_item_agree_is_not_flagged(): void
    {
        $shift = TsaShift::where('team', 'Eyecare Team')->first();

        Order::create([
            'pancake_order_id'   => 'no-conflict-1',
            'team'               => 'Eyecare Team',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'product'            => 'Clear Sight 3.0',
            'raw_tags'           => [strtoupper($shift->tsa_key), 'CLEARSIGHT'],
            'is_upsell'          => false,
            'status_code'        => 1,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $response = $this->get(route('tag-conflicts'));

        $response->assertOk();
        $response->assertViewHas('totalConflicts', 0);
    }

    public function test_empty_state_shows_when_nothing_conflicts(): void
    {
        $response = $this->get(route('tag-conflicts'));

        $response->assertOk();
        $response->assertViewHas('totalConflicts', 0);
        $response->assertSee('No tag conflicts in this window.', false);
    }

    public function test_an_order_outside_the_date_window_is_excluded(): void
    {
        $this->conflictingOrder(['pancake_order_id' => 'old-conflict', 'pancake_created_at' => now()->subDays(45)]);

        // Default window is the last 30 days — a 45-day-old order shouldn't
        // show up without explicitly widening the range.
        $response = $this->get(route('tag-conflicts'));
        $response->assertViewHas('totalConflicts', 0);

        $response = $this->get(route('tag-conflicts', ['date_from' => now()->subDays(60)->toDateString(), 'date_to' => now()->toDateString()]));
        $response->assertViewHas('totalConflicts', 1);
    }

    public function test_a_range_wider_than_the_max_window_is_clamped_instead_of_erroring(): void
    {
        // A conflict 200 days old sits outside even the clamped 90-day window
        // ending today, so it must NOT appear — proves the clamp is applied
        // to the actual query, not just cosmetic on the displayed label.
        $this->conflictingOrder(['pancake_order_id' => 'ancient-conflict', 'pancake_created_at' => now()->subDays(200)]);

        $response = $this->get(route('tag-conflicts', ['date_from' => now()->subDays(400)->toDateString(), 'date_to' => now()->toDateString()]));

        $response->assertOk();
        $response->assertViewHas('clamped', true);
        $response->assertViewHas('dateFrom', now()->subDays(90)->toDateString());
        $response->assertViewHas('totalConflicts', 0);
    }

    public function test_mark_reviewed_removes_the_order_from_the_open_queue(): void
    {
        $order = $this->conflictingOrder();

        $response = $this->post(route('tag-conflicts.review', $order));

        $response->assertRedirect();
        $this->assertDatabaseHas('tag_conflict_reviews', ['order_id' => $order->id]);

        $this->get(route('tag-conflicts'))->assertViewHas('totalConflicts', 0);
        $this->get(route('tag-conflicts', ['reviewed' => 1]))->assertViewHas('totalConflicts', 1);
    }

    public function test_marking_the_same_order_reviewed_twice_does_not_duplicate(): void
    {
        $order = $this->conflictingOrder();

        $this->post(route('tag-conflicts.review', $order));
        $this->post(route('tag-conflicts.review', $order));

        $this->assertSame(1, TagConflictReview::where('order_id', $order->id)->count());
    }

    public function test_unreview_moves_the_order_back_to_the_open_queue(): void
    {
        $order = $this->conflictingOrder();
        TagConflictReview::markReviewed($order->id, auth()->id());

        $response = $this->delete(route('tag-conflicts.unreview', $order));

        $response->assertRedirect();
        $this->assertDatabaseMissing('tag_conflict_reviews', ['order_id' => $order->id]);
        $this->get(route('tag-conflicts'))->assertViewHas('totalConflicts', 1);
    }
}
