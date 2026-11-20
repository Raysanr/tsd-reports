<?php

use App\Models\Department;
use App\Support\ArchiveRosterSync;
use Illuminate\Database\Migrations\Migration;

/**
 * TEAM SH E-COM becomes TSD's telesales parent (2026-11-20): it's restored,
 * and the selling teams the TSA Management sync fills (archived on
 * 2026-11-04 as CLOSING / OPENING) are restored under it with their
 * selling-team names, so TSD TSAs show on the Teams page and Org Chart.
 */
return new class extends Migration
{
    public function up(): void
    {
        $teams = Department::whereIn('team_slug', array_keys(config('teams', [])))->get();
        if ($teams->isEmpty()) {
            return; // fresh install: the sync creates them under SH E-COM itself
        }

        $parent = ArchiveRosterSync::parentTeam();
        $parent->update(['archived_at' => null]);

        foreach ($teams as $team) {
            $team->update([
                'parent_id'   => $parent->id,
                'archived_at' => null,
                'name'        => \App\Support\Teams::config()[$team->team_slug]['name'] ?? $team->name,
            ]);
        }
    }

    public function down(): void {}
};
