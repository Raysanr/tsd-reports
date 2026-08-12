<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadSyncRun;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
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

                if ($product) {
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

                if ($lead->tsa) {
                    LeadActivity::log($lead, 'assigned', "Round-robin assigned to {$lead->tsa->display_name}.");
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
        return self::SUCCESS;
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
