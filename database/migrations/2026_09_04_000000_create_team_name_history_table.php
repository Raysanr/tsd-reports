<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dated team-name history (explicit follow-up request, 2026-09-04: "i wanna
 * know that if today 12 midnight transition the team opening and closing it
 * will be like tomorrow when we backtrack the data ... it is sh naturals and
 * eyecare" — the plain Setting-based rename (App\Support\Teams) changes a
 * team's display name globally with no date component at all: renaming
 * today makes every past date show the new name too. This table lets a
 * rename apply only FROM a given date forward, so a report for a date
 * before that cutoff still shows the name that was actually in effect then.
 *
 * One row per rename EVENT, not one row per team — a team can be renamed
 * more than once over time, and every past rename needs to stay resolvable
 * for whatever date range a report is being viewed for. `slug` matches
 * config('teams')'s own array key (e.g. 'sh-naturals'), same as
 * Setting::set(Teams::nameSettingKey($slug), ...) already keys on — never
 * the order_team value, which stays permanently fixed regardless of any
 * rename (see Teams.php's own doc comment on why).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_name_history', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->string('name');
            $table->date('effective_from');
            $table->timestamps();

            $table->index(['slug', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_name_history');
    }
};
