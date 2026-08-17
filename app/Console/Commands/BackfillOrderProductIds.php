<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * One-off backfill for orders synced before pancake_product_ids existed (see
 * that migration's doc comment) — re-fetches each day's orders from the same
 * /orders list endpoint SyncTodayOrders already uses (confirmed live: the
 * list response's items[] already carries the same real product_id as a
 * single-order GET, so this is one cheap paginated fetch per day, not one
 * request per order) and updates ONLY the pancake_product_ids column on
 * already-synced local rows — every other column (disposition, amount,
 * status, etc.) is left untouched, this never re-derives them.
 *
 * Bounded by inserted_at (not updated_at, unlike the live sync) — this is
 * filling in a fact about the order's ORIGINAL line items, which doesn't
 * change after the fact the way status/tags do, so there's no need to
 * re-scan for later edits the way the delta sync does.
 */
class BackfillOrderProductIds extends Command
{
    protected $signature = 'pancake:backfill-product-ids
        {--date= : Single date (Y-m-d, Asia/Manila) to backfill. Defaults to today.}
        {--from= : Start of a date range (Y-m-d) — use with --to instead of --date.}
        {--to= : End of a date range (Y-m-d) — use with --from.}';

    protected $description = 'Backfill pancake_product_ids on already-synced orders for a date or date range';

    public function handle(): int
    {
        $apiKey = Setting::get('pancake_api_key', '');
        $shopId = Setting::get('shop_id', '');
        if (empty($apiKey) || empty($shopId)) {
            $this->error('pancake_api_key / shop_id not configured.');
            return self::FAILURE;
        }

        $from = $this->option('from') ?: $this->option('date') ?: now('Asia/Manila')->toDateString();
        $to   = $this->option('to') ?: $this->option('date') ?: $from;

        $start = Carbon::parse($from, 'Asia/Manila')->startOfDay();
        $end   = Carbon::parse($to, 'Asia/Manila')->startOfDay();

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $this->backfillDay($day, $apiKey, $shopId);
        }

        return self::SUCCESS;
    }

    private function backfillDay(Carbon $day, string $apiKey, string $shopId): void
    {
        $this->info("Backfilling {$day->toDateString()} (Asia/Manila)…");

        $url    = "https://pos.pages.fm/api/v1/shops/{$shopId}/orders";
        $params = [
            'api_key'       => $apiKey,
            'page_size'     => 100,
            'updateStatus'  => 'inserted_at',
            'startDateTime' => $day->copy()->startOfDay()->timestamp,
            'endDateTime'   => $day->copy()->endOfDay()->timestamp,
        ];

        $productIdsByOrderId = [];
        $page = 1;

        while (true) {
            $response = Http::timeout(30)->get($url, $params + ['page_number' => $page]);
            if (!$response->successful()) {
                $this->error("  API error on page {$page}: HTTP {$response->status()}");
                break;
            }

            $orders = $response->json('data') ?? [];
            if (empty($orders)) break;

            foreach ($orders as $raw) {
                $orderId = (string) ($raw['id'] ?? '');
                if ($orderId === '') continue;

                $ids = array_values(array_unique(array_filter(array_column($raw['items'] ?? [], 'product_id'))));
                $productIdsByOrderId[$orderId] = $ids;
            }

            $this->line("  Page {$page} — " . count($orders) . ' orders fetched');

            if (count($orders) < $params['page_size']) break;
            $page++;
        }

        if (empty($productIdsByOrderId)) {
            $this->line('  No orders found for this day.');
            return;
        }

        // Only touch orders that already exist locally — this backfills a fact
        // about existing rows, it never creates new ones (that's the live
        // sync's job).
        $existingIds = Order::whereIn('pancake_order_id', array_keys($productIdsByOrderId))
            ->pluck('pancake_order_id')->all();

        $rows = collect($existingIds)->map(fn ($id) => [
            'pancake_order_id'    => $id,
            'pancake_product_ids' => json_encode($productIdsByOrderId[$id]),
        ])->all();

        foreach (array_chunk($rows, 200) as $chunk) {
            Order::upsert($chunk, ['pancake_order_id'], ['pancake_product_ids']);
        }

        $this->info('  Updated ' . count($rows) . ' of ' . count($productIdsByOrderId) . ' fetched orders (rest not yet synced locally).');
    }
}
