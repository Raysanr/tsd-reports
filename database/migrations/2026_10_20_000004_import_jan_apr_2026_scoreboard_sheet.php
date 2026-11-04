<?php

use App\Support\ScoreboardSheetImport;
use Illuminate\Database\Migrations\Migration;

/** Loads the Jan–Apr 2026 Google Sheet figures into scoreboard_imports on
 *  deploy (the entrypoint runs migrate), so production gets them without a
 *  manual step. Re-runnable via `php artisan archive:import-scoreboard-sheet`. */
return new class extends Migration
{
    public function up(): void
    {
        ScoreboardSheetImport::run();
    }

    public function down(): void
    {
        \App\Models\ScoreboardImport::whereBetween('month', ['2026-01-01', '2026-04-30'])->delete();
    }
};
