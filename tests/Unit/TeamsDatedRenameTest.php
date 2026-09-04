<?php

namespace Tests\Unit;

use App\Models\TeamNameHistory;
use App\Support\Teams;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Explicit follow-up request (2026-09-04: "i wanna know that if today 12
 * midnight transition the team opening and closing it will be like tomorrow
 * when we backtrack the data like yesterday it is sh naturals and eyecare")
 * — a plain "current name" override (the pre-existing Setting-based
 * mechanism) renamed every date at once, including the past. TeamNameHistory
 * makes every rename a DATED event instead: nameFor() resolves what a team
 * was actually called on a given date, and nameForRange() combines names
 * for a range that straddles a rename ("Old / New") rather than picking one
 * arbitrarily.
 */
class TeamsDatedRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_date_before_any_rename_shows_the_config_default(): void
    {
        TeamNameHistory::create(['slug' => 'sh-naturals', 'name' => 'Team Closing', 'effective_from' => '2026-09-04']);

        $this->assertSame('SH Naturals', Teams::nameFor('sh-naturals', Carbon::parse('2026-09-01')));
    }

    public function test_a_date_on_or_after_the_rename_shows_the_new_name(): void
    {
        TeamNameHistory::create(['slug' => 'sh-naturals', 'name' => 'Team Closing', 'effective_from' => '2026-09-04']);

        $this->assertSame('Team Closing', Teams::nameFor('sh-naturals', Carbon::parse('2026-09-04')));
        $this->assertSame('Team Closing', Teams::nameFor('sh-naturals', Carbon::parse('2026-09-05')));
    }

    public function test_the_latest_rename_wins_when_multiple_exist(): void
    {
        TeamNameHistory::create(['slug' => 'sh-naturals', 'name' => 'Team Closing', 'effective_from' => '2026-09-04']);
        TeamNameHistory::create(['slug' => 'sh-naturals', 'name' => 'Team Alpha', 'effective_from' => '2026-09-10']);

        $this->assertSame('Team Closing', Teams::nameFor('sh-naturals', Carbon::parse('2026-09-05')));
        $this->assertSame('Team Alpha', Teams::nameFor('sh-naturals', Carbon::parse('2026-09-10')));
        $this->assertSame('Team Alpha', Teams::nameFor('sh-naturals', Carbon::parse('2026-09-20')));
    }

    public function test_a_range_entirely_before_a_rename_shows_only_the_old_name(): void
    {
        TeamNameHistory::create(['slug' => 'sh-naturals', 'name' => 'Team Closing', 'effective_from' => '2026-09-04']);

        $name = Teams::nameForRange('sh-naturals', Carbon::parse('2026-08-25'), Carbon::parse('2026-09-01'));

        $this->assertSame('SH Naturals', $name);
    }

    public function test_a_range_entirely_after_a_rename_shows_only_the_new_name(): void
    {
        TeamNameHistory::create(['slug' => 'sh-naturals', 'name' => 'Team Closing', 'effective_from' => '2026-09-04']);

        $name = Teams::nameForRange('sh-naturals', Carbon::parse('2026-09-05'), Carbon::parse('2026-09-10'));

        $this->assertSame('Team Closing', $name);
    }

    /** The exact scenario the user asked about: a range spanning the
     *  transition combines both names rather than picking one. */
    public function test_a_range_straddling_a_rename_shows_both_names(): void
    {
        TeamNameHistory::create(['slug' => 'sh-naturals', 'name' => 'Team Closing', 'effective_from' => '2026-09-04']);

        $name = Teams::nameForRange('sh-naturals', Carbon::parse('2026-08-29'), Carbon::parse('2026-09-04'));

        $this->assertSame('SH Naturals / Team Closing', $name);
    }

    /** Two renames inside one range must both show up, not just the range's
     *  own start/end names. */
    public function test_a_range_spanning_two_renames_shows_every_distinct_name(): void
    {
        TeamNameHistory::create(['slug' => 'sh-naturals', 'name' => 'Team Closing', 'effective_from' => '2026-09-04']);
        TeamNameHistory::create(['slug' => 'sh-naturals', 'name' => 'Team Alpha', 'effective_from' => '2026-09-10']);

        $name = Teams::nameForRange('sh-naturals', Carbon::parse('2026-09-01'), Carbon::parse('2026-09-15'));

        $this->assertSame('SH Naturals / Team Closing / Team Alpha', $name);
    }

    /** No rename inside the range at all — must never combine names just
     *  because a rename happened, if that rename doesn't actually touch
     *  this specific range. */
    public function test_a_rename_outside_the_queried_range_does_not_get_pulled_in(): void
    {
        TeamNameHistory::create(['slug' => 'sh-naturals', 'name' => 'Team Closing', 'effective_from' => '2026-09-04']);
        TeamNameHistory::create(['slug' => 'sh-naturals', 'name' => 'Team Alpha', 'effective_from' => '2026-09-20']);

        $name = Teams::nameForRange('sh-naturals', Carbon::parse('2026-09-05'), Carbon::parse('2026-09-10'));

        $this->assertSame('Team Closing', $name);
    }

    public function test_config_reflects_the_name_effective_today(): void
    {
        TeamNameHistory::create(['slug' => 'sh-naturals', 'name' => 'Team Closing', 'effective_from' => today()]);

        $this->assertSame('Team Closing', Teams::config()['sh-naturals']['name']);
    }

    public function test_a_future_dated_rename_does_not_apply_yet(): void
    {
        TeamNameHistory::create(['slug' => 'sh-naturals', 'name' => 'Team Closing', 'effective_from' => today()->addDays(3)]);

        $this->assertSame('SH Naturals', Teams::config()['sh-naturals']['name']);
        $this->assertSame('SH Naturals', Teams::nameFor('sh-naturals', today()));
    }
}
