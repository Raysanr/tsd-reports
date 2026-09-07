<?php

namespace App\Support;

/**
 * The single source of truth for "which team does this hour belong to" —
 * explicit request, 2026-09-07: Order.team switched from "which TSA/
 * product handled it" to "what hour it was created," using the same
 * pancake_created_at value every report already buckets by. Both
 * SyncTodayOrders.php (new orders going forward) and
 * BackfillTimeBasedTeams (existing 2026-09-05-onward orders) call
 * forHour() so the boundary is defined in exactly one place and can
 * never drift between the two.
 *
 * Opening = Eyecare Team, 00:00-14:59. Closing = SH Naturals, 15:00-23:59.
 * These literal order_team strings are the same two values
 * config('teams') has always used — this does not introduce a third
 * team or rename either one; App\Support\Teams's own dated
 * display-name resolution (e.g. showing "Team Opening"/"Team Closing"
 * instead of "Eyecare"/"SH Naturals") is completely separate and
 * unaffected by this class.
 */
class TeamShiftWindow
{
    private const OPENING_TEAM = 'Eyecare Team';
    private const CLOSING_TEAM = 'SH Naturals';

    /** $hour: 0-23, the hour-of-day component of pancake_created_at. */
    public static function forHour(int $hour): string
    {
        if ($hour < 0 || $hour > 23) {
            throw new \InvalidArgumentException("Hour must be 0-23, got {$hour}.");
        }

        return $hour < 15 ? self::OPENING_TEAM : self::CLOSING_TEAM;
    }

    /** The hour-of-day (0-23) at which $team's window starts — Leads
     *  Report's hourly tables use this as their display cutoff (2026-09-07,
     *  replacing an older per-TSA shift_start-based cutoff that disagreed
     *  with this same boundary once Order.team became time-derived). */
    public static function startHourFor(string $team): int
    {
        return match ($team) {
            self::OPENING_TEAM => 0,
            self::CLOSING_TEAM => 15,
            default => throw new \InvalidArgumentException("Unknown team: {$team}."),
        };
    }
}
