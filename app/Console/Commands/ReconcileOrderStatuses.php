<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Corrects local orders whose status is stale because Pancake canceled/deleted
 * them after they were already synced. SyncTodayOrders' regular sync can never
 * catch this on its own, no matter how often or which date window it targets:
 * Pancake's list-orders endpoint excludes canceled/deleted orders by default
 * (confirmed live via the include_removed param — a plain query omits them
 * entirely), so once an order is removed there, no future date-scoped sync
 * query ever sees it again. The only way to learn its real current status is
 * to explicitly ask for removed orders via filter_status[]=6,7 +
 * include_removed=1, which this command does on a rolling window.
 *
 * This is a genuine, ongoing drift, not a one-off: confirmed live
 * (2026-07-25) that the first 100 of 522 Canceled/Deleted orders in just the
 * last 30 days already had 33 whose local status_code disagreed with
 * Pancake's real one — inflating Leads Report and other counts everywhere,
 * not just for one product on one day.
 *
 * Also clears is_upsell/is_cancelled_upsell/is_returned_upsell/
 * is_restocking_upsell on every order it corrects — status_code alone fixes
 * Leads Report/TSA Performance (they check DELETED_STATUSES directly), but
 * Dashboard's sales/revenue figures filter by these booleans, which
 * SyncTodayOrders' own convention already forces false for any void-status
 * order (Order::VOID_STATUSES, which includes Canceled/Deleted/Restocking).
 * A stale flag left over from before the order went void would otherwise
 * keep inflating gross sales or Total Restocking even after status_code
 * itself is corrected — e.g. an order sitting in Restocking (is_restocking_
 * upsell=true) that later actually gets Deleted in Pancake would keep
 * counting toward Total Restocking forever without this.
 */
class ReconcileOrderStatuses extends Command
{
    protected $signature = 'pancake:reconcile-statuses {--days=30 : How many past days to check}';
    protected $description = 'Correct local orders whose status is stale because Pancake canceled/deleted them after syncing';

    public function handle(): int
    {
        $apiKey = Setting::get('pancake_api_key', env('PANCAKE_API_KEY', ''));
        $shopId = Setting::get('shop_id', '');

        if (empty($apiKey) || empty($shopId)) {
            $this->error('API key or shop ID not configured.');
            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $from = Carbon::now('Asia/Manila')->subDays($days)->startOfDay();
        $to   = Carbon::now('Asia/Manila')->endOfDay();

        $url = "https://pos.pages.fm/api/v1/shops/{$shopId}/orders";
        $baseParams = [
            'api_key'       => $apiKey,
            'page_size'     => 100,
            'updateStatus'  => 'updated_at',
            'startDateTime' => $from->timestamp,
            'endDateTime'   => $to->timestamp,
        ];
        // Laravel/Guzzle's default array-query encoding (filter_status[0]=6&
        // filter_status[1]=7) gets a bare 500 "Server internal error" from
        // Pancake's API — confirmed live it only accepts the literal repeated
        // filter_status[]=6&filter_status[]=7 form, so this is built by hand
        // instead of passed as a query array.
        $statusQuery = collect(Order::DELETED_STATUSES)
            ->map(fn ($s) => "filter_status[]={$s}")
            ->implode('&');

        $checkedCount   = 0;
        $correctedCount = 0;
        $page           = 1;
        $apiError       = null;

        while ($page <= 500) {
            $query = http_build_query($baseParams + ['page_number' => $page]) . '&' . $statusQuery . '&include_removed=1';

            $response = Http::withHeaders(['Accept' => 'application/json'])->timeout(30)->get("{$url}?{$query}");

            if (!$response->successful()) {
                $apiError = "API error on page {$page}: " . $response->status();
                $this->error($apiError);
                break;
            }

            $orders = $response->json()['data'] ?? [];
            if (empty($orders)) break;

            foreach ($orders as $raw) {
                $id     = (string) ($raw['id'] ?? '');
                $status = (int) ($raw['status'] ?? -1);
                if ($id === '' || !in_array($status, Order::DELETED_STATUSES, true)) continue;

                $checkedCount++;
                $local = Order::where('pancake_order_id', $id)->first();
                if (!$local) continue;

                // A void status (SyncTodayOrders' own convention — see its
                // $isExcludedStatus check) never counts as a live cross-sell, no
                // matter what it looked like before Pancake canceled/deleted it.
                // Fixing status_code alone corrects Leads Report/TSA Performance
                // (they check DELETED_STATUSES directly) but NOT Dashboard's sales
                // figures, which filter by is_upsell — a stale is_upsell=true from
                // before the order went void would keep inflating gross sales/
                // upsell revenue even after status_code itself is corrected.
                $needsStatusFix = (int) $local->status_code !== $status;
                $needsUpsellFix = $local->is_upsell || $local->is_cancelled_upsell
                    || $local->is_returned_upsell || $local->is_restocking_upsell;
                if (!$needsStatusFix && !$needsUpsellFix) continue;

                $oldStatus = $local->status_code;
                $local->update([
                    'status_code'              => $status,
                    'is_upsell'                => false,
                    'is_cancelled_upsell'      => false,
                    'is_returned_upsell'       => false,
                    'is_restocking_upsell'     => false,
                    'restocking_upsell_amount' => 0,
                ]);
                $correctedCount++;
                $this->line("  Corrected #{$id}: status {$oldStatus} -> {$status}" . ($needsUpsellFix ? ' (also cleared stale upsell flag)' : ''));
            }

            if (count($orders) < 100) break;
            $page++;
        }

        Setting::set('order_status_reconcile_last_run', now()->toIso8601String());
        Setting::set('order_status_reconcile_last_checked', $checkedCount);
        Setting::set('order_status_reconcile_last_corrected', $correctedCount);

        if ($apiError !== null) {
            Log::error('pancake:reconcile-statuses failed', ['message' => $apiError]);
            return self::FAILURE;
        }

        $this->info("Checked {$checkedCount} Pancake-removed order(s) from the last {$days} day(s); corrected {$correctedCount} stale local record(s).");
        return self::SUCCESS;
    }
}
