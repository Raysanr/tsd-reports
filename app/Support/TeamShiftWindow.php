<?php

namespace App\Support;

/**
 * The single source of truth for "which team does this hour belong to" —
 * explicit request, 2026-09-07: Order.team switched from "which TSA/
 * product handled it" to "what hour it was created."
 *
 * "Created" means the order's true, original Pancake creation time
 * (pancake_inserted_at, via Order::effective_created_at) — NOT
 * pancake_created_at, which despite its name holds a business-adjusted
 * "worked-at" timestamp that can run hours later than the real creation
 * time (see SyncTodayOrders::resolveWorkedAt()). This class originally
 * used pancake_created_at/worked-at, but that disagreed with Leads
 * Report's own hour bucketing (which always used effective_created_at,
 * to match Pancake POS's own "Created At" filter) whenever a lead sat
 * untouched before being tagged — confirmed live: a lead truly created
 * 8:56am but tagged/worked at 3:20pm got team='SH Naturals' from the
 * 3:20pm tag, while Leads Report's hourly table bucketed the same order
 * under 8am. Both SyncTodayOrders.php (new orders going forward) and
 * BackfillTimeBasedTeams (existing 2026-09-05-onward orders) call
 * forHour() with effective_created_at's hour, so the boundary is defined
 * in exactly one place and can never drift between the two.
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

    /** $hour: 0-23, the hour-of-day component of the order's true creation
     *  time (Order::effective_created_at) — NOT pancake_created_at. */
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

    /** The LAST hour-of-day (0-23) still inside $team's window — the mirror
     *  of startHourFor() at the other edge (2026-09-07): Opening's window
     *  ends at 2pm (14), Closing's at 11pm (23). Leads Report's hourly
     *  tables use this to exclude any row outside the team's own window. */
    public static function endHourFor(string $team): int
    {
        return match ($team) {
            self::OPENING_TEAM => 14,
            self::CLOSING_TEAM => 23,
            default => throw new \InvalidArgumentException("Unknown team: {$team}."),
        };
    }
}
