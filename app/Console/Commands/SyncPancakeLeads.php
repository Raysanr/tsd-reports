<?php

namespace App\Console\Commands;

use App\Http\Controllers\CallTracker\LeadController;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadSyncRun;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Support\PancakeOrderTagApi;
use App\Support\RoundRobinAssigner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ported from call-tracker (merged into one app 2026-08-12). Pulls recent
 * Pancake orders that have no TSA claim on them yet and assigns each one to
 * the next TSA in its product's round-robin queue — a purely local
 * assignment, no tag written back to Pancake for it. Only a TSA's own
 * logged Outcome writes real tags to Pancake (see
 * CallTracker\LeadController::tagOutcomeInPancake()).
 *
 * An order already carrying ANY known TSA's tag is someone else's claim
 * (worked directly in Pancake, bypassing this tool) and is left alone —
 * this only ever touches genuinely unclaimed leads.
 */
class SyncPancakeLeads extends Command
{
    protected $signature = 'pancake:sync-leads {--hours=24 : How many past hours of orders to check}';
    protected $description = 'Pull unclaimed Pancake orders and round-robin assign each to a TSA';

    public function handle(): int
    {
        // Self-contained overlap lock (explicit request, 2026-09-12: "make
        // it like realtime... i want dont have to wait minutes") — this
        // command used to rely ENTIRELY on Schedule::withoutOverlapping(10)
        // in routes/console.php, a lock that only applies when invoked
        // through schedule:run. To get assignment faster than Laravel's own
        // scheduler (which cannot express anything below one-minute
        // resolution — there is no ->everySeconds()), CronController::run()
        // now also fires this command directly on every /cron/run hit,
        // independent of schedule:run's once-a-minute cadence, so the
        // external pinger's own interval becomes the real ceiling instead.
        // That bypasses the scheduler-level lock entirely, so without a
        // lock of its own here, a pinger interval shorter than one full
        // run's real duration (multi-page Pancake pagination, up to 100
        // pages) could launch two genuinely overlapping runs. Same
        // Setting-flag-plus-staleness-fallback pattern SyncCallRecordings
        // already uses for the identical problem (see that class's own
        // runningFlagIsStale()) — staleness matters here too: a container
        // killed mid-run never reaches the finally block, which would
        // otherwise leave this flag stuck at '1' forever.
        if (Setting::get('pancake_sync_leads_running') === '1' && !$this->runningFlagIsStale()) {
            $this->info('A sync is already running — skipping to avoid running two at once.');
            return self::SUCCESS;
        }
        Setting::set('pancake_sync_leads_running', '1');
        Setting::set('pancake_sync_leads_last_run', now()->toIso8601String());

        try {
            return $this->doSync();
        } finally {
            Setting::set('pancake_sync_leads_running', '');
        }
    }

    /** A real run has taken a few seconds to low tens of seconds in
     *  practice (single-shop Pancake pagination, not a heavy Drive
     *  download loop like SyncCallRecordings) — confirmed live, 2026-09-22:
     *  ~440-450 orders per 24h window resolves to exactly 5 pages every
     *  time (nowhere near the 100-page ceiling), and even in the
     *  pathological case where every one of those 5 pages takes the full
     *  10s per-page timeout (see the fetch loop's own doc comment) before
     *  the run finally gives up, that's ~50s worst case — 2 minutes is
     *  still generous headroom over that, just proportionate headroom now
     *  (SyncCallRecordings' own 20-minute threshold is ~2-4x ITS real
     *  worst case of 5-10 minutes; this was previously ~10-100x its own
     *  real worst case, not the same ratio).
     *
     *  Lowered from 5 minutes the same day (real incident: a deploy's
     *  container restart killed an in-flight detached background sync
     *  process — see handle()'s own doc comment on why this runs
     *  detached — mid-run, before it ever reached the finally block that
     *  clears this flag; since the flag lives in Postgres, not in-process
     *  memory, it survived the restart and blocked every sync attempt,
     *  including a manual "Sync Now" click, for the full 5 minutes until
     *  this exact staleness check finally cleared it). A deploy happens
     *  routinely, not as a rare edge case, so this flag getting orphaned
     *  is a normal, expected event this threshold needs to recover from
     *  quickly — not a scenario to pad generously against.
     *
     *  No timestamp at all can't be a genuinely in-progress run, so treat
     *  it as stale too rather than block forever on a flag with nothing
     *  to measure staleness against. */
    private function runningFlagIsStale(): bool
    {
        $lastRun = Setting::get('pancake_sync_leads_last_run');
        return !$lastRun || Carbon::parse($lastRun)->diffInMinutes(now()) > 2;
    }

    private function doSync(): int
    {
        $runStart = now();
        $apiKey   = Setting::get('pancake_api_key', env('PANCAKE_API_KEY', ''));
        $shopId   = Setting::get('shop_id', '');

        if (empty($apiKey) || empty($shopId)) {
            $this->error('API key or shop ID not configured. Go to Settings first.');
            $this->recordRun($runStart, 0, 0, 0, false, 'API key or shop ID not configured.');
            return self::FAILURE;
        }

        $tsaKeys  = TsaShift::pluck('tsa_key')->map(fn ($k) => strtoupper($k))->all();
        $products = Product::all();

        // Stamps/clears round_robin_states.roster_available_since for every
        // product on this tick, BEFORE any assignment below — see
        // RoundRobinAssigner::trackRosterAvailability()'s own doc comment.
        // Runs every minute (this command's own schedule), so a login is
        // reflected within a minute of it actually happening.
        foreach ($products as $product) {
            RoundRobinAssigner::trackRosterAvailability($product);
        }

        $hours = max(1, (int) $this->option('hours'));
        $from  = Carbon::now('Asia/Manila')->subHours($hours);
        $to    = Carbon::now('Asia/Manila');

        $url          = "https://pos.pages.fm/api/v1/shops/{$shopId}/orders";
        $page         = 1;
        $totalFetched = 0;
        $synced       = 0;
        $skipped      = 0;
        $errorMessage = null;

        while ($page <= 100) {
            // 10s, not 30s (root-caused 2026-09-22, live incident: Sync
            // Health showed "stale — last synced 4 minutes ago" — Pancake's
            // own /orders endpoint was responding at ~18 KB/s that day,
            // confirmed live via a direct timed request; a 30s timeout let
            // ONE slow page eat half the 1-minute scheduler gap, so a run
            // that hit even 2-3 slow pages in a row took 60-94+ seconds —
            // longer than the gap before the NEXT scheduled tick, which
            // then found $running still '1' and skipped, compounding into
            // the visible "stale" state). A page that times out here isn't
            // lost — this loop's own 24-hour lookback window means the NEXT
            // tick, one minute later, re-fetches the exact same page's
            // orders again; cutting a slow page off early trades "maybe 1
            // extra minute of delay for THAT page's orders" for "the whole
            // sync pipeline stops piling up behind itself." Wrapped in
            // try/catch (new — previously Http::timeout() throwing a
            // ConnectionException on an actual cURL timeout, as opposed to
            // a non-2xx response, propagated straight past this loop's own
            // `if (!$response->successful())` check, uncaught, all the way
            // out of doSync() — the outer handle()'s bare try{}finally{}
            // still reset $running correctly on that path, but recordRun()
            // never ran, so a timed-out tick left NO LeadSyncRun row at
            // all, silently invisible to Sync Health's own "last synced"
            // readout instead of showing up as a real failed run).
            try {
                $response = Http::withHeaders(['Accept' => 'application/json'])->timeout(10)->get($url, [
                    'api_key'       => $apiKey,
                    'page_size'     => 100,
                    'page_number'   => $page,
                    'updateStatus'  => 'inserted_at',
                    'startDateTime' => $from->timestamp,
                    'endDateTime'   => $to->timestamp,
                ]);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $errorMessage = "Connection error on page {$page}: " . $e->getMessage();
                $this->error($errorMessage);
                Log::error('pancake:sync-leads connection failed', ['page' => $page, 'message' => $e->getMessage()]);
                break;
            }

            if (!$response->successful()) {
                $errorMessage = "API error on page {$page}: HTTP " . $response->status() . ' — ' . $response->body();
                $this->error($errorMessage);
                Log::error('pancake:sync-leads failed', ['status' => $response->status()]);
                break;
            }

            $orders = $response->json()['data'] ?? [];
            if (empty($orders)) break;

            $totalFetched += count($orders);

            foreach ($orders as $raw) {
                $id = (string) ($raw['id'] ?? '');
                if ($id === '') continue;

                // Already pulled in before (assigned or otherwise) — never
                // re-process into a NEW lead, this command is not a re-sync.
                // Its EXISTING row's disposition/callback_at can still catch
                // up to a real Pancake tag added since it was pulled in
                // though — see backfillCallbackFromTags()'s own comment.
                $existing = Lead::where('pancake_order_id', $id)->first();
                if ($existing) {
                    $this->backfillCallbackFromTags($existing, $raw);
                    $this->backfillUnansweredDispositionFromTags($existing, $raw);
                    $skipped++;
                    continue;
                }

                $tagNames = collect($raw['tags'] ?? [])->pluck('name')->filter()->map(fn ($t) => strtoupper($t));

                // Someone (a TSA, an admin) already claimed this in Pancake
                // directly — not this tool's lead to hand out.
                if ($tagNames->contains(fn ($t) => in_array($t, $tsaKeys, true))) {
                    $skipped++;
                    continue;
                }

                // A warehouse/logistics duplicate of an already-real order —
                // explicit report, 2026-09-19 ("is it possible that can be
                // not distribute the leads that is duplicated from
                // logistics? like from AJ DELA CRUZ and RALPH CRUZ" — two
                // separate real orders, each already carrying Pancake's own
                // "DUPLICATED BY LOGISTICS" note, both still got created as
                // Leads and round-robin assigned to a TSA to call). This is
                // the exact same live-note check Order::isDuplicatedByLogistics()
                // already applies at Order-sync time (SyncTodayOrders) and
                // at reporting time (BackfillDuplicatedByLogistics) — this
                // command just never applied it before creating/distributing
                // a Lead, since it's a separate sync pipeline over the same
                // raw Pancake order shape. Skipped, not silently dropped —
                // still counted so a run's own "skipped" total accounts for
                // it, same as an already-claimed order above.
                if (Order::isDuplicatedByLogistics($raw)) {
                    $skipped++;
                    continue;
                }

                $itemName = $raw['items'][0]['variation_info']['name'] ?? $raw['items'][0]['product_name'] ?? null;
                $product  = $products->first(fn (Product $p) => $p->matchesText($itemName) || $tagNames->contains(fn ($t) => $p->matchesText($t)));

                $lead = new Lead([
                    'pancake_order_id'   => $id,
                    // bill_full_name/bill_phone_number are the real top-level
                    // fields on a Pancake order; customer.phone_numbers is a
                    // plural array fallback.
                    'customer_name'      => $raw['bill_full_name'] ?? $raw['customer']['name'] ?? null,
                    'phone_number'       => $raw['bill_phone_number'] ?? ($raw['customer']['phone_numbers'][0] ?? null),
                    'conversation_link'  => $raw['customer']['conversation_link'] ?? null,
                    'pancake_page_id'         => isset($raw['page_id']) ? (string) $raw['page_id'] : null,
                    'pancake_conversation_id' => $raw['conversation_id'] ?? null,
                    'product_id'         => $product?->id,
                    // Bug fix, 2026-09-18 ("why is it like no leads
                    // distributed in the leads page?", reported ~7:41 AM
                    // Manila) — Pancake stores inserted_at as UTC with NO
                    // offset marker (same real behavior SyncTodayOrders::
                    // flushOrders() already documents as "Fix 1: Pancake
                    // stores UTC without TZ marker — parse as UTC, convert
                    // to Manila"), but this line only ever did the first
                    // half: Carbon::parse(..., 'UTC') correctly READS it as
                    // UTC, but never converted it to Manila time before
                    // saving, so the raw UTC clock-time got stored as-is.
                    // Every Manila morning before 8:00 AM, a genuinely-today
                    // order's pancake_created_at was still stamped with
                    // YESTERDAY's date (UTC is 8 hours behind Manila) —
                    // invisible to every view that filters pancake_created_at
                    // to "today" (Leads, Overdue, Callbacks, the sidebar
                    // badge, Monitor, Dashboard, Analytics). Confirmed live
                    // against production: the SAME order's Order.
                    // pancake_created_at (SyncTodayOrders' own, already-
                    // correct conversion) read 07:41:56 while this
                    // command's own Lead.pancake_created_at read 23:41:56
                    // the PREVIOUS day for the identical real timestamp.
                    'pancake_created_at' => isset($raw['inserted_at'])
                        ? Carbon::parse($raw['inserted_at'], 'UTC')->setTimezone('Asia/Manila')
                        : null,
                    'synced_at'          => now(),
                ]);

                // Likely-duplicate check (explicit request, 2026-08-26) — see
                // findLikelyDuplicateLead()'s own doc comment for why. Only
                // matters when there's an existing lead to route to AND that
                // lead already has a TSA — an unassigned "original" has
                // nothing to inherit, so this one just falls through to a
                // normal round-robin pick same as before.
                $duplicateOf = ($product && $lead->phone_number && $lead->pancake_created_at)
                    ? $this->findLikelyDuplicateLead($lead->phone_number, $product->id, $lead->pancake_created_at)
                    : null;

                if ($duplicateOf && $duplicateOf->tsa_id) {
                    $lead->tsa_id      = $duplicateOf->tsa_id;
                    $lead->assigned_at = now();
                    $lead->status      = 'assigned';
                } elseif ($product) {
                    $tsa = RoundRobinAssigner::next($product);
                    if ($tsa) {
                        $lead->tsa_id      = $tsa->id;
                        $lead->assigned_at = now();
                        $lead->status      = 'assigned';
                    } else {
                        // Product exists but has no active TSA roster — a real
                        // config gap, surfaced (not silently skipped) so an
                        // admin notices and fixes product_tsa.
                        $lead->status = 'unassigned';
                    }
                } else {
                    $lead->status = 'unassigned';
                }

                $lead->save();
                $synced++;

                LeadActivity::log($lead, 'created', "Lead pulled in from Pancake order #{$id}.");

                if ($duplicateOf && $duplicateOf->tsa_id) {
                    LeadActivity::log(
                        $lead, 'assigned',
                        "Likely duplicate of order #{$duplicateOf->pancake_order_id} (same phone, product, and day) — "
                            . "auto-routed to {$lead->tsa->display_name} instead of a fresh round-robin pick."
                    );
                } elseif ($lead->tsa) {
                    LeadActivity::log($lead, 'assigned', "Round-robin assigned to {$lead->tsa->display_name}.");
                }

                // Tag the new owner's own POS name onto the real order right
                // away (explicit follow-up request, 2026-09-03: "when there's
                // new leads it is auto tagging ... because it is their
                // leads") — previously this was local-only until the TSA
                // logged a call outcome (see LeadController::
                // tagTsaOnPancakeOrder()'s own doc comment).
                if ($lead->tsa_id) {
                    LeadController::tagTsaOnPancakeOrder($lead, app(PancakeOrderTagApi::class));
                }
            }

            if (count($orders) < 100) break;
            $page++;
        }

        $this->recordRun($runStart, $totalFetched, $synced, $skipped, $errorMessage === null, $errorMessage);

        if ($errorMessage !== null) {
            return self::FAILURE;
        }

        $this->info("Synced {$synced} new lead(s), skipped {$skipped} (already claimed or already pulled in).");

        $caughtUp = $this->catchUpUnassignedLeads();
        if ($caughtUp > 0) {
            $this->info("Caught up {$caughtUp} previously-unassigned lead(s) now that a TSA is available.");
        }

        return self::SUCCESS;
    }

    /**
     * Surfaces a lead on the Callbacks tab when Pancake ITSELF already
     * carries a "Call Back" tag on the order — not just when a TSA logs
     * that outcome through this app's own Log Outcome flow. Originally
     * (explicit request, 2026-09-08: "all of the leads in the pos that has
     * unattended and not answering tag is should be display in the
     * callbacks page") this matched Not Answering/Unattended instead — see
     * LeadController::CALLBACK_TRIGGER_KEYWORDS' own doc comment for the
     * 2026-09-22 reversal: those two (plus Invalid Number) now get their
     * own dedicated Unanswered Calls page, so this method's job is now
     * "Call Back" ONLY, matching CALLBACK_TRIGGER_KEYWORDS' current value —
     * same single-source-of-truth list, just a different set of keywords
     * in it than when this comment was first written. Before this method
     * existed at all, a lead someone tagged directly in Pancake (or that
     * arrived already tagged, e.g. from an earlier sync this app never
     * ran) sat with a blank disposition and no callback_at forever —
     * updateDisposition()'s own auto-callback logic only ever runs when a
     * TSA submits the Log Outcome form here.
     *
     * See backfillUnansweredDispositionFromTags() just below for this
     * method's sibling — the 2026-09-22 reversal above initially left NO
     * mechanism backfilling disposition for Not Answering/Unattended/
     * Invalid Number at all (a real gap, caught live the same day: "why
     * the unanswered is in tsd report leads is this much but it is only
     * displaying in unanswered calls is only 1"), which that method now
     * covers independently — same "re-check every sync tick" pattern,
     * disposition only, never touching callback_at.
     *
     * Runs every minute (this command's own schedule, see routes/
     * console.php) over EVERY already-synced lead this run's date window
     * touches, not just brand-new ones — so a tag added in Pancake well
     * after the lead first arrived here still gets picked up on the very
     * next sync tick, not just at creation time.
     *
     * Deliberately does NOT touch a lead already status='called' — that
     * means a TSA has already logged a real, human outcome for it through
     * this app (see updateDisposition()), which is a more informed record
     * than a bare Pancake tag and must never be silently overwritten OR
     * cleared by it. This is also exactly the signal used below to tell a
     * backfill-set callback apart from a TSA's own: updateDisposition()
     * always sets status='called' in the SAME write as callback_at, so a
     * callback still sitting on a NON-'called' lead can only ever have come
     * from this method — safe to auto-clear, never a human's own choice.
     *
     * callback_at = now(), NOT now()->addDay() (root-caused 2026-09-08, real
     * production check the same day: 99 leads backfilled with +1 day showed
     * ZERO of them on the Callbacks page, since that view only ever shows
     * callback_at <= now() — "due now or already past due", see
     * LeadController::index()'s own comment). now()->addDay() is the right
     * default for a TSA who genuinely just hasn't decided WHEN to call back
     * yet (updateDisposition()'s own fallback, a real but different case) —
     * a lead Pancake itself already flagged Call Back needs calling today,
     * not tomorrow, which is the entire point of this fix.
     *
     * Auto-CLEARS a backfill-set callback too (root-caused 2026-09-08, same
     * day: order #1365830 was tagged "Not Answering" — correctly triggered
     * a callback under the keyword set at the time — then someone called
     * it directly in Pancake and it's now tagged "Confirmed Via Call"
     * instead; the lead sat stuck showing as a
     * due callback forever, since nothing ever re-checked it once the real
     * tag moved on). Every sync tick, a lead whose callback_at is still set
     * from THIS method (status !== 'called', see above) gets re-checked
     * against its CURRENT real tags — if none of them match a trigger
     * keyword anymore, the callback is cleared, same as it would be if a
     * TSA had logged a real Outcome on it.
     */
    private function backfillCallbackFromTags(Lead $lead, array $raw): void
    {
        if ($lead->status === 'called') {
            return;
        }

        $tagNames = collect($raw['tags'] ?? [])->pluck('name')->filter();
        $matchedTag = $tagNames->first(
            fn ($tag) => collect(LeadController::CALLBACK_TRIGGER_KEYWORDS)->contains(fn ($kw) => stripos($tag, $kw) !== false)
        );

        if ($lead->callback_at !== null) {
            // Already has a (backfill-set, per the status check above)
            // callback — only act if the real tags no longer justify it.
            if ($matchedTag === null) {
                $lead->update(['disposition' => null, 'callback_at' => null]);
                LeadActivity::log($lead, 'callback_scheduled', 'Callback cleared — Pancake tags no longer include Not Answering/Unattended.');
            }
            return;
        }

        if ($matchedTag === null) {
            return;
        }

        $lead->update([
            'disposition' => $matchedTag,
            'callback_at' => now(),
        ]);

        LeadActivity::log($lead, 'callback_scheduled', 'Callback due now (Pancake tag "' . $matchedTag . '", not a logged Outcome).');
    }

    /**
     * Sibling of backfillCallbackFromTags() above, added 2026-09-22 — real
     * production gap found the same day the Unanswered Calls page shipped:
     * "why the unanswered is in tsd report leads is this much but it is
     * only displaying in unanswered calls is only 1". Root cause: before
     * this method existed, NOTHING ever copied a Not Answering/Unattended/
     * Invalid Number Pancake tag into Lead::disposition unless a TSA
     * manually logged that exact outcome through this app's own Log
     * Outcome flow — backfillCallbackFromTags() used to do it as a side
     * effect of scheduling a callback, back when those 3 keywords were
     * still part of CALLBACK_TRIGGER_KEYWORDS, but the 2026-09-22 reversal
     * that narrowed that constant to "Call Back" only silently removed the
     * ONLY thing that ever wrote these 3 tags into disposition at all.
     * Confirmed live: 65 of 66 leads tagged one of these 3 in Pancake
     * today had disposition still null, while the real TSD Report (which
     * reads Order::raw_tags directly, not Lead::disposition) correctly
     * showed the true count — the two pages were reading two different,
     * silently-diverged sources of truth for the same real-world tag.
     *
     * Deliberately does NOT touch callback_at at all — that's
     * backfillCallbackFromTags()'s own job now, and Unanswered Calls
     * doesn't depend on callback_at (see LeadController::index()'s own
     * 'unanswered' branch doc comment). Only ever fills in disposition,
     * never overwrites one that's already set — a lead a TSA already
     * logged an outcome for (status='called') or that already picked up
     * ANY disposition (including from backfillCallbackFromTags() itself,
     * e.g. "Call Back") keeps that value; this only fills in a genuinely
     * blank one.
     *
     * Auto-clears too, same "re-checked every sync tick" convention as
     * backfillCallbackFromTags() — a lead this method backfilled
     * (still status !== 'called', still carrying the disposition THIS
     * method set) whose current real tags no longer include any of these
     * 3 keywords gets its disposition cleared back to null, so a
     * resolved-in-Pancake lead doesn't sit stuck on the Unanswered Calls
     * page forever. Never touches a disposition a TSA logged herself or
     * that backfillCallbackFromTags() itself set (e.g. "Call Back") —
     * status='called' or a disposition outside these 3 keywords both mean
     * "not this method's to manage", same ownership boundary
     * backfillCallbackFromTags() already draws for callback_at.
     */
    private function backfillUnansweredDispositionFromTags(Lead $lead, array $raw): void
    {
        if ($lead->status === 'called') {
            return;
        }

        $tagNames = collect($raw['tags'] ?? [])->pluck('name')->filter();
        $matchedTag = $tagNames->first(
            fn ($tag) => collect(LeadController::UNANSWERED_CALLS_TRIGGER_KEYWORDS)->contains(fn ($kw) => stripos($tag, $kw) !== false)
        );

        $dispositionIsOwnedByThisMethod = $lead->disposition !== null
            && collect(LeadController::UNANSWERED_CALLS_TRIGGER_KEYWORDS)->contains(fn ($kw) => stripos($lead->disposition, $kw) !== false);

        if ($dispositionIsOwnedByThisMethod) {
            if ($matchedTag === null) {
                $lead->update(['disposition' => null]);
                LeadActivity::log($lead, 'callback_scheduled', 'Unanswered Calls tag cleared — Pancake tags no longer include Not Answering/Unattended/Invalid Number.');
            }
            return;
        }

        if ($lead->disposition !== null || $matchedTag === null) {
            return;
        }

        $lead->update(['disposition' => $matchedTag]);

        LeadActivity::log($lead, 'callback_scheduled', 'Tagged for Unanswered Calls (Pancake tag "' . $matchedTag . '", not a logged Outcome).');
    }

    /**
     * A likely duplicate of the order about to become a brand-new,
     * independently round-robin-assigned lead — explicit request,
     * 2026-08-26: Pancake sometimes creates two separate orders for what's
     * really the same customer inquiry (confirmed live: orders #1357483 and
     * #1357480, same phone number, same SINUXYL product, both landing on
     * Gemma De Guzman purely by round-robin coincidence, not because they
     * were meant to go together). Today nothing catches this until a TSA
     * notices and manually tags the call "DFR" (Duplicate) after the fact —
     * by which point a round-robin slot, and possibly a second TSA's time,
     * is already spent.
     *
     * Matched on last-9-digits phone number (same "different formatting,
     * same real PH mobile number" reasoning CallTracker\LeadController::
     * matchingRecordingFiles() already uses) + same matched product + same
     * calendar day in Asia/Manila. Filters candidates by product/day in the
     * query first (normally a small set), then compares phone numbers in
     * PHP rather than a DB-specific regex function — this app runs on
     * different SQL drivers locally vs. in production, so a raw SQL regex
     * expression risks silently behaving differently (or erroring outright)
     * between them.
     */
    private function findLikelyDuplicateLead(string $phoneNumber, int $productId, Carbon $createdAt): ?Lead
    {
        $digits = preg_replace('/[^0-9]/', '', $phoneNumber);
        $last9  = substr($digits, -9);
        if (strlen($last9) < 9) {
            return null;
        }

        $dayStart = $createdAt->copy()->timezone('Asia/Manila')->startOfDay();
        $dayEnd   = $createdAt->copy()->timezone('Asia/Manila')->endOfDay();

        return Lead::where('product_id', $productId)
            ->whereBetween('pancake_created_at', [$dayStart, $dayEnd])
            ->whereNotNull('phone_number')
            ->get()
            ->first(fn (Lead $existing) => substr(preg_replace('/[^0-9]/', '', $existing->phone_number), -9) === $last9);
    }

    /**
     * Catch-up pass (explicit request, 2026-08-21): a lead that lands as
     * Unassigned because nobody was logged in yet at the time it arrived
     * used to sit that way FOREVER — the loop above only ever looks at each
     * Pancake order once, the moment it first sees it (line ~88's exists()
     * check), so a TSA logging in later never triggered a retry for
     * anything already pulled in. This re-attempts round-robin for every
     * still-unassigned local lead with a matched product, oldest first,
     * every run — the moment ANY TSA logs in for that product, whatever
     * piled up overnight gets swept up and FAIRLY DIVIDED across however
     * many TSAs are now eligible, via the exact same per-product
     * RoundRobinAssigner::next() rotation a brand-new lead already uses:
     * each call advances that product's own round_robin_states pointer, so
     * e.g. 6 backlog leads with 2 TSAs now online split 3/3 via the normal
     * rotation, not all landing on whichever TSA happened to log in first
     * (explicit follow-up, 2026-09-14: "i want in all tsa when they are
     * login and has same product it should be like equally divided of
     * leads" — confirmed this is exactly what the rotation already does;
     * BATCH_LIMIT below only bounds how MANY leads get processed per run,
     * never changes the turn-by-turn fairness of who receives each one).
     *
     * A lead with no matched product (product_id null) is skipped — there's
     * no rotation to retry it against; that's a separate, pre-existing gap
     * (see the main loop's own "$product ? ... : 'unassigned'" branch),
     * unrelated to the login-timing problem this catch-up fixes.
     *
     * Bounded to BATCH_LIMIT oldest leads per run (regression fix,
     * 2026-09-14: "why mariel is currently online today but no leads" —
     * root-caused: this method used to process the ENTIRE unassigned
     * backlog every run with no limit; after the sub-minute leads loop
     * (2026-09-12) started running it every ~15s, and a mass-dump
     * incident (2026-09-14, see RoundRobinAssigner's own comment) grew the
     * backlog to 6,940 unassigned leads, a single run's real cost —
     * dominated by tagTsaOnPancakeOrder()'s live Pancake API call per
     * SUCCESSFUL assignment, not the cheap DB filtering of the rest —
     * started taking minutes. Confirmed live via leads-loop.log: every
     * tick after the first showed "A sync is already running — skipping,"
     * for 3+ minutes straight, so Mariel (correctly eligible, confirmed
     * directly via RoundRobinAssigner::next()) simply hadn't been reached
     * by the one giant slow-running sweep yet. Processing oldest-first in
     * bounded batches means each run finishes well within the loop's own
     * interval, and the backlog clears gradually over many ticks instead
     * of blocking every product's assignment behind one massive run.
     */
    private const CATCH_UP_BATCH_LIMIT = 200;

    private function catchUpUnassignedLeads(): int
    {
        $leads = Lead::where('status', 'unassigned')
            ->whereNotNull('product_id')
            ->with('product')
            ->orderBy('pancake_created_at')
            ->limit(self::CATCH_UP_BATCH_LIMIT)
            ->get();

        $caughtUp = 0;
        foreach ($leads as $lead) {
            if (!$lead->product) continue; // product deleted since — nothing to rotate against

            // Handover buffer (explicit request, 2026-09-17) — hold off on
            // THIS product's backlog until its roster has been continuously
            // non-empty for RoundRobinAssigner::GAP_BUFFER_MINUTES, so the
            // rest of a closing/opening team has a few minutes to log in
            // too instead of the whole backlog landing on whoever logged in
            // first. Checked per-lead (not once per product up front) since
            // $leads spans every product in one query — a product past its
            // buffer shouldn't wait on a different product still inside it.
            if (!RoundRobinAssigner::isPastHandoverBuffer($lead->product)) continue;

            $tsa = RoundRobinAssigner::next($lead->product);
            if (!$tsa) continue; // still nobody eligible for this product — try again next run

            // assigned_at = now(), not backdated to when the order actually
            // came in — same reasoning LeadController::transfer() already
            // uses: the TSA is only just now receiving this lead, so their
            // own overdue-threshold clock (and today's TSA
            // Performance/Dashboard attribution, both anchored on
            // assigned_at) should start from THIS moment, not however many
            // hours it sat unassigned overnight.
            $lead->update([
                'tsa_id'      => $tsa->id,
                'assigned_at' => now(),
                'status'      => 'assigned',
            ]);
            LeadActivity::log(
                $lead,
                'assigned',
                "Round-robin assigned to {$tsa->display_name} (was unassigned since "
                    . ($lead->pancake_created_at?->format('M j, g:i A') ?? 'an earlier sync') . ').'
            );

            // Same immediate POS name tag as a brand-new lead gets above —
            // see LeadController::tagTsaOnPancakeOrder()'s own doc comment.
            LeadController::tagTsaOnPancakeOrder($lead, app(PancakeOrderTagApi::class));

            $caughtUp++;
        }

        return $caughtUp;
    }

    private function recordRun(Carbon $runStart, int $totalFetched, int $newLeads, int $skipped, bool $success, ?string $errorMessage): void
    {
        LeadSyncRun::create([
            'ran_at'         => $runStart,
            'total_fetched'  => $totalFetched,
            'new_leads'      => $newLeads,
            'skipped'        => $skipped,
            'duration_ms'    => (int) round($runStart->diffInMilliseconds(now())),
            'success'        => $success,
            'error_message'  => $errorMessage,
        ]);
    }
}
