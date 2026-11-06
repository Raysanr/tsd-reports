<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\TsaShift;
use App\Support\ProductPerformance;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Puts back TSA names a sync blanked (found 2026-11-06). An order's tags still
 * name its TSA, so a blank order gets the one TSA its tags match on the roster
 * of its own date, the same way SyncTodayOrders reads tags. Orders whose tags
 * match two TSAs are left blank and counted. Only ever fills blank names, and
 * changes nothing without --apply.
 */
class RestoreOrderTsaNames extends Command
{
    protected $signature = 'orders:restore-tsa-names {--from=2026-10-01} {--to= : Defaults to today} {--apply : Save the names; without this it only reports}';

    protected $description = "Restore blanked TSA names from orders' own tags (dry run unless --apply)";

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) ($this->option('to') ?: now('Asia/Manila')->toDateString());

        $blank = Order::where(fn ($q) => $q->whereNull('tsa_name')->orWhere('tsa_name', ''))
            ->whereRaw('DATE(COALESCE(pancake_inserted_at, pancake_created_at)) BETWEEN ? AND ?', [$from, $to])
            ->get();

        $rosters = [];
        $matched = collect();
        $ambiguous = 0;
        foreach ($blank as $order) {
            $date = Carbon::parse($order->pancake_inserted_at ?? $order->pancake_created_at)->toDateString();
            $roster = $rosters[$date] ??= $this->rosterOn($date);
            $names = collect((array) ($order->raw_tags ?? []))
                ->map(fn ($tag) => $roster[strtoupper(trim((string) $tag))] ?? null)
                ->filter()->unique();

            if ($names->count() > 1) {
                $ambiguous++;
            } elseif ($names->count() === 1) {
                $matched->push(['order' => $order, 'name' => $names->first()]);
            }
        }

        $this->report($blank->count(), $matched, $ambiguous);

        if (!$this->option('apply')) {
            $this->warn('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        // Query builder, so updated_at stays as it was.
        foreach ($matched as $row) {
            DB::table('orders')->where('id', $row['order']->id)
                ->where(fn ($q) => $q->whereNull('tsa_name')->orWhere('tsa_name', ''))
                ->update(['tsa_name' => $row['name']]);
        }
        $this->info("Restored {$matched->count()} orders.");

        return self::SUCCESS;
    }

    /** Tag keyword => tsa_key for TSAs still on the roster on $date. */
    private function rosterOn(string $date): array
    {
        $map = [];
        $shifts = TsaShift::withTrashed()
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('deleted_at', '>=', "{$date} 00:00:00"))
            ->orderBy('id')->get();
        foreach ($shifts as $shift) {
            foreach ($shift->tag_keywords_array as $keyword) {
                $map[strtoupper($keyword)] = $shift->tsa_key;
            }
        }

        return $map;
    }

    private function report(int $blank, Collection $matched, int $ambiguous): void
    {
        $this->line("Blank orders in range: {$blank}. Can restore: {$matched->count()}. Two TSAs on the tags, left blank: {$ambiguous}.");
        foreach ($matched->groupBy('name') as $name => $rows) {
            $tally = ProductPerformance::tally($rows->pluck('order'));
            $this->line(sprintf('  %-12s %4d orders, %3d confirmed, sales %s, catered %d', $name, $rows->count(), $tally['upsell_confirmation'], number_format($tally['upsell_sales'], 2), $tally['catered']));
        }
    }
}
