<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * One-off backfill for orders synced BEFORE Order::isDuplicatedByLogistics()
 * existed (2026-08-22) — 215 such orders were found live in the shop, all
 * still counted as real leads/upsells everywhere despite Pancake staff
 * having already flagged them as a warehouse/logistics duplicate via a
 * "DUPLICATED BY LOGISTICS" note. SyncTodayOrders' regular sync now sets
 * this flag going forward on its own (the note is written at/near order
 * creation time, well within a normal delta sync's window), so this is a
 * manual, run-once-per-affected-window command, not a new daily-scheduled
 * pass — unlike ReconcileOrderStatuses' own passes, there is no cheap local
 * signal to narrow the candidate set to (nothing local distinguishes a
 * duplicate order from a real one — only the live note text does), so
 * scanning every order in a window this way is far heavier than those
 * narrow, targeted passes and not something to run automatically every day.
 */
class BackfillDuplicatedByLogistics extends Command
{
    protected $signature = 'pancake:backfill-duplicated-logistics
        {--days=30 : How many past days to check, from today backward}
        {--from= : Explicit start date (Y-m-d) — overrides --days when given}
        {--to= : Explicit end date (Y-m-d), only used with --from — defaults to today}';
    protected $description = 'Backfill is_duplicated_by_logistics on orders synced before that check existed, by live-checking each one\'s Pancake note fields';

    public function handle(): int
    {
        $apiKey = Setting::get('pancake_api_key', env('PANCAKE_API_KEY', ''));
        $shopId = Setting::get('shop_id', '');

        if (empty($apiKey) || empty($shopId)) {
            $this->error('API key or shop ID not configured.');
            return self::FAILURE;
        }

        if ($this->option('from')) {
            $from = Carbon::parse($this->option('from'), 'Asia/Manila')->startOfDay();
            $to   = $this->option('to')
                ? Carbon::parse($this->option('to'), 'Asia/Manila')->endOfDay()
                : Carbon::now('Asia/Manila')->endOfDay();
        } else {
            $days = max(1, (int) $this->option('days'));
            $from = Carbon::now('Asia/Manila')->subDays($days)->startOfDay();
            $to   = Carbon::now('Asia/Manila')->endOfDay();
        }

        // Only orders not already flagged — a prior run (or a normal sync since)
        // may have already caught some of this window.
        $candidates = Order::where('is_duplicated_by_logistics', false)
            ->whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to])
            ->get(['id', 'pancake_order_id']);

        $checked     = 0;
        $corrected   = 0;
        $concurrency = 5;

        foreach ($candidates->chunk($concurrency) as $batch) {
            $responses = Http::pool(fn ($pool) => $batch->map(
                fn ($order) => $pool->as($order->pancake_order_id)
                    ->withHeaders(['Accept' => 'application/json'])->timeout(15)
                    ->get("https://pos.pages.fm/api/v1/shops/{$shopId}/orders/{$order->pancake_order_id}", ['api_key' => $apiKey])
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
                if (!Order::isDuplicatedByLogistics($raw)) continue;

                // Same cascade SyncTodayOrders applies at sync time — a duplicate
                // was never a genuine upsell either, no matter what tag it carries.
                $local->update([
                    'is_duplicated_by_logistics' => true,
                    'is_upsell'                  => false,
                    'is_cancelled_upsell'        => false,
                    'cancelled_upsell_amount'    => null,
                    'is_returned_upsell'         => false,
                    'returned_upsell_amount'     => 0,
                    'is_restocking_upsell'       => false,
                    'restocking_upsell_amount'   => 0,
                    'is_upsell_on_voided_order'  => false,
                ]);
                $corrected++;
                $this->line("  Corrected #{$local->pancake_order_id}: flagged as duplicated by logistics");
            }
        }

        Setting::set('duplicated_logistics_backfill_last_run', now()->toIso8601String());
        Setting::set('duplicated_logistics_backfill_last_checked', $checked);
        Setting::set('duplicated_logistics_backfill_last_corrected', $corrected);

        $this->info("Checked {$checked} order(s) not yet flagged; corrected {$corrected} newly-found duplicate(s).");
        return self::SUCCESS;
    }
}
