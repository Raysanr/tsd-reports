<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** One-time backfill (2026-09-07, revised same day — second time):
 *  recomputes team for orders from 2026-09-05 onward using the true
 *  creation time (effective_created_at: pancake_inserted_at, falling back
 *  to pancake_created_at for older rows with no insertion timestamp) —
 *  NOT pancake_created_at alone, which holds a business-adjusted
 *  "worked-at" time that can disagree with when the order was truly
 *  created. Orders before that date keep whatever team they already have
 *  from an earlier rule — never touched. */
class BackfillTimeBasedTeamsTest extends TestCase
{
    use RefreshDatabase;

    public function test_recomputes_team_for_an_order_on_or_after_sep_5(): void
    {
        $order = Order::factory()->create([
            'pancake_order_id'   => 'in-scope-1',
            'team'               => 'SH Naturals', // wrong under the new rule for a 10am order
            'pancake_created_at' => '2026-09-05 10:00:00',
        ]);

        $this->artisan('orders:backfill-time-based-teams')->assertSuccessful();

        $this->assertSame('Eyecare Team', $order->fresh()->team);
    }

    public function test_leaves_an_order_before_sep_5_untouched(): void
    {
        $order = Order::factory()->create([
            'pancake_order_id'   => 'out-of-scope-1',
            'team'               => 'SH Naturals', // would be "Eyecare Team" under the new rule, but this order predates the backfill's scope
            'pancake_created_at' => '2026-09-04 10:00:00',
        ]);

        $this->artisan('orders:backfill-time-based-teams')->assertSuccessful();

        $this->assertSame('SH Naturals', $order->fresh()->team, 'orders before 2026-09-05 must never be touched by this backfill');
    }

    public function test_dry_run_reports_without_writing_anything(): void
    {
        $order = Order::factory()->create([
            'pancake_order_id'   => 'in-scope-2',
            'team'               => 'SH Naturals',
            'pancake_created_at' => '2026-09-05 10:00:00',
        ]);

        $this->artisan('orders:backfill-time-based-teams --dry-run')->assertSuccessful();

        $this->assertSame('SH Naturals', $order->fresh()->team, 'dry-run must not write anything');
    }

    public function test_an_order_with_no_effective_created_at_is_skipped_not_errored(): void
    {
        $order = Order::factory()->create([
            'pancake_order_id'    => 'null-date-1',
            'team'                => null,
            'pancake_created_at'  => null,
            'pancake_inserted_at' => null,
        ]);

        $this->artisan('orders:backfill-time-based-teams')->assertSuccessful();

        $this->assertNull($order->fresh()->team);
    }

    /** Bug fix (2026-09-07, second revision): recomputes from the true
     *  creation time (pancake_inserted_at), not the worked-at time
     *  (pancake_created_at) — an order tagged hours after it was really
     *  created must land in the team of when it was CREATED, not worked. */
    public function test_recomputes_from_the_true_insertion_time_not_the_workedat_time(): void
    {
        $order = Order::factory()->create([
            'pancake_order_id'    => 'inserted-vs-created-1',
            'team'                => 'SH Naturals', // wrong under the new rule: real creation was 8am (Opening)
            'pancake_inserted_at' => '2026-09-05 08:00:00', // true creation time -> Opening
            'pancake_created_at'  => '2026-09-05 16:00:00', // worked-at/tag time -> would be Closing if used
        ]);

        $this->artisan('orders:backfill-time-based-teams')->assertSuccessful();

        $this->assertSame('Eyecare Team', $order->fresh()->team);
    }
}
