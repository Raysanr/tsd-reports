<?php

namespace App\Console\Commands;

use App\Support\PerformanceSnapshotter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Archiving System — freeze last month's per-TSA numbers (scheduled on
 *  the 1st), or any month via --month=YYYY-MM. */
class SnapshotTsaPerformance extends Command
{
    protected $signature = 'archive:snapshot-performance {--month= : YYYY-MM, defaults to last month}';

    protected $description = 'Freeze each TSA\'s monthly numbers into the Archiving System';

    public function handle(): int
    {
        $opt = $this->option('month');
        if ($opt !== null && ! preg_match('/^\d{4}-\d{2}$/', $opt)) {
            $this->error('--month must look like 2026-09');

            return self::FAILURE;
        }
        $month = $opt ? Carbon::createFromFormat('Y-m-d', $opt . '-01') : now()->subMonthNoOverflow();

        $count = PerformanceSnapshotter::generate($month);
        $this->info("Snapshotted {$count} TSAs for " . $month->format('F Y') . '.');

        return self::SUCCESS;
    }
}
