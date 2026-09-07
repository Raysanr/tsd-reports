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

    public function test_opening_starts_at_midnight(): void
    {
        $this->assertSame(0, TeamShiftWindow::startHourFor('Eyecare Team'));
    }

    public function test_closing_starts_at_3pm(): void
    {
        $this->assertSame(15, TeamShiftWindow::startHourFor('SH Naturals'));
    }

    public function test_rejects_an_unknown_team(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TeamShiftWindow::startHourFor('Some Other Team');
    }

    public function test_opening_ends_at_2pm(): void
    {
        $this->assertSame(14, TeamShiftWindow::endHourFor('Eyecare Team'));
    }

    public function test_closing_ends_at_11pm(): void
    {
        $this->assertSame(23, TeamShiftWindow::endHourFor('SH Naturals'));
    }

    public function test_end_hour_rejects_an_unknown_team(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TeamShiftWindow::endHourFor('Some Other Team');
    }
}
