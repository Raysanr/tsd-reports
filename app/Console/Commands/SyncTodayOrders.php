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

class SyncTodayOrders extends Command
{
    protected $signature   = 'pancake:sync-today {--date= : Date to sync (Y-m-d, Philippine time), defaults to today}';
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

        $this->info("Syncing orders for: {$date->toDateString()} (Philippine Time)");
        $this->newLine();

        $page        = 1;
        $pageSize    = 100;
        $totalSynced = 0;
        $totalNew    = 0;
        $upsellSales = 0;
        $tsaCount    = [];
        $upsellCount = 0;
        $hitPrevDay  = false;
        $apiError    = null;

        do {
            $response = Http::withHeaders(['Accept' => 'application/json'])
                ->timeout(30)
                ->get("https://pos.pages.fm/api/v1/shops/{$shopId}/orders", [
                    'api_key'       => $apiKey,
                    'page_number'   => $page,
                    'page_size'     => $pageSize,
                    // Fix #13: a lead can be created days before a TSA actually works it
                    // (e.g. a backlog Facebook lead) — paginating/cutting off by
                    // inserted_at (the old approach) silently drops orders worked today
                    // but created earlier, since they're buried past the "reached
                    // previous day" stop point. Fetch by *last updated* time instead, so
                    // anything touched today — regardless of age — actually gets pulled.
                    'updateStatus'  => 'updated_at',
                    'startDateTime' => $startOfDayTs,
                    'endDateTime'   => $endOfDayTs,
                    'option_sort'   => 'last_updated_order_desc',
                ]);

            if (!$response->successful()) {
                $apiError = "API error on page {$page}: " . $response->status();
                $this->error($apiError);
                break;
            }

            $body   = $response->json();
            $orders = $body['data'] ?? [];

            if (empty($orders)) break;

            foreach ($orders as $raw) {
                $createdAt = $raw['inserted_at'] ?? $raw['created_at'] ?? null;

                // Fix 1: Pancake stores UTC without TZ marker — parse as UTC, convert to Manila
                $carbonPHT = $createdAt
                    ? Carbon::parse($createdAt, 'UTC')->setTimezone('Asia/Manila')
                    : null;

                // Fix #13: membership in "today's sync" is decided by last-updated time
                // (matching the updateStatus=updated_at filter above), not insertion
                // time — see note above.
                $updatedAtRaw = $raw['updated_at'] ?? null;
                $updatedPHT   = $updatedAtRaw
                    ? Carbon::parse($updatedAtRaw, 'UTC')->setTimezone('Asia/Manila')
                    : null;
                $activityDate = $updatedPHT?->toDateString();

                // Orders come most-recently-updated-first; once we reach one last
                // touched before today, every order after it (on this page and all
                // following pages) is even older — stop the entire loop immediately.
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
                $productName       = $this->extractUpsellProduct($raw, $isUpsell);
                $tsaInfo     = $this->extractTsaInfo($tagNames, $raw, $productName);
                $tsaName     = $tsaInfo['name'];
                $team        = $tsaInfo['team'];

                // Root-cause fix: $carbonPHT (Pancake's inserted_at) is when the lead/order
                // record was first created — often an automated event (e.g. a Facebook ad
                // lead) hours before any TSA touches it. Every hourly report in this app
                // (TSA Performance, Team Report, Charts) reads pancake_created_at as "when
                // this call happened", so store the moment the TSA's own tag was actually
                // added (from Pancake's histories log) instead, falling back to insertion
                // time when the tag was already present at creation.
                $workedAt = self::resolveWorkedAt($raw, $tsaInfo['matched_tag'] ?? null, $carbonPHT);

                // Fix 2: For upsell orders, only count the added items (not the original product)
                $amount = $isUpsell
                    ? $this->extractUpsellAmount($raw)
                    : (float)($raw['total_price'] ?? $raw['cod'] ?? 0);

                // The cancelled add-on's price is gone from Pancake's own data the moment
                // it's removed from the order — `items` simply won't list it anymore. The
                // only place that amount still exists is our own DB row from the LAST sync
                // that saw it as a live upsell, so capture it here before it's overwritten.
                // Once already marked cancelled, keep carrying that preserved amount
                // forward instead of re-deriving it (there's nothing left to derive from).
                $cancelledUpsellAmount = 0.0;
                if ($isCancelledUpsell) {
                    $existing = Order::where('pancake_order_id', (string)$raw['id'])->first();
                    if ($existing?->is_cancelled_upsell) {
                        $cancelledUpsellAmount = (float) $existing->cancelled_upsell_amount;
                    } elseif ($existing?->is_upsell) {
                        $cancelledUpsellAmount = (float) $existing->amount;
                    }
                }

                $order = Order::updateOrCreate(
                    ['pancake_order_id' => (string)$raw['id']],
                    [
                        'team'                    => $team,
                        'tsa_name'                => $tsaName,
                        'disposition'             => $disposition,
                        'product'                 => $productName,
                        'amount'                  => $amount,
                        'raw_tags'                => $tagNames,
                        'is_upsell'               => $isUpsell,
                        'is_cancelled_upsell'     => $isCancelledUpsell,
                        'cancelled_upsell_amount' => $cancelledUpsellAmount,
                        'status_code'             => $statusCode,
                        'pancake_created_at'      => $workedAt,
                        'synced_at'               => now(),
                    ]
                );

                if ($order->wasRecentlyCreated) $totalNew++;
                $totalSynced++;
                // Fix #6: only accumulate CLI counters for newly inserted rows to avoid
                // double-counting on re-syncs (DB totals are always correct via SUM)
                if ($isUpsell && $order->wasRecentlyCreated) {
                    $upsellCount++;
                    $upsellSales += $amount;
                }
                // Fix TSA breakdown: count only upsell orders
                if ($tsaName && $isUpsell) $tsaCount[$tsaName] = ($tsaCount[$tsaName] ?? 0) + 1;
            }

            $this->line("  Page {$page} — {$totalSynced} orders found" . ($hitPrevDay ? ' (reached previous day, stopping)' : ''));
            $page++;

        } while (!$hitPrevDay && $page <= 500);

        Setting::set('last_synced', now()->toDateTimeString());

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
            'duration_ms'   => $runStart->diffInMilliseconds(now()),
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

        return ['name' => null, 'team' => null, 'matched_tag' => null];
    }

    private function inferTeamFromProduct(?string $productName): ?string
    {
        if (!$productName) return null;

        // Sourced from the products table (Product Management page) instead of
        // config/teams.php — see docs/superpowers/specs/2026-07-06-product-management-design.md.
        // $this->products is loaded once in handle() (same reasoning as $tsaMap/
        // $sellerMap) so this doesn't re-query on every single order.
        foreach ($this->products as $product) {
            if (stripos($productName, $product->effective_keyword) !== false) {
                return $product->team;
            }
        }

        return null;
    }

    /**
     * The moment the TSA actually worked this order, not when the lead/order record was
     * created. Pancake's `histories` log records every tag-list change with a timestamp;
     * this finds the first entry where $matchedTag newly appears, which is when the TSA's
     * own tag was added (typically well after $insertedAt for auto-created leads). Falls
     * back to $insertedAt when there's no tag to match, or the tag was already present at
     * creation (no history diff to find).
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
