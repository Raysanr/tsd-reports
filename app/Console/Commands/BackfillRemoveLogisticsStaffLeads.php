<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * One-off cleanup for leads pulled in and distributed BEFORE
 * Order::isCreatedByLogisticsStaff() existed (2026-09-28) — real production
 * orders #1373293/#1373292/#1373288/#1373287, all created by AJ Dela Cruz,
 * were already synced as Leads and sitting in TSAs' queues. That check now
 * stops any NEW order created by AJ Dela Cruz/Ralph Cruz from ever becoming
 * a Lead (see SyncPancakeLeads.php), but does nothing for ones already
 * created before it existed — this is that manual, run-once-per-affected-
 * window backfill, same "run-once, not scheduled" convention as
 * BackfillDuplicatedByLogistics (see that command's own doc comment for
 * why: there's no cheap local signal to narrow the candidate set to, only
 * a live Pancake fetch of each lead's own order tells us who created it).
 *
 * Explicit scope, 2026-09-28 ("back fill today only" / "if remove it is
 * only in lead not delete as in the pos"): defaults to today only (not the
 * 30-day window BackfillDuplicatedByLogistics defaults to), and only ever
 * deletes the LOCAL Lead row — never touches the real Pancake order itself,
 * this sync is one-directional (pull-in only) so there is nothing to
 * delete or write back to Pancake either way. A lead already called or
 * carrying a logged disposition is left alone even if its order matches —
 * a TSA may have already done real work on it, so only untouched leads are
 * removed.
 */
class BackfillRemoveLogisticsStaffLeads extends Command
{
    protected $signature = 'pancake:backfill-remove-logistics-leads
        {--date= : Explicit date (Y-m-d) to check — defaults to today}';
    protected $description = 'Remove already-synced, untouched leads whose Pancake order was created by logistics staff (AJ Dela Cruz / Ralph Cruz)';

    public function handle(): int
    {
        $apiKey = Setting::get('pancake_api_key', env('PANCAKE_API_KEY', ''));
        $shopId = Setting::get('shop_id', '');

        if (empty($apiKey) || empty($shopId)) {
            $this->error('API key or shop ID not configured.');
            return self::FAILURE;
        }

        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), 'Asia/Manila')->startOfDay()
            : Carbon::now('Asia/Manila')->startOfDay();
        $from = $date->copy()->startOfDay();
        $to   = $date->copy()->endOfDay();

        // Only leads no TSA has touched yet — same "leave already-worked
        // leads alone" caution as every other backfill in this app that
        // corrects data retroactively (e.g. backfillCallbackFromTags()'s
        // own "never overwritten" guards in SyncPancakeLeads).
        $candidates = Lead::whereNull('called_at')
            ->whereNull('disposition')
            ->whereRaw('COALESCE(pancake_created_at, synced_at) BETWEEN ? AND ?', [$from, $to])
            ->get(['id', 'pancake_order_id']);

        $checked = 0;
        $removed = 0;
        $concurrency = 5;

        foreach ($candidates->chunk($concurrency) as $batch) {
            $responses = Http::pool(fn ($pool) => $batch->map(
                fn ($lead) => $pool->as($lead->pancake_order_id)
                    ->withHeaders(['Accept' => 'application/json'])->timeout(15)
                    ->get("https://pos.pages.fm/api/v1/shops/{$shopId}/orders/{$lead->pancake_order_id}", ['api_key' => $apiKey])
            )->all());

            foreach ($batch as $local) {
                $checked++;
                $response = $responses[$local->pancake_order_id] ?? null;

                if ($response instanceof \Throwable || $response === null || !$response->successful()) {
                    $reason = $response instanceof \Throwable ? $response->getMessage() : ($response?->status() ?? 'no response');
                    $this->warn("  Skipped #{$local->pancake_order_id}: {$reason}");
                    continue;
                }

                $raw = $response->json()['data'] ?? $response->json();
                if (!is_array($raw)) continue;
                if (!Order::isCreatedByLogisticsStaff($raw)) continue;

                $creatorName = $raw['creator']['name'] ?? 'unknown';
                $local->delete();
                $removed++;
                $this->line("  Removed lead for order #{$local->pancake_order_id} (created by {$creatorName})");
            }
        }

        $this->info("Checked {$checked} untouched lead(s) from {$date->toDateString()}; removed {$removed} created by logistics staff.");
        return self::SUCCESS;
    }
}
