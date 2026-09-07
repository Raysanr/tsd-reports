<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** One-time backfill (2026-09-07): recomputes team for orders from
 *  2026-09-05 onward using the new time-based rule. Orders before that
 *  date keep whatever team they already have from the old "who handled
 *  it" rule — never touched. */
class BackfillTimeBasedTeamsTest extends TestCase
{
    use RefreshDatabase;

    public function test_recomputes_team_for_an_order_on_or_after_sep_5(): void
    {
        $order = Order::factory()->create([
            'pancake_order_id'   => 'in-scope-1',
            'team'               => 'SH Naturals', // wrong under the old rule for a 10am order
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

    public function test_an_order_with_no_pancake_created_at_is_skipped_not_errored(): void
    {
        $order = Order::factory()->create([
            'pancake_order_id'   => 'null-date-1',
            'team'               => null,
            'pancake_created_at' => null,
        ]);

        $this->artisan('orders:backfill-time-based-teams')->assertSuccessful();

        $this->assertNull($order->fresh()->team);
    }
}
