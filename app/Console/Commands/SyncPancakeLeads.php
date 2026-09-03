<?php

namespace App\Console\Commands;

use App\Http\Controllers\CallTracker\LeadController;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadSyncRun;
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
            $response = Http::withHeaders(['Accept' => 'application/json'])->timeout(30)->get($url, [
                'api_key'       => $apiKey,
                'page_size'     => 100,
                'page_number'   => $page,
                'updateStatus'  => 'inserted_at',
                'startDateTime' => $from->timestamp,
                'endDateTime'   => $to->timestamp,
            ]);

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
                // re-process, this command is not a re-sync.
                if (Lead::where('pancake_order_id', $id)->exists()) {
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
                    'pancake_created_at' => isset($raw['inserted_at']) ? Carbon::parse($raw['inserted_at'], 'UTC') : null,
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
     * every run (same 1-minute cadence as the rest of this command) — the
     * moment ANY TSA logs in for that product, whatever piled up overnight
     * gets swept up and FAIRLY DIVIDED across however many TSAs are now
     * eligible, via the exact same per-product RoundRobinAssigner::next()
     * rotation a brand-new lead already uses: each call advances that
     * product's own round_robin_states pointer, so e.g. 6 backlog leads
     * with 2 TSAs now online split 3/3 via the normal rotation, not all
     * landing on whichever TSA happened to log in first.
     *
     * A lead with no matched product (product_id null) is skipped — there's
     * no rotation to retry it against; that's a separate, pre-existing gap
     * (see the main loop's own "$product ? ... : 'unassigned'" branch),
     * unrelated to the login-timing problem this catch-up fixes.
     */
    private function catchUpUnassignedLeads(): int
    {
        $leads = Lead::where('status', 'unassigned')
            ->whereNotNull('product_id')
            ->with('product')
            ->orderBy('pancake_created_at')
            ->get();

        $caughtUp = 0;
        foreach ($leads as $lead) {
            if (!$lead->product) continue; // product deleted since — nothing to rotate against

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
            'duration_ms'    => $runStart->diffInMilliseconds(now()),
            'success'        => $success,
            'error_message'  => $errorMessage,
        ]);
    }
}
