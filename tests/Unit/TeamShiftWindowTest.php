<?php

namespace Tests\Unit;

use App\Support\TeamShiftWindow;
use Tests\TestCase;

/**
 * Explicit request (2026-09-07): Order.team is switching from "which TSA/
 * product handled it" to "what hour it was created" — Opening (Eyecare
 * Team) = 00:00-14:59, Closing (SH Naturals) = 15:00-23:59. This is the
 * one place that boundary is defined; SyncTodayOrders.php and the
 * backfill command both call it so the boundary can never drift between
 * the two.
 */
class TeamShiftWindowTest extends TestCase
{
    public function test_midnight_is_opening(): void
    {
        $this->assertSame('Eyecare Team', TeamShiftWindow::forHour(0));
    }

    public function test_2pm_is_still_opening(): void
    {
        $this->assertSame('Eyecare Team', TeamShiftWindow::forHour(14));
    }

    public function test_3pm_is_the_first_closing_hour(): void
    {
        $this->assertSame('SH Naturals', TeamShiftWindow::forHour(15));
    }

    public function test_11pm_is_still_closing(): void
    {
        $this->assertSame('SH Naturals', TeamShiftWindow::forHour(23));
    }

    public function test_rejects_an_hour_outside_0_to_23(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TeamShiftWindow::forHour(24);
    }
}
