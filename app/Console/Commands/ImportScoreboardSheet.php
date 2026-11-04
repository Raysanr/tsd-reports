<?php

namespace App\Console\Commands;

use App\Support\ScoreboardSheetImport;
use Illuminate\Console\Command;

/** Re-load the Jan–Apr 2026 Google Sheet figures into the TSD Scoreboard
 *  (e.g. after correcting the JSON, or after adding a TSA to the roster). */
class ImportScoreboardSheet extends Command
{
    protected $signature = 'archive:import-scoreboard-sheet {--path= : JSON file, defaults to database/data/scoreboard-sheet-2026-jan-apr.json}';

    protected $description = 'Import Jan–Apr 2026 sales and net income from the Google Sheet data file into the TSD Scoreboard';

    public function handle(): int
    {
        $r = ScoreboardSheetImport::run($this->option('path'));
        $this->info("Imported {$r['rows']} rows — {$r['matched']} matched to the roster.");
        if ($r['former']) {
            $this->line('Former TSAs (not on the roster): ' . implode(', ', $r['former']));
        }

        return self::SUCCESS;
    }
}
