<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\TsaShift;
use Illuminate\Console\Command;
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

    public function handle(): int
    {
        ini_set('memory_limit', '-1');
        $runStart = now();
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
                ? [(string) $pages[0] => Http::withHeaders($headers)->timeout(30)->get($url, $fetchParams + ['page_number' => $pages[0]])]
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
            $hasUpsellTag = $this->hasUpsellTag($tagNames) || $this->hasUpsellBySeller($raw);

            // Cancelled upsell: the order still carries an upsell tag, but the add-on
            // item(s) have been removed from it while the primary order kept going
            // (remainingItemIsJustTheBase — see that method's doc comment for the
            // exact tag-parsing rule). This is NOT the same as a void/cancelled ORDER
            // (Order::VOID_STATUSES) — the order itself is still active, only the
            // upsell portion was cancelled, so it's excluded from is_upsell here
            // rather than counted as a live cross-sell.
            $isCancelledUpsell = !$isExcludedStatus && $hasUpsellTag && $this->remainingItemIsJustTheBase($raw);
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

            $productName       = $this->extractUpsellProduct($raw, $isUpsell);
            $tsaInfo           = $this->extractTsaInfo($tagNames, $raw, $productName);

            // Root-cause fix: $carbonPHT (Pancake's inserted_at) is when the lead/order
            // record was first created — often an automated event (e.g. a Facebook ad
            // lead) hours before any TSA touches it. Every hourly report in this app
            // (TSA Performance, Leads Report, Charts) reads pancake_created_at as "when
            // this call happened", so store the moment the TSA's own tag was actually
            // added (from Pancake's histories log) instead, falling back to insertion
            // time when the tag was already present at creation.
            //
            // Excess/uncatered leads (no TSA tag ever matched) are a special case of
            // this same problem: Pancake's own end-of-day sweep is what actually makes
            // a lead "Excess" by adding the UNCATERED LEADS tag, and — confirmed
            // against live histories data across leads created hours apart — that sweep
            // always lands within the same ~2-minute nightly window (~11:56-11:58 PM
            // Manila), regardless of each lead's own creation time. Falling back to
            // insertion time here would scatter Excess Leads across whatever hour each
            // lead happened to be created in instead of the single hour it was
            // actually swept.
            $excessSweepTag = ($tsaInfo['name'] === null && $disposition === 'UNCATERED LEADS') ? 'UNCATERED LEADS' : null;
            $workedAt = self::resolveWorkedAt($raw, $tsaInfo['matched_tag'] ?? $excessSweepTag, $carbonPHT);

            // Fix 2: For upsell orders, only count the added items (not the original product)
            if ($isUpsell) {
                $this->warnIfAmbiguousUpsellItems($raw);
            }
            $amount = $isUpsell
                ? $this->extractUpsellAmount($raw)
                : (float)($raw['total_price'] ?? $raw['cod'] ?? 0);

            // See $isReturnedUpsell comment above — this is the isolated add-on price,
            // computed independently of $amount (which holds the whole order's total
            // for this row, since is_upsell is forced false by the void status).
            $returnedUpsellAmount = $isReturnedUpsell ? $this->extractUpsellAmount($raw) : 0.0;

            $parsed[] = [
                'pancake_order_id'        => (string)$raw['id'],
                'team'                    => $tsaInfo['team'],
                'tsa_name'                => $tsaInfo['name'],
                'disposition'             => $disposition,
                'product'                 => $productName,
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
                'status_code'             => $statusCode,
                'pancake_created_at'      => $workedAt?->toDateTimeString(),
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
                    'team', 'tsa_name', 'disposition', 'product', 'amount', 'raw_tags',
                    'is_upsell', 'is_cancelled_upsell', 'cancelled_upsell_amount',
                    'is_returned_upsell', 'returned_upsell_amount',
                    'status_code', 'pancake_created_at', 'pancake_updated_at', 'synced_at',
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
            'error_message' => $errorMessage,
        ]);
    }

    // Sum retail_price of items AFTER the first — those are the items the TSA added
    private function extractUpsellAmount(array $raw): float
    {
        $items = $raw['items'] ?? [];

        // Single-item order tagged UPSELL TSD: that item IS the upsell (original was voided)
        if (count($items) < 2) {
            // Fix #8: this assumption is backwards when it's the ADD-ON that got
            // removed instead of the original (order #1325213: customer cancelled
            // the upsell, staff deleted the LUMICARE OIL line, leaving only the
            // original Clear Sight 3.0 — that's not a ₱1,000 upsell, it's ₱0).
            if ($this->remainingItemIsJustTheBase($raw)) {
                return 0.0;
            }
            $vi = $items[0]['variation_info'] ?? [];
            return (float)($vi['retail_price'] ?? $raw['total_price'] ?? $raw['cod'] ?? 0);
        }

        // Fix #7: a "(Product Name)" tag names one exact upsell item — match it by
        // name instead of assuming item order, since Pancake doesn't guarantee the
        // added-on item is listed last (see order #1325787).
        $hintIndex = $this->findItemIndexByTagHint($raw);
        if ($hintIndex !== null) {
            $vi    = $items[$hintIndex]['variation_info'] ?? [];
            $price = (float)($vi['retail_price'] ?? 0);
            $qty   = (int)($items[$hintIndex]['quantity'] ?? 1);
            return $price * $qty;
        }

        $upsellAmount = 0.0;
        foreach (\array_slice($items, 1) as $item) {
            $vi    = $item['variation_info'] ?? [];
            $price = (float)($vi['retail_price'] ?? 0);
            $qty   = (int)($item['quantity'] ?? 1);
            $upsellAmount += $price * $qty;
        }

        // Fix #2: never fall back to total_price — that includes item 0 (original product).
        // If retail_price is missing/zero on all upsell items, return 0 rather than
        // inflating the total with the original order value.
        return $upsellAmount;
    }

    // For upsell orders, show the upsell product name (item 1+), not the original
    private function extractUpsellProduct(array $raw, bool $isUpsell): ?string
    {
        $items = $raw['items'] ?? [];
        if (empty($items)) return null;

        if ($isUpsell) {
            if (count($items) < 2 && $this->remainingItemIsJustTheBase($raw)) {
                return null; // upsell add-on was removed; nothing valid to show
            }
            $hintIndex = $this->findItemIndexByTagHint($raw);
            if ($hintIndex !== null) {
                $vi = $items[$hintIndex]['variation_info'] ?? [];
                return $vi['name'] ?? $items[$hintIndex]['product_name'] ?? null;
            }
        }

        $index = ($isUpsell && count($items) >= 2) ? 1 : 0;
        $vi    = $items[$index]['variation_info'] ?? [];
        return $vi['name'] ?? $items[$index]['product_name'] ?? null;
    }

    // Fix #8: "UPSELL TSD - Base + Addon1 + Addon2" names the ORIGINAL product
    // first (before the first "+"); the add-ons after it are the actual upsell.
    // If only one item remains on the order and it matches that base name, the
    // add-on was removed (upsell cancelled) — the remaining item is NOT an
    // upsell, regardless of what the (now-stale) tag still says.
    private function remainingItemIsJustTheBase(array $raw): bool
    {
        $items = $raw['items'] ?? [];
        if (count($items) !== 1) return false;

        $tags     = $raw['tags'] ?? [];
        $tagNames = array_map(fn($t) => \is_array($t) ? ($t['name'] ?? '') : (string)$t, $tags);

        foreach ($tagNames as $tag) {
            if (!preg_match('/(?:UPSELL\s+TSD|TSD\s+UPSELL)\s*-\s*(.+)/i', $tag, $m)) {
                continue;
            }
            $parts = array_map('trim', explode('+', $m[1]));
            if (count($parts) < 2) continue; // no base/add-on split named in this tag

            $base = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $parts[0]));
            if ($base === '') continue;

            $vi   = $items[0]['variation_info'] ?? [];
            $name = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $vi['name'] ?? $items[0]['product_name'] ?? ''));

            if ($name !== '' && str_contains($name, $base)) {
                return true;
            }
        }

        return false;
    }

    // Fix #7: "Upsell TSD (Product Name)" tags name one exact product. Find the
    // item whose name matches it, rather than trusting item array order — order
    // #1325787 had AudiCure (the customer's original repeat item) listed after
    // Ear Relief Balm (the actual TSA upsell), which the old index-1 assumption
    // recorded backwards.
    private function findItemIndexByTagHint(array $raw): ?int
    {
        $tags     = $raw['tags'] ?? [];
        $tagNames = array_map(fn($t) => \is_array($t) ? ($t['name'] ?? '') : (string)$t, $tags);

        $hint = null;
        foreach ($tagNames as $tag) {
            if (preg_match('/UPSELL\s+TSD\s*\(([^)]+)\)/i', $tag, $m)) {
                $hint = trim($m[1]);
                break;
            }
        }
        if ($hint === null) return null;

        $items    = $raw['items'] ?? [];
        $hintNorm = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $hint));

        foreach ($items as $i => $item) {
            $vi       = $item['variation_info'] ?? [];
            $name     = $vi['name'] ?? $item['product_name'] ?? '';
            $nameNorm = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $name));
            if ($nameNorm !== '' && str_contains($nameNorm, $hintNorm)) {
                return $i;
            }
        }

        return null;
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
        if ($this->findItemIndexByTagHint($raw) !== null) return;

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

    private function extractTsaInfo(array $tagNames, array $raw = [], ?string $productName = null): array
    {
        // Primary: explicit name tag (JULIE, GEMMA, etc.)
        foreach ($tagNames as $tag) {
            $key = strtoupper(trim($tag));
            if (isset($this->tsaMap[$key])) {
                // matched_tag lets resolveWorkedAt() find, in Pancake's histories log,
                // the moment this specific tag was actually added to the order.
                return $this->tsaMap[$key] + ['matched_tag' => $key];
            }
        }

        // Fallback: check assigning_seller on upsell items (index 1+)
        foreach (\array_slice($raw['items'] ?? [], 1) as $item) {
            $seller = strtolower($item['assigning_seller']['name'] ?? '');
            foreach ($this->sellerMap as $keyword => $info) {
                if ($seller !== '' && str_contains($seller, $keyword)) {
                    // No tag was matched, so there's no history entry to look up —
                    // resolveWorkedAt() falls back to insertion time for this case.
                    return $info + ['matched_tag' => null];
                }
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
     * $matchedTag is the TSA's own name tag (typically added well after $insertedAt for
     * auto-created leads) — but the caller also passes 'UNCATERED LEADS' here for
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

        foreach ($raw['histories'] ?? [] as $entry) {
            if (!isset($entry['tags']['new'])) continue;

            $newNames = self::tagNamesOf($entry['tags']['new']);
            $oldNames = self::tagNamesOf($entry['tags']['old'] ?? []);

            if (\in_array($matchedTag, $newNames, true) && !\in_array($matchedTag, $oldNames, true)) {
                return isset($entry['updated_at'])
                    ? Carbon::parse($entry['updated_at'], 'UTC')->setTimezone('Asia/Manila')
                    : $insertedAt;
            }
        }

        return $insertedAt;
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

    private function hasUpsellTag(array $tagNames): bool
    {
        foreach ($tagNames as $tag) {
            // "UPSELL TSD" or "TSD UPSELL" — exclude "Follow up - Upsell" (disposition, not a new order)
            if (preg_match('/\bUPSELL\s+TSD\b|\bTSD\s+UPSELL\b/i', $tag)) {
                return true;
            }
        }
        return false;
    }
}
