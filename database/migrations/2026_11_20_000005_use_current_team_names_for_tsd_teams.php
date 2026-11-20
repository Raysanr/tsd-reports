<?php

use App\Models\Department;
use App\Support\Teams;
use Illuminate\Database\Migrations\Migration;

/** 2026_11_20_000003 named the TSD teams with the built-in names (SH
 *  Naturals / Eyecare) instead of the names in use (CLOSING / OPENING).
 *  Fix only teams still carrying the built-in name — never a hand rename. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Teams::config() as $slug => $team) {
            Department::where('team_slug', $slug)->where('name', config("teams.{$slug}.name"))
                ->update(['name' => $team['name']]);
        }
    }

    public function down(): void {}
};
