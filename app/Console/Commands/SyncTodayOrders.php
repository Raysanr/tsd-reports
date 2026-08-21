<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\TsaShift;
use App\Support\SyncHealth;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncTodayOrders extends Command
{
    protected $signature   = 'pancake:sync-today
        {--date= : Date to sync (Y-m-d, Philippine time), defaults to today}
        {--delta : Only fetch orders updated since the last successful run (5-min overlap); falls back to the full day when that anchor is stale}';
    protected $description = 'Fetch orders from Pancake POS for a given Philippine date and save to the local database';

    // Built from the tsa_shifts table in handle() — map POS name tag → [name, team].
    // Loaded dynamically (not hardcoded) so TSAs added via the TSA Management page
    // are recognized by the very next sync, no code change/deploy needed.
    private array $tsaMap = [];

    // Built from the tsa_shifts table in handle() — map POS seller account keyword
    // → [name, team]. Used as fallback when the UPSELL TSD tag is missing but the
    // seller account confirms it's a TSA. Keywords must be specific enough to avoid
    // matching non-TSA accounts with common names.
    private array $sellerMap = [];

    // Loaded once in handle() (same reasoning as $tsaMap/$sellerMap above — avoids
    // re-querying the products table on every single order in inferTeamFromProduct()).
    private ?\Illuminate\Support\Collection $products = null;

    /** Populate $tsaMap / $sellerMap from tsa_shifts (see class doc above). */
    private function loadTsaMaps(): void
    {
        foreach (TsaShift::all() as $shift) {
            $info = ['name' => $shift->tsa_key, 'team' => $shift->team];

            foreach ($shift->tag_keywords_array as $kw) {
                $this->tsaMap[strtoupper($kw)] = $info;
            }
            foreach ($shift->seller_keywords_array as $kw) {
                $this->sellerMap[strtolower($kw)] = $info;
            }
        }
    }

    /** Generous vs. real durations (seconds up to ~1 minute for a big day, per
     *  Sync Health data) — wide headroom so a container crash mid-run (the flag
     *  never reaches the finally block below) can't block every future run,
     *  scheduled or manual, forever. Mirrors SyncCallRecordings::runningFlagIsStale(). */
    private const RUNNING_STALE_MINUTES = 10;

    public function handle(): int
    {
        ini_set('memory_limit', '-1');
        $runStart = now();

        // withoutOverlapping() on the schedule registration (routes/console.php)
        // only guards the SCHEDULER from launching a second instance of its own —
        // it does nothing for DashboardController::sync()'s manual "Sync" button,
        // which spawns this command via a raw detached exec(), bypassing Laravel's
        // scheduler entirely. Without this guard, clicking Sync while the every-N-minute
        // delta or 15-minute safety sweep is mid-run would run two heavy Pancake
        // fetches at once on this single-worker container — the same bug already
        // fixed for Drive syncs (SyncCallRecordings) after an identical outage.
        if (Setting::get('pancake_sync_running') === '1' && !$this->runningFlagIsStale()) {
            $this->warn('A sync is already running — skipping to avoid running two at once.');
            return self::SUCCESS;
        }

        Setting::set('pancake_sync_running', '1');
        Setting::set('pancake_sync_last_run', now()->toIso8601String());

        try {
            return $this->doSync($runStart);
        } finally {
            Setting::set('pancake_sync_running', '');
        }
    }

    /** No timestamp at all can't be a genuinely in-progress run, so treat that
     *  as stale too rather than block forever on a flag with nothing to measure
     *  staleness against. */
    private function runningFlagIsStale(): bool
    {
        $lastRun = Setting::get('pancake_sync_last_run');
        return !$lastRun || Carbon::parse($lastRun)->diffInMinutes(now()) > self::RUNNING_STALE_MINUTES;
    }

    private function doSync(Carbon $runStart): int
    {
        $this->loadTsaMaps();
        $this->products = Product::orderBy('sort_order')->get();

        $apiKey = Setting::get('pancake_api_key', env('PANCAKE_API_KEY', ''));
        $shopId = Setting::get('shop_id', '');

        if (empty($apiKey) || empty($shopId)) {
            $this->error('API key or shop ID not configured. Go to Settings first.');
            $this->recordRun($runStart, success: false, errorMessage: 'API key or shop ID not configured.');
            return self::FAILURE;
        }

        // Target date in Philippine time
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), 'Asia/Manila')
            : Carbon::now('Asia/Manila');

        $startOfDayTs = $date->copy()->startOfDay()->timestamp;
        $endOfDayTs   = $date->copy()->endOfDay()->timestamp;

        // Delta mode (what the scheduler uses): only fetch orders whose updated_at
        // falls after the last successful run, with a 5-minute overlap so nothing
        // on the boundary is ever missed. Falls back to the full day when the
        // anchor is stale (>60 min — e.g. the scheduler was down), so gaps
        // self-heal with a complete sweep. Manual syncs always do the full day.
        $startTs = $startOfDayTs;
        if ($this->option('delta') && !$this->option('date')) {
            $lastSynced = Setting::get('last_synced');
            if ($lastSynced) {
                $anchor = Carbon::parse($lastSynced);
                $since  = $anchor->copy()->subMinutes(5);
                if ($since->timestamp > $startOfDayTs && $anchor->diffInMinutes(now()) <= 60) {
                    $startTs = $since->timestamp;
                }
            }
        }

        $this->info("Syncing orders for: {$date->toDateString()} (Philippine Time)"
            . ($startTs !== $startOfDayTs ? ' — delta since last successful run' : ''));
        $this->newLine();

        $pageSize   = 100;
        $hitPrevDay = false;
        $apiError   = null;
        $stats      = ['total' => 0, 'new' => 0, 'upsell_count' => 0, 'upsell_sales' => 0.0, 'tsa_count' => []];

        $url         = "https://pos.pages.fm/api/v1/shops/{$shopId}/orders";
        $fetchParams = [
            'api_key'       => $apiKey,
            'page_size'     => $pageSize,
            // Fix #13: a lead can be created days before a TSA actually works it
            // (e.g. a backlog Facebook lead) — paginating/cutting off by
            // inserted_at (the old approach) silently drops orders worked today
            // but created earlier, since they're buried past the "reached
            // previous day" stop point. Fetch by *last updated* time instead, so
            // anything touched today — regardless of age — actually gets pulled.
            'updateStatus'  => 'updated_at',
            'startDateTime' => $startTs,
            'endDateTime'   => $endOfDayTs,
            'option_sort'   => 'last_updated_order_desc',
        ];
        $headers = ['Accept' => 'application/json'];

        // Page 1 goes alone (most delta runs fit in it entirely); further pages are
        // fetched in concurrent batches — API round-trips, not the DB, dominate run
        // time, so pooling cuts multi-page days to roughly 1/{$concurrency} the wait.
        // A page returning fewer than $pageSize orders is the last one; processing
        // stays in page order so the "reached previous day" early-stop still holds.
        $page        = 1;
        $concurrency = 5;
        while ($page <= 500 && !$hitPrevDay && $apiError === null) {
            $pages = $page === 1 ? [1] : range($page, min($page + $concurrency - 1, 500));

            $responses = count($pages) === 1
                ? [(string) $pages[0] => $this->safeGet($url, $headers, $fetchParams + ['page_number' => $pages[0]])]
                : Http::pool(fn ($pool) => array_map(
                    fn ($p) => $pool->as((string) $p)->withHeaders($headers)->timeout(30)->get($url, $fetchParams + ['page_number' => $p]),
                    $pages
                  ));

            $sawLastPage = false;
            foreach ($pages as $p) {
                $response = $responses[(string) $p] ?? null;
                if ($response instanceof \Throwable || $response === null || !$response->successful()) {
                    $apiError = "API error on page {$p}: "
                        . ($response instanceof \Throwable ? $response->getMessage() : ($response?->status() ?? 'no response'));
                    $this->error($apiError);
                    break;
                }

                $orders = $response->json()['data'] ?? [];
                if (empty($orders)) { $sawLastPage = true; break; }

                $this->flushOrders($orders, $date, $stats, $hitPrevDay);
                $this->line("  Page {$p} — {$stats['total']} orders found" . ($hitPrevDay ? ' (reached previous day, stopping)' : ''));

                if ($hitPrevDay) break;
                if (count($orders) < $pageSize) { $sawLastPage = true; break; }
            }

            if ($sawLastPage) break;
            $page = end($pages) + 1;
        }

        // Only advance the delta anchor on success — after a failed run the next
        // delta window must still reach back past the failure, or those orders
        // would be skipped forever.
        if ($apiError === null) {
            Setting::set('last_synced', now()->toDateTimeString());
        }

        $totalSynced = $stats['total'];
        $totalNew    = $stats['new'];
        $upsellCount = $stats['upsell_count'];
        $upsellSales = $stats['upsell_sales'];
        $tsaCount    = $stats['tsa_count'];

        arsort($tsaCount);

        $this->newLine();
        $this->info("=== SYNC COMPLETE ===");
        $this->line("  Date          : {$date->toDateString()} PHT");
        $this->line("  Total orders  : {$totalSynced} ({$totalNew} new)");
        $this->line("  Upsell orders : {$upsellCount}");
        $this->line("  Upsell sales  : ₱" . number_format($upsellSales, 2));

        $this->newLine();
        $this->info("=== TSA BREAKDOWN (upsell orders) ===");
        foreach ($tsaCount as $name => $count) {
            $this->line(\sprintf("  %-20s %d orders", $name, $count));
        }
        if (empty($tsaCount)) {
            $this->warn("  No TSA name tags found.");
        }

        $this->recordRun(
            $runStart,
            success: $apiError === null,
            errorMessage: $apiError,
            totalSynced: $totalSynced,
            newOrders: $totalNew,
            upsellCount: $upsellCount,
            upsellSales: $upsellSales,
        );

        return self::SUCCESS;
    }

    /** Mirrors Http::pool()'s own behavior of returning a failed request as a
     *  Throwable instead of throwing (see the pooled page 2+ branch above) — page
     *  1 is fetched alone, outside the pool, so without this a connection-level
     *  failure (timeout, DNS blip) here would throw straight through handle() as
     *  an uncaught exception instead of the graceful {success:false, error_message}
     *  every caller (manual Sync button, Sync Health) depends on — and recordRun()
     *  below would never even run to log the failure. */
    private function safeGet(string $url, array $headers, array $params): Response|\Throwable
    {
        try {
            return Http::withHeaders($headers)->timeout(30)->get($url, $params);
        } catch (\Throwable $e) {
            return $e;
        }
    }

    /**
     * Parse one API page of raw orders and write them in bulk: one SELECT covering
     * every order on the page (replacing the old per-order cancelled-upsell lookup)
     * and chunked upserts (replacing one updateOrCreate — two queries — per order).
     * Sets $hitPrevDay when it reaches an order last touched before $date: orders
     * arrive most-recently-updated-first, so everything after it is even older and
     * the caller must stop paginating.
     */
    private function flushOrders(array $orders, Carbon $date, array &$stats, bool &$hitPrevDay): void
    {
        $parsed = [];

        foreach ($orders as $raw) {
            $createdAt = $raw['inserted_at'] ?? $raw['created_at'] ?? null;

            // Fix 1: Pancake stores UTC without TZ marker — parse as UTC, convert to Manila
            $carbonPHT = $createdAt
                ? Carbon::parse($createdAt, 'UTC')->setTimezone('Asia/Manila')
                : null;

            // Fix #13: membership in "today's sync" is decided by last-updated time
            // (matching the updateStatus=updated_at filter in handle()), not
            // insertion time — see the fetch-params note there.
            $updatedAtRaw = $raw['updated_at'] ?? null;
            $updatedPHT   = $updatedAtRaw
                ? Carbon::parse($updatedAtRaw, 'UTC')->setTimezone('Asia/Manila')
                : null;
            $activityDate = $updatedPHT?->toDateString();

            if ($activityDate && $activityDate < $date->toDateString()) {
                $hitPrevDay = true;
                break;
            }

            if ($activityDate !== $date->toDateString()) continue;

            // Fix #3/#9: canceled/returned/restocking orders never count as cross-sells
            // (Order::VOID_STATUSES — the single source of truth also used to render
            // each order's status label on the dashboard). Still upsert them (with
            // is_upsell forced false) rather than skip — an order already saved as a
            // valid upsell before it went void needs this re-sync to correct it, not
            // just leave the stale row alone. Checks the numeric `status` code
            // (reliable, matches Pancake's documented enum) rather than the
            // free-text status_name field, which is unreliable.
            $statusCode       = (int) ($raw['status'] ?? -1);
            $isExcludedStatus = in_array($statusCode, Order::VOID_STATUSES, true);

            $tags     = $raw['tags'] ?? [];
            $tagNames = array_map(fn($t) => \is_array($t) ? ($t['name'] ?? '') : (string)$t, $tags);

            $disposition  = $this->extractDisposition($tagNames);
            // hasSeparateParcelTag() folded in directly (explicit request,
            // 2026-08-14, confirmed live on order #1348619/Joana): a real
            // TSA sale can ship as its own standalone Pancake order with
            // ONLY the SEPARATE PARCEL tag on it — no separate "UPSELL TSD"
            // tag at all, and (unlike the scenario the 2026-08-12 fix below
            // handles) no sibling order to inherit one from either. Same
            // trust-the-tag precedent that fix already established for a
            // SEPARATE PARCEL order that looks structurally like a
            // cancelled upsell — this just extends it to also cover one
            // that never had an upsell tag to begin with. extractTsaInfo()
            // already resolves this order's real TSA independently (account
            // signal, falling back to a name tag), so this only ever
            // affects whether an order correctly-attributed to a TSA counts
            // as her upsell, never who it's attributed to.
            // Persisted (not just used to gate $hasUpsellTag below) so every OTHER
            // "was this a real upsell" path can also respect it — see
            // Order::isBroadRealUpsell()'s own doc comment: ProductPerformance::
            // tally()/ordersForColumn() and TsaPerformanceController's per-TSA
            // breakdown all fall back to a bare tag-text scan for a separate edge
            // case (Restocking-status orders) that bypasses is_upsell entirely, so
            // gating only $hasUpsellTag here would leave this order still counted
            // as an upsell everywhere except the Dashboard card/Leaderboard.
            $isExcludedUpsellSeller = $this->isExcludedUpsellSeller($raw);

            $hasUpsellTag = !$isExcludedUpsellSeller
                && (Order::hasUpsellTag($tagNames) || $this->hasUpsellBySeller($raw)
                    || Order::hasSeparateParcelTag($tagNames));

            // Cancelled upsell: the order still carries an upsell tag, but the add-on
            // item(s) have been removed from it while the primary order kept going
            // (remainingItemIsJustTheBase — see that method's doc comment for the
            // exact tag-parsing rule). This is NOT the same as a void/cancelled ORDER
            // (Order::VOID_STATUSES) — the order itself is still active, only the
            // upsell portion was cancelled, so it's excluded from is_upsell here
            // rather than counted as a live cross-sell.
            //
            // Explicit request (2026-08-12), confirmed live on order #1347336
            // (Joana): a SEPARATE PARCEL-tagged order has the exact same
            // structural shape as a cancelled upsell — a single remaining item
            // matching the tag's named base — but for the opposite reason: the
            // add-on was never cancelled, it shipped as its own sibling Pancake
            // order instead (see Order::hasSeparateParcelTag(),
            // LinkSeparateParcelOrders). That's still a real TSA upsell, so it
            // must count toward upsell_confirmation the same as any other live
            // upsell — just excluded here so it isn't miscounted as cancelled.
            $isCancelledUpsell = !$isExcludedStatus && $hasUpsellTag && Order::remainingItemIsJustTheBase($raw)
                && !Order::hasSeparateParcelTag($tagNames);
            $isUpsell          = !$isExcludedStatus && !$isCancelledUpsell && $hasUpsellTag;

            // Returned upsell: the order carries the TSA's upsell tag but its status is
            // Returning/Returned specifically (not Cancelled/Restocking/Deleted, the other
            // VOID_STATUSES). is_upsell above is already forced false for it like any void
            // status, which would otherwise make it impossible to ever report "upsell
            // revenue lost to returns" — this preserves that fact separately, the same way
            // is_cancelled_upsell preserves the add-on-removed case. Unlike a cancelled
            // add-on, a returned order's items array is untouched (only the shipping status
            // changed), so extractUpsellAmount() below still isolates the correct add-on
            // price on every sync — no carry-forward needed.
            $isReturnedUpsell = in_array($statusCode, [4, 5], true) && $hasUpsellTag;

            // Restocking upsell: the order carries the TSA's upsell tag but its status
            // is Restocking specifically (awaiting stock, not Cancelled/Returned/
            // Deleted, the other VOID_STATUSES). is_upsell above is already forced
            // false for it like any void status — deliberately, so a not-yet-shipped
            // order never counts toward confirmed gross sales — but that also made it
            // impossible for the Dashboard's "Total Restocking" KPI to ever find any
            // data: it queries status_code=11 AND is_upsell=true, a combination that
            // can structurally never exist (confirmed in production: 0 of 3553 real
            // Restocking orders have is_upsell=true, even though 271 of them genuinely
            // carry an upsell tag). This preserves the fact separately, the same way
            // is_returned_upsell preserves the Returned/Returning case just above.
            $isRestockingUpsell = $statusCode === 11 && $hasUpsellTag;

            $productInfo       = $this->extractUpsellProduct($raw, $isUpsell);
            $productName       = $productInfo['name'];
            $bundleDescription = $productInfo['display_id'];
            $baseProductName   = $productInfo['base_name'];
            $tsaInfo           = $this->extractTsaInfo($tagNames, $raw, $productName, $bundleDescription);

            // Root-cause fix: $carbonPHT (Pancake's inserted_at) is when the lead/order
            // record was first created — often an automated event (e.g. a Facebook ad
            // lead) hours before any TSA touches it. Every hourly report in this app
            // (TSA Performance, Leads Report, Charts) reads pancake_created_at as "when
            // this call happened", so store the moment the real outcome was decided
            // (from Pancake's histories log) instead, falling back to insertion time
            // when there's no tag to anchor to at all.
            //
            // Fix #16: prefer the DISPOSITION tag's own add-time over the TSA name
            // tag's — confirmed via live histories data (SH Naturals/Sinuxyl,
            // 2026-07-29) that the TSA name tag can land in a tight automated
            // assignment batch well before shift start (e.g. 7:47 AM for an 8:00 AM
            // shift), while the real disposition (Call in Progress -> CONFIRMED VIA
            // CALL/Not answering/etc.) lands hours later, during the actual shift.
            // Anchoring to the assignment tag put genuine calls in an hour bucket
            // before the TSA had even logged in. A still-uncalled lead (no
            // disposition tag yet) has nothing more accurate to anchor to, so it
            // still falls back to the assignment tag in that case.
            //
            // Excess/uncatered leads (no TSA tag ever matched) are a special case of
            // this same problem: through 2026-07-17, Pancake's own end-of-day sweep is
            // what actually made a lead "Excess" by adding the UNCATERED LEADS tag, and
            // — confirmed against live histories data across leads created hours apart
            // — that sweep always landed within the same ~2-minute nightly window
            // (~11:56-11:58 PM Manila), regardless of each lead's own creation time.
            // Falling back to insertion time here would scatter Excess Leads across
            // whatever hour each lead happened to be created in instead of the single
            // hour it was actually swept. $disposition already equals that tag for
            // this case (extractDisposition() matches it like any other outcome), so
            // preferring $disposition first also covers it with no separate variable.
            // Only matters for that legacy tag — a genuinely tagless order (the
            // current definition, since 2026-07-21) has no tag-add history entry to
            // look up anyway, so it already falls back to insertion time on its own.
            $workedAt = self::resolveWorkedAt($raw, $disposition ?? $tsaInfo['matched_tag'], $carbonPHT);

            // Fix 2: For upsell orders, only count the added items (not the original product)
            if ($isUpsell) {
                $this->warnIfAmbiguousUpsellItems($raw);
            }
            // Root-cause fix (2026-08-11): amount must be the isolated add-on
            // price for EVERY row Order::scopeRealUpsell() counts as revenue
            // (is_upsell OR is_returned_upsell) — not just is_upsell. Before
            // this, a Returned/Returning order fell to the total_price
            // branch below (is_upsell is forced false for it, same as any
            // VOID_STATUSES row), so every SUM(amount) query scoped to
            // realUpsell() (Dashboard's Total Cross-Sell Sales, Charts,
            // TsaPerformanceController's upsell_sales) silently included the
            // ORIGINAL product's price too for any upsell that was later
            // returned — confirmed live (2026-08-11), Mariel/Aug 1: order
            // #1340544 stored amount=1300 (full order) instead of 500 (the
            // real add-on value already correctly sitting in
            // returned_upsell_amount two lines below) — an ₱800 overcount
            // from one single order. RtsReportController already knew to
            // read returned_upsell_amount instead of amount for exactly
            // this reason; every other consumer didn't.
            $amount = ($isUpsell || $isReturnedUpsell)
                ? Order::extractUpsellAmount($raw)
                : (float)($raw['total_price'] ?? $raw['cod'] ?? 0);

            // Same value as $amount now for a Returned/Returning row (see the
            // fix above) — kept as its own column anyway since
            // RtsReportController and others already read it by name, and
            // removing the redundancy isn't this fix's job.
            $returnedUpsellAmount = $isReturnedUpsell ? Order::extractUpsellAmount($raw) : 0.0;

            // Same reasoning as $returnedUpsellAmount above, for the Restocking case.
            $restockingUpsellAmount = $isRestockingUpsell ? Order::extractUpsellAmount($raw) : 0.0;

            $parsed[] = [
                'pancake_order_id'        => (string)$raw['id'],
                // Needed to group this order with its "separate parcel"
                // siblings from the same customer — see
                // LinkSeparateParcelOrders. Same field/fallback SyncPancakeLeads
                // already uses for the same purpose.
                'customer_phone'          => $raw['bill_phone_number'] ?? ($raw['customer']['phone_numbers'][0] ?? null),
                'team'                    => $tsaInfo['team'],
                'tsa_name'                => $tsaInfo['name'],
                'disposition'             => $disposition,
                'product'                 => $productName,
                'base_product'            => $baseProductName,
                'bundle_description'      => $bundleDescription,
                // Every real line item's own product_id (a stable Pancake UUID,
                // NOT the small POS-panel display badge like "643" — that lives
                // on the product catalog entry, not the order item) — see the
                // pancake_product_ids migration's doc comment for why this
                // exists: it's what lets ProductPerformance match a product by
                // real catalog ID instead of guessing from text. Deduped since
                // a combo can repeat the same product across 2+ line items.
                'pancake_product_ids'     => json_encode(array_values(array_unique(array_filter(
                    array_column($raw['items'] ?? [], 'product_id')
                )))),
                'amount'                  => $amount,
                'raw_tags'                => $tagNames,
                'is_upsell'               => $isUpsell,
                'is_cancelled_upsell'     => $isCancelledUpsell,
                // null = never captured (this sync never saw the order as a live
                // upsell before Pancake's data showed it cancelled) — distinct from a
                // confirmed ₱0. See the carry-forward logic below, which is the only
                // place this ever gets a real value.
                'cancelled_upsell_amount' => null,
                'is_returned_upsell'      => $isReturnedUpsell,
                'returned_upsell_amount'  => $returnedUpsellAmount,
                'is_restocking_upsell'    => $isRestockingUpsell,
                'restocking_upsell_amount' => $restockingUpsellAmount,
                'excluded_upsell_seller'  => $isExcludedUpsellSeller,
                'status_code'             => $statusCode,
                'pancake_created_at'      => $workedAt?->toDateTimeString(),
                // Pancake's raw inserted_at, untouched — see the orders migration's
                // comment on this column for why it's kept separate from
                // pancake_created_at above.
                'pancake_inserted_at'     => $carbonPHT?->toDateTimeString(),
                'pancake_updated_at'      => $updatedPHT?->toDateTimeString(),
                'synced_at'               => now()->toDateTimeString(),
            ];
        }

        if (empty($parsed)) return;

        // One query for every existing row on this page — replaces both the old
        // per-order cancelled-upsell SELECT and updateOrCreate's per-order lookup.
        $existing = Order::whereIn('pancake_order_id', array_column($parsed, 'pancake_order_id'))
            ->get(['pancake_order_id', 'is_upsell', 'is_cancelled_upsell', 'amount', 'cancelled_upsell_amount'])
            ->keyBy('pancake_order_id');

        $rows = [];
        foreach ($parsed as $row) {
            $prev  = $existing->get($row['pancake_order_id']);
            $isNew = $prev === null;

            // The cancelled add-on's price is gone from Pancake's own data the moment
            // it's removed from the order — `items` simply won't list it anymore. The
            // only place that amount still exists is our own DB row from the LAST sync
            // that saw it as a live upsell, so capture it here before it's overwritten.
            // Once already marked cancelled, keep carrying that preserved amount
            // forward instead of re-deriving it (there's nothing left to derive from).
            if ($row['is_cancelled_upsell']) {
                if ($prev?->is_cancelled_upsell) {
                    // Carry the previous row's value through as-is — including null,
                    // if THAT row never captured a real amount either. Casting null to
                    // (float) would silently turn "unknown" into a false "confirmed ₱0".
                    $row['cancelled_upsell_amount'] = $prev->cancelled_upsell_amount === null
                        ? null
                        : (float) $prev->cancelled_upsell_amount;
                } elseif ($prev?->is_upsell) {
                    $row['cancelled_upsell_amount'] = (float) $prev->amount;
                }
            }

            $stats['total']++;
            if ($isNew) $stats['new']++;
            // Fix #6: only accumulate CLI counters for newly inserted rows to avoid
            // double-counting on re-syncs (DB totals are always correct via SUM)
            if ($row['is_upsell'] && $isNew) {
                $stats['upsell_count']++;
                $stats['upsell_sales'] += $row['amount'];
            }
            // Fix TSA breakdown: count only upsell orders
            if ($row['tsa_name'] && $row['is_upsell']) {
                $stats['tsa_count'][$row['tsa_name']] = ($stats['tsa_count'][$row['tsa_name']] ?? 0) + 1;
            }

            // upsert() goes through the query builder, not the model, so the
            // raw_tags array cast doesn't apply — encode it here ourselves.
            $row['raw_tags'] = json_encode($row['raw_tags']);
            $rows[] = $row;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            Order::upsert(
                $chunk,
                ['pancake_order_id'],
                [
                    'customer_phone',
                    'team', 'tsa_name', 'disposition', 'product', 'base_product', 'bundle_description', 'pancake_product_ids', 'amount', 'raw_tags',
                    'is_upsell', 'is_cancelled_upsell', 'cancelled_upsell_amount',
                    'is_returned_upsell', 'returned_upsell_amount',
                    'is_restocking_upsell', 'restocking_upsell_amount', 'excluded_upsell_seller',
                    'status_code', 'pancake_created_at', 'pancake_inserted_at',
                    'pancake_updated_at', 'synced_at',
                ]
            );
        }
    }

    /** Persist one row per run so the dashboard can show real sync activity over
     *  time (frequency, volume, failures) instead of just "last synced at X". */
    private function recordRun(
        Carbon $runStart,
        bool $success,
        ?string $errorMessage = null,
        int $totalSynced = 0,
        int $newOrders = 0,
        int $upsellCount = 0,
        float $upsellSales = 0,
    ): void {
        SyncRun::create([
            'ran_at'        => $runStart,
            'total_synced'  => $totalSynced,
            'new_orders'    => $newOrders,
            'upsell_count'  => $upsellCount,
            'upsell_sales'  => $upsellSales,
            // diffInMilliseconds() can return a float (sub-millisecond precision) —
            // MySQL silently truncated that into its integer column, but Postgres
            // rejects non-integer text for one outright, crashing this insert on
            // every single run there.
            'duration_ms'   => (int) round($runStart->diffInMilliseconds(now())),
            'success'       => $success,
            // Redacted BEFORE it ever reaches the database, not just before display
            // (Sync Health's table and the manual-retry flash message both already
            // redacted at render time — see SyncHealthController::retry() and
            // sync-health.blade.php — but the raw column itself still stored
            // whatever a failed HTTP client threw, which for a connection error
            // (Guzzle) typically includes the full request URL, api_key and all.
            // Redacting at write time means every future read of this column is
            // safe by construction, not dependent on each one remembering to redact).
            'error_message' => SyncHealth::redactSecrets($errorMessage),
        ]);
    }

    // For upsell orders, show the upsell product name (item 1+), not the original
    /** Returns ['name' => ..., 'display_id' => ..., 'base_name' => ...] for the same
     *  item extractUpsellProduct() used to return just the name for. display_id is
     *  the item's full variation_info.display_id text (e.g. "1 Ginseng Serum + 5
     *  Scar Cream" for a combo SKU) — 'name' alone only ever reflects the catalog
     *  entry's generic label (e.g. "GINSENG SERUM"), which silently hides any other
     *  product bundled into the same combo. 'base_name' is the customer's
     *  ORIGINALLY-ordered item — the one that isn't the upsell — so it survives
     *  even once 'name'/`product` gets overwritten with the upsold item below.
     *  Confirmed in production: an AudiCure order upsold to Ear Relief Balm leaves
     *  no OTHER signal anywhere (not `product`, not `bundle_description`, not
     *  `raw_tags`) pointing back to AudiCure, silently dropping it from Leads
     *  Report's per-product count even though Pancake's own product search still
     *  finds it. Kept as one method (not a second lookup) so all three values
     *  always come from the exact same item(s) — the upsell-hint-index logic below
     *  is nontrivial enough that re-deriving it twice risked them ever pointing at
     *  different items. */
    private function extractUpsellProduct(array $raw, bool $isUpsell): array
    {
        $items = $raw['items'] ?? [];
        if (empty($items)) return ['name' => null, 'display_id' => null, 'base_name' => null];

        $itemName = fn (array $item) => ($item['variation_info'] ?? [])['name'] ?? $item['product_name'] ?? null;

        if ($isUpsell) {
            if (count($items) < 2 && Order::remainingItemIsJustTheBase($raw)) {
                return ['name' => null, 'display_id' => null, 'base_name' => null]; // upsell add-on was removed; nothing valid to show
            }
            $hintIndex = Order::findItemIndexByTagHint($raw);
            if ($hintIndex !== null) {
                $vi = $items[$hintIndex]['variation_info'] ?? [];
                // Base = the other item, whichever one ISN'T the tag-identified
                // upsell — matching Fix #7's discovery that array order isn't
                // trustworthy (order #1325787 had the base item listed AFTER the
                // upsell).
                $baseIndex = $hintIndex === 0 ? (count($items) > 1 ? 1 : null) : 0;
                return [
                    'name'        => $vi['name'] ?? $items[$hintIndex]['product_name'] ?? null,
                    'display_id'  => $vi['display_id'] ?? null,
                    'base_name'   => $baseIndex !== null ? $itemName($items[$baseIndex]) : null,
                ];
            }
        }

        $index = ($isUpsell && count($items) >= 2) ? 1 : 0;
        $vi    = $items[$index]['variation_info'] ?? [];
        // No tag hint (or not an upsell at all) — same positional convention
        // already used elsewhere in this file (extractUpsellAmount's fallback,
        // Fix #8): item 0 is always the ORIGINAL/base product, so it doubles as
        // 'base_name' whether or not this order turns out to be an upsell.
        return [
            'name'       => $vi['name'] ?? $items[$index]['product_name'] ?? null,
            'display_id' => $vi['display_id'] ?? null,
            'base_name'  => $itemName($items[0]),
        ];
    }

    /**
     * extractUpsellAmount()'s fallback (no "(Product Name)" tag hint) assumes the
     * ORIGINAL item is always items[0] and everything after it is the TSA's add-on
     * — Pancake doesn't guarantee that ordering (see Fix #7/#8 above), so when 2+
     * items share the exact same product name, there's no name-based signal left to
     * tell which one is really the base vs. the add-on either. Rather than silently
     * trust array position for a case we can independently see is ambiguous, this
     * logs it so a human can check the actual order in Pancake — the computed
     * amount still uses the same positional fallback as always (no better rule
     * exists to replace it with), this only makes the guess visible instead of
     * invisible.
     */
    private function warnIfAmbiguousUpsellItems(array $raw): void
    {
        $items = $raw['items'] ?? [];
        if (count($items) < 2) return;
        if (Order::findItemIndexByTagHint($raw) !== null) return;

        $names = array_map(function ($item) {
            $vi = $item['variation_info'] ?? [];
            return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $vi['name'] ?? $item['product_name'] ?? ''));
        }, $items);

        $duplicated = array_filter(array_count_values(array_filter($names)), fn($count) => $count > 1);
        if (empty($duplicated)) return;

        Log::warning('SyncTodayOrders: ambiguous upsell item order — 2+ items share the same product name with no tag hint to disambiguate the add-on', [
            'pancake_order_id' => $raw['id'] ?? null,
            'item_names'       => array_map(fn($item) => $item['variation_info']['name'] ?? $item['product_name'] ?? null, $items),
            'item_prices'      => array_map(fn($item) => $item['variation_info']['retail_price'] ?? null, $items),
        ]);
    }

    private function extractTsaInfo(array $tagNames, array $raw = [], ?string $productName = null, ?string $bundleDescription = null): array
    {
        // Primary: assigning_seller on the upsell item(s) (index 1+) — a real Pancake
        // account (who actually closed the add-on), not free-text tag parsing. Tags
        // stay reliable in the overwhelming majority of cases (confirmed live,
        // 2026-08-07: 45 of 46 upsell orders on one date agreed with the account
        // signal — the 1 disagreement was an admin editing a stale order after the
        // fact, not a genuine TSA mismatch) but are structurally vulnerable to an
        // order carrying two different TSAs' name tags at once, which
        // extractTsaInfo() would previously resolve by whichever tag Pancake happened
        // to list first — not necessarily the TSA who actually gets credit. The
        // account field can't have that ambiguity: Pancake records exactly one
        // assigning_seller per item. Only ever checked for items[1:] — item 0 is the
        // customer's original/base product, whose assigning_seller is the marketer/
        // page account that created the lead, not a TSA, so it's deliberately never
        // consulted here.
        $sellerInfo = null;
        foreach (\array_slice($raw['items'] ?? [], 1) as $item) {
            $seller = strtolower($item['assigning_seller']['name'] ?? '');
            foreach ($this->sellerMap as $keyword => $info) {
                if ($seller !== '' && str_contains($seller, $keyword)) {
                    $sellerInfo = $info;
                    break 2;
                }
            }
        }

        // matched_tag still comes from the tag list when one agrees with whoever the
        // account signal above identified — resolveWorkedAt() uses it to find the
        // precise moment that tag was added in Pancake's histories log. Without this,
        // every order with an account match would fall back to insertion time instead
        // of the more accurate tag-add time, even when a perfectly good tag exists.
        // Deliberately ignores a DISAGREEING tag (a different TSA's name) rather than
        // trusting its timestamp for the account-identified TSA.
        $matchedTag = null;
        foreach ($tagNames as $tag) {
            $key = strtoupper(trim($tag));
            if (isset($this->tsaMap[$key]) && (!$sellerInfo || $this->tsaMap[$key]['name'] === $sellerInfo['name'])) {
                $matchedTag = $key;
                break;
            }
        }

        if ($sellerInfo) {
            return $sellerInfo + ['matched_tag' => $matchedTag];
        }

        // Fallback: no assigning_seller match — e.g. a single-item order (nothing at
        // index 1+ to check at all) or a genuinely new TSA whose seller_keywords
        // hasn't been configured on the TSA Management page yet. Same tag-scan this
        // method always used as its primary signal before the account check above.
        foreach ($tagNames as $tag) {
            $key = strtoupper(trim($tag));
            if (isset($this->tsaMap[$key])) {
                return $this->tsaMap[$key] + ['matched_tag' => $key];
            }
        }

        // Fix #15: last resort — no TSA tag AND no seller match means nobody ever
        // claimed this lead (e.g. a brand-new order swept by the midnight "UNCATERED
        // LEADS" bulk action before any human touched it). It still has a real product
        // in its cart though (captured separately as $productName from
        // extractUpsellProduct()), so match that against each team's product list to
        // recover the TEAM — never a TSA name, since genuinely nobody claimed it — so
        // the lead counts as Excess for the right team instead of vanishing from every
        // report (confirmed via production data: 46 such orders on one date alone,
        // 41 of them clearly SH Naturals products by cart contents).
        if ($team = $this->inferTeamFromProduct($productName)) {
            return ['name' => null, 'team' => $team, 'matched_tag' => null];
        }

        // Cart name alone matched nothing — a combo SKU's generic name only ever
        // names its primary component, so try the full bundle description text
        // (e.g. "1 Ginseng Serum + 5 Scar Cream") before falling through to tags.
        if ($team = $this->inferTeamFromProduct($bundleDescription)) {
            return ['name' => null, 'team' => $team, 'matched_tag' => null];
        }

        // Cart name matched nothing — try the order's tags too (a lead sometimes
        // carries a product tag like "CLEARSIGHT" even when nobody claimed it).
        foreach ($tagNames as $tag) {
            if ($team = $this->inferTeamFromProduct($tag)) {
                return ['name' => null, 'team' => $team, 'matched_tag' => null];
            }
        }

        return ['name' => null, 'team' => null, 'matched_tag' => null];
    }

    private function inferTeamFromProduct(?string $productName): ?string
    {
        if (!$productName) return null;

        // Sourced from the products table (Product Management page) instead of
        // config/teams.php — see docs/superpowers/specs/2026-07-06-product-management-design.md.
        // $this->products is loaded once in handle() (same reasoning as $tsaMap/
        // $sellerMap) so this doesn't re-query on every single order.
        // matchesText (not stripos on one keyword): honors every configured alias
        // and ignores spacing/punctuation, so a cart named "Clear Sight 3.0" maps
        // to CLEARSIGHT's team instead of leaving the lead team-NULL and therefore
        // invisible to every report (122 such leads in the 14 days before this fix).
        foreach ($this->products as $product) {
            if ($product->matchesText($productName)) {
                return $product->team;
            }
        }

        return null;
    }

    /**
     * The moment this order's defining tag was actually added, not when the lead/order
     * record was created. Pancake's `histories` log records every tag-list change with a
     * timestamp; this finds the first entry where $matchedTag newly appears. Usually
     * $matchedTag is the real disposition tag (preferred — see the caller) or, failing
     * that, the TSA's own name tag; the caller also passes 'UNCATERED LEADS' here for
     * excess/uncatered orders, since that tag's own addition time (Pancake's nightly
     * sweep) is what should anchor those rows, not their original creation time either.
     * Falls back to $insertedAt when there's no tag to match, or the tag was already
     * present at creation (no history diff to find).
     */
    public static function resolveWorkedAt(array $raw, ?string $matchedTag, ?Carbon $insertedAt): ?Carbon
    {
        if ($matchedTag === null || $insertedAt === null) {
            return $insertedAt;
        }

        // Normalized the same way tagNamesOf() normalizes histories' own tag names
        // below — matched_tag (a tsaMap key) is already upper/trimmed, but a raw
        // disposition tag from Pancake (e.g. "Not answering ", trailing space and
        // all) isn't, and the strict in_array() comparison below would otherwise
        // silently never match it.
        $matchedTag  = strtoupper(trim($matchedTag));
        $tagAt       = null;
        $tagEditorId = null;

        foreach ($raw['histories'] ?? [] as $entry) {
            if (!isset($entry['tags']['new'])) continue;

            $newNames = self::tagNamesOf($entry['tags']['new']);
            $oldNames = self::tagNamesOf($entry['tags']['old'] ?? []);

            if (\in_array($matchedTag, $newNames, true) && !\in_array($matchedTag, $oldNames, true)) {
                $tagAt = isset($entry['updated_at'])
                    ? Carbon::parse($entry['updated_at'], 'UTC')->setTimezone('Asia/Manila')
                    : $insertedAt;
                $tagEditorId = $entry['editor_id'] ?? null;
                break;
            }
        }

        if ($tagAt === null) {
            return $insertedAt;
        }

        // Fix #17: the generic "Call in Progress"/"CIP" catch-all (what
        // extractDisposition() falls back to when no more specific outcome tag
        // exists yet) can itself be auto-added in the SAME automated batch as the
        // TSA assignment tag — confirmed live (order 1339220: GEMMA, SINUXYL, and
        // "Call in Progress (Sinuxyl Inhaler)" were all added in one event at
        // 7:46 AM, while Gemma's real work — the order's status moving from New to
        // Purchasing, then the actual "UPSELL TSD" tag — didn't happen until
        // 8:40 AM). The fix above (prefer the disposition tag's own add-time over
        // the assignment tag's) doesn't help this case, since they're the same
        // stale timestamp.
        //
        // First attempt at this (prefer ANY later status change) was itself wrong
        // — confirmed live against orders 1338554/1338808/1339021/1339088: a
        // Pancake order keeps getting status changes for HOURS after the real call
        // (Purchasing -> Confirmed -> sent-to-partner -> "Picked up"), driven by
        // the courier/fulfillment webhook (J&T Express — the `partner` field's
        // picked_up_at matches these exactly) under shared system editor_ids
        // (e.g. a84af695..., 4b09ee22..., or null) that recur identically across
        // totally unrelated orders and TSAs — never the TSA's own account. That
        // wrongly anchored several orders to a courier pickup timestamp hours
        // later instead of the real, much-earlier call time.
        //
        // A status change only counts as evidence of real work when it was made by
        // the SAME editor_id who added the disposition tag — i.e. the same person
        // continuing to work this exact order (confirmed live: order 1339220's own
        // status change WAS made by the same editor, GEMMA DIAZ, as the tag add).
        if ($tagEditorId !== null && (str_contains($matchedTag, 'CALL IN PROGRESS') || $matchedTag === 'CIP')) {
            foreach ($raw['histories'] ?? [] as $entry) {
                if (!isset($entry['status']['old'], $entry['status']['new'], $entry['updated_at'])) continue;
                if ($entry['status']['old'] === $entry['status']['new']) continue;
                if (($entry['editor_id'] ?? null) !== $tagEditorId) continue;

                $statusAt = Carbon::parse($entry['updated_at'], 'UTC')->setTimezone('Asia/Manila');
                if ($statusAt->gt($tagAt)) {
                    return $statusAt;
                }
            }
        }

        return $tagAt;
    }

    private static function tagNamesOf(array $tags): array
    {
        return array_map(
            fn($t) => strtoupper(trim(\is_array($t) ? ($t['name'] ?? '') : (string)$t)),
            $tags
        );
    }

    // True if any item at index 1+ was added by a known TSA seller account
    private function hasUpsellBySeller(array $raw): bool
    {
        foreach (\array_slice($raw['items'] ?? [], 1) as $item) {
            $seller = strtolower($item['assigning_seller']['name'] ?? '');
            foreach ($this->sellerMap as $keyword => $_) {
                if ($seller !== '' && str_contains($seller, $keyword)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * True if any item at index 1+ (the same scope hasUpsellBySeller()/
     * extractTsaInfo() check) was closed by a known non-TSA account —
     * config/excluded_upsell_sellers.php. Checked ahead of every upsell
     * signal (hasUpsellTag(), hasUpsellBySeller(), hasSeparateParcelTag()),
     * not just the seller-signal path — a tag like "UPSELL TSD - ..." isn't
     * a genuine TSA sale just because someone applied it; if the account
     * that touched the item is known to not be a TSA, the tag is a false
     * signal too. See that config file's own doc comment for the incident
     * this fixes (order #1352836, account "Ralph Cruz").
     */
    private function isExcludedUpsellSeller(array $raw): bool
    {
        $excluded = array_map('strtolower', config('excluded_upsell_sellers', []));
        if (empty($excluded)) return false;

        foreach (\array_slice($raw['items'] ?? [], 1) as $item) {
            $seller = strtolower($item['assigning_seller']['name'] ?? '');
            if ($seller === '') continue;
            foreach ($excluded as $needle) {
                if (str_contains($seller, $needle)) return true;
            }
        }
        return false;
    }

    private function extractDisposition(array $tagNames): ?string
    {
        $keywords = [
            'NOT ANSWERING', 'UNATTENDED', 'CONFIRMED VIA CALL',
            'CALL DROPPED', 'CALL BACK', 'INVALID NUMBER', 'RUDE CUSTOMER',
            'RELATIVES CONFIRMATION', 'DFR', 'FSD UNCLEARED ORDER',
            // Fix #11: the real Pancake tag is just "REPEAT ORDER" — the longer phrase
            // here ("...WITH UPSELL STOCKS") can never match as a substring of the
            // shorter real tag, so this disposition was never being recognized.
            // "DOUBLE ORDER" and "RUDE CUSTOMER" were checked against the full 7,491-tag
            // catalog and don't exist under any wording — left in case they're added later.
            'REPEAT ORDER', 'DOUBLE ORDER',
            'Call in Progress', 'CIP',
            // Fix #14: this used to be checked FIRST, so any order that already had a
            // real, specific outcome tag (NOT ANSWERING, UNATTENDED, CALL BACK, etc.) but
            // ALSO got swept by the midnight bulk "UNCATERED LEADS" action (confirmed via
            // production data: 100% of a 92-order sample had a real tag sitting right next
            // to "UNCATERED LEADS") had that real disposition silently discarded — the
            // report showed it as Excess/Uncatered instead of its true outcome. Checking it
            // LAST (same fix already applied to "Call in Progress"/"CIP" above, for the
            // exact same reason) means a real disposition always wins; this only applies
            // when nothing more specific matched.
            'UNCATERED LEADS',
        ];
        // Fix #12: this used to loop tag-outer/keyword-inner, returning on the FIRST
        // tag that matched ANY keyword — including the generic "Call in Progress"/"CIP"
        // catch-all, which is usually listed first (SH Naturals' standard pattern:
        // ["Call in Progress (Product)", TSA_NAME, "RUDE CUSTOMER", ...]). That meant a
        // more specific outcome tag like RUDE CUSTOMER, listed later in the same array,
        // was never reached — "Call in Progress" always won first. Now it loops
        // keyword-outer (in priority order) so a specific outcome is found across the
        // whole tag list before ever falling through to the generic catch-all.
        foreach ($keywords as $kw) {
            foreach ($tagNames as $tag) {
                // Fix #10: SH Naturals tags this as "RELATIVE'S CONFIRMATION-<product>"
                // (apostrophe + product suffix) vs Eyecare's plain "RELATIVES CONFIRMATION" —
                // the apostrophe broke the substring match, so SH Naturals' version was
                // never recognized as a disposition at all. Strip apostrophes before matching.
                $normalized = str_replace("'", '', $tag);
                if (stripos($normalized, $kw) !== false) return $tag;
            }
        }
        return null;
    }

}
