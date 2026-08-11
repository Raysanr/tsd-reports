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
 *
 * Second, separate pass: reconcileStaleUpsellTags() below catches a related
 * but distinct kind of drift — an order still fully ACTIVE (never Canceled/
 * Deleted/Restocking at all) whose upsell add-on item was removed or never
 * added, leaving a stale upsell tag on an otherwise-ordinary order. See that
 * method's own docblock for why it needs its own live per-order lookups
 * rather than reusing this class's Cancelled/Deleted-status query above.
 *
 * Third, separate pass: reconcileUpsellAmounts() below re-verifies `amount`
 * itself (not just which orders count as upsells) against live Pancake
 * data for every order still is_upsell=true in the window — explicit
 * request (2026-08-10) that "Fix Now" also correct Dashboard's Total
 * Cross-Sell Sales number, not just which orders feed into it.
 */
class ReconcileOrderStatuses extends Command
{
    protected $signature = 'pancake:reconcile-statuses
        {--days=30 : How many past days to check, from today backward}
        {--from= : Explicit start date (Y-m-d) — overrides --days when given}
        {--to= : Explicit end date (Y-m-d), only used with --from — defaults to today}';
    protected $description = 'Correct local orders whose status is stale because Pancake canceled/deleted them after syncing, or whose upsell tag is stale because the add-on item was removed';

    public function handle(): int
    {
        $apiKey = Setting::get('pancake_api_key', env('PANCAKE_API_KEY', ''));
        $shopId = Setting::get('shop_id', '');

        if (empty($apiKey) || empty($shopId)) {
            $this->error('API key or shop ID not configured.');
            return self::FAILURE;
        }

        // --from (Sync Health's "Fix Now" date-range picker, 2026-08-10) takes
        // an explicit calendar range instead of "N days back from today" —
        // the two aren't equivalent whenever the range doesn't end today, so
        // this can't be faked by converting a picked range into a day-count.
        if ($this->option('from')) {
            $from = Carbon::parse($this->option('from'), 'Asia/Manila')->startOfDay();
            $to   = $this->option('to')
                ? Carbon::parse($this->option('to'), 'Asia/Manila')->endOfDay()
                : Carbon::now('Asia/Manila')->endOfDay();
            $days = $from->diffInDays($to) + 1; // for the summary line below only
        } else {
            $days = max(1, (int) $this->option('days'));
            $from = Carbon::now('Asia/Manila')->subDays($days)->startOfDay();
            $to   = Carbon::now('Asia/Manila')->endOfDay();
        }

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

            // try/catch, not just ->successful(): a connection-level failure
            // (timeout, DNS blip) THROWS instead of returning a response at
            // all — confirmed live (2026-08-10), an uncaught ConnectionException
            // here crashed the whole "Fix Now" run as a fatal 500 instead of
            // resolving through the $apiError/FAILURE path below like any
            // other API error already does. Same fix, same reasoning as
            // SyncTodayOrders::safeGet().
            try {
                $response = Http::withHeaders(['Accept' => 'application/json'])->timeout(30)->get("{$url}?{$query}");
            } catch (\Throwable $e) {
                $apiError = "API error on page {$page}: " . $e->getMessage();
                $this->error($apiError);
                break;
            }

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

        [$upsellChecked, $upsellCorrected] = $apiError === null
            ? $this->reconcileStaleUpsellTags($apiKey, $shopId, $from, $to)
            : [0, 0];

        [$amountChecked, $amountCorrected] = $apiError === null
            ? $this->reconcileUpsellAmounts($apiKey, $shopId, $from, $to)
            : [0, 0];

        Setting::set('order_status_reconcile_last_run', now()->toIso8601String());
        Setting::set('order_status_reconcile_last_checked', $checkedCount + $upsellChecked + $amountChecked);
        Setting::set('order_status_reconcile_last_corrected', $correctedCount + $upsellCorrected + $amountCorrected);
        Setting::set('order_status_reconcile_last_amount_corrected', $amountCorrected);

        if ($apiError !== null) {
            Log::error('pancake:reconcile-statuses failed', ['message' => $apiError]);
            return self::FAILURE;
        }

        $this->info("Checked {$checkedCount} Pancake-removed order(s) from the last {$days} day(s); corrected {$correctedCount} stale local record(s).");
        $this->info("Checked {$upsellChecked} still-active order(s) tagged as upsells; corrected {$upsellCorrected} whose add-on item was actually removed.");
        $this->info("Checked {$amountChecked} still-active upsell(s) against live Pancake data; corrected {$amountCorrected} whose amount had drifted.");
        return self::SUCCESS;
    }

    /**
     * Second pass, separate from the Cancelled/Deleted-status sweep above: an
     * order can carry a stale upsell tag while still very much ACTIVE (not
     * void at all) — the add-on item itself was removed or never added, while
     * the order kept shipping normally. SyncTodayOrders' own sync only
     * re-examines an order when Pancake's updated_at for it falls in whatever
     * window that sync run targets; an old, closed order whose updated_at
     * never changes again would otherwise stay wrong forever once its window
     * has passed (confirmed in production: 87 such orders going back to April,
     * ₱68,389 of overcounted upsell revenue, none of them Cancelled/Deleted —
     * "Fix Now"'s existing pass above would never have touched any of them).
     *
     * Bounded to a small, precise local candidate set first (is_upsell=true,
     * product and base_product identical — the structural signature this bug
     * always leaves, see Order::remainingItemIsJustTheBase()'s docblock) so
     * this stays cheap enough to run as part of the daily schedule, not just
     * a one-off manual pass: only genuine suspects get an individual live
     * Pancake lookup, not every upsell order in the window.
     *
     * is_returned_upsell candidates included (2026-08-11, corrected — this
     * pass originally only checked is_upsell/is_restocking_upsell). Real
     * production case, Mariel/Aug 1, order #1340590: tagged "Upsell TSD
     * (Ear Relief Balm)" but the live order's sole remaining item is
     * "AudiCure" (the base) — the exact remainingItemIsJustTheBase() stale-
     * tag pattern this pass already catches for is_upsell rows, just never
     * checked for is_returned_upsell ones, so an order that was NEVER a
     * genuine surviving upsell stayed counted as one indefinitely. Same
     * root cause as the is_restocking_upsell fix above (2026-08-07,
     * order #1342174) — a third structural bucket sharing this exact bug,
     * just not caught until specifically dug into.
     */
    private function reconcileStaleUpsellTags(string $apiKey, string $shopId, Carbon $from, Carbon $to): array
    {
        // is_restocking_upsell/is_returned_upsell candidates alongside
        // is_upsell ones: the same stale-tag bug hits a Restocking or
        // Returned/Returning order just as easily (each has its own
        // isolated amount column — restocking_upsell_amount/
        // returned_upsell_amount — instead of amount, but the structural
        // signature, and the fix, are the same). Confirmed live, order
        // #1342174 (2026-08-07, restocking) and #1340590 (2026-08-11,
        // returned): both had their only items-history event be a genuine
        // addon being added then removed, tagged in a way
        // remainingItemIsJustTheBase() alone couldn't recognize (see that
        // check's own call site below).
        $candidates = Order::where(fn ($q) => $q->where('is_upsell', true)
                ->orWhere('is_restocking_upsell', true)
                ->orWhere('is_returned_upsell', true))
            ->where('is_cancelled_upsell', false)
            ->whereNotNull('product')
            ->whereColumn('product', 'base_product')
            ->whereBetween('pancake_created_at', [$from, $to])
            ->get();

        $checked   = 0;
        $corrected = 0;

        foreach ($candidates as $local) {
            $checked++;

            // try/catch, not just ->successful(): a connection-level failure
            // (timeout, DNS blip) THROWS instead of returning a response —
            // confirmed live (2026-08-10), an uncaught ConnectionException
            // for ONE candidate (order #1341832, cURL error 28 after 15s)
            // crashed this entire "Fix Now" run as a fatal 500, discarding
            // every other candidate's correction along with it. Each
            // candidate is independent (unlike the sequential page-fetch
            // above, where a failure genuinely does invalidate the rest of
            // that pass) — so the correct recovery here is skip-and-continue,
            // not abort-everything: this one stays uncorrected, safe to
            // pick up again next run, while every other candidate still
            // gets checked.
            try {
                $response = Http::withHeaders(['Accept' => 'application/json'])->timeout(15)
                    ->get("https://pos.pages.fm/api/v1/shops/{$shopId}/orders/{$local->pancake_order_id}", ['api_key' => $apiKey]);
            } catch (\Throwable $e) {
                $this->warn("  Skipped #{$local->pancake_order_id}: " . $e->getMessage());
                continue;
            }

            if (!$response->successful()) continue;
            $raw = $response->json()['data'] ?? $response->json();
            if (!is_array($raw) || !isset($raw['items'])) continue;

            // Three independent signals catch three different shapes of the
            // same underlying bug — see each method's own doc comment for why
            // no single one covers all of them. The history-based ones only
            // apply to a currently-single-item order (same precondition the
            // first one's single-item branch already uses) — irrelevant
            // otherwise.
            $isStale = Order::remainingItemIsJustTheBase($raw)
                || (count($raw['items']) === 1 && (
                    Order::historyShowsOnlyOneDistinctItemEverExisted($raw)
                    || Order::historyShowsADifferentItemWasAddedThenRemoved($raw)
                ));
            if (!$isStale) continue;

            // A restocking/returned candidate's isolated amount lives in its
            // own column (restocking_upsell_amount/returned_upsell_amount),
            // not amount — preserve whichever one actually held it.
            $preservedAmount = match (true) {
                $local->is_restocking_upsell => (float) $local->restocking_upsell_amount,
                $local->is_returned_upsell   => (float) $local->returned_upsell_amount,
                default                       => (float) $local->amount,
            };

            $local->update([
                'is_upsell'                => false,
                'is_restocking_upsell'     => false,
                'restocking_upsell_amount' => 0,
                'is_returned_upsell'       => false,
                'returned_upsell_amount'   => 0,
                'is_cancelled_upsell'      => true,
                'cancelled_upsell_amount'  => $preservedAmount,
                'amount'                   => (float) ($raw['total_price'] ?? $raw['cod'] ?? 0),
            ]);
            $corrected++;
            $this->line("  Corrected #{$local->pancake_order_id}: stale upsell tag, add-on item was removed");
        }

        return [$checked, $corrected];
    }

    /**
     * Third pass, separate from both above — explicit request (2026-08-10):
     * "Fix Now" should also correct Total Cross-Sell Sales' actual number,
     * not just which orders count toward it. Re-verifies `amount` (the
     * isolated upsell add-on price, Order::extractUpsellAmount() — see that
     * method's own docblock for why it's never total_price) against live
     * Pancake data for every order that still counts as revenue under
     * Order::scopeRealUpsell() (is_upsell OR is_returned_upsell) in the
     * window — broader than reconcileStaleUpsellTags()'s narrow candidate
     * shape above (product===base_product), since amount drift (a price
     * edit, quantity change) can happen to any upsell, not just ones
     * matching that one structural bug signature.
     *
     * is_returned_upsell IS included here (2026-08-11, corrected from this
     * pass's original scope) — confirmed live, Mariel/Aug 1: order #1340544
     * had amount=1300 (the WHOLE order's total_price) instead of 500 (the
     * real add-on value), a genuine ₱800 overcount inherited from before
     * SyncTodayOrders::handle() computed amount correctly for a Returned/
     * Returning row at sync time (see that method's own comment on the same
     * date). This pass is what retroactively corrects orders that were
     * already synced under the old, wrong logic — the sync-time fix alone
     * only protects future syncs.
     *
     * Checks every current real-upsell order in range (this can be hundreds
     * of orders, unlike the narrow candidate set above), so this fetches
     * concurrently — same Http::pool() pattern as SyncTodayOrders' own
     * page-fetch, for the same reason: one-at-a-time here made an already-
     * slow "Fix Now" meaningfully slower for close to zero benefit, and
     * pool() already returns a failed request as a Throwable in its results
     * array instead of throwing, which is the exact resilience this loop
     * needs anyway.
     */
    private function reconcileUpsellAmounts(string $apiKey, string $shopId, Carbon $from, Carbon $to): array
    {
        $candidates = Order::where(fn ($q) => $q->where('is_upsell', true)->orWhere('is_returned_upsell', true))
            ->whereBetween('pancake_created_at', [$from, $to])
            ->get(['id', 'pancake_order_id', 'amount']);

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
                    $this->warn("  Skipped #{$local->pancake_order_id} (amount check): {$reason}");
                    continue;
                }

                $raw = $response->json()['data'] ?? $response->json();
                if (!is_array($raw) || !isset($raw['items'])) continue;

                $liveAmount = Order::extractUpsellAmount($raw);
                if (abs($liveAmount - (float) $local->amount) < 0.01) continue;

                $oldAmount = (float) $local->amount;
                $local->update(['amount' => $liveAmount]);
                $corrected++;
                $this->line(sprintf('  Corrected #%s amount: ₱%.2f -> ₱%.2f', $local->pancake_order_id, $oldAmount, $liveAmount));
            }
        }

        return [$checked, $corrected];
    }
}
