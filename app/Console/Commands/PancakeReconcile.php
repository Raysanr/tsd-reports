<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Setting;
use App\Models\TsaShift;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PancakeReconcile extends Command
{
    protected $signature   = 'pancake:reconcile';
    protected $description = "Cross-check yesterday's synced order count and configured TSA tag keywords against Pancake's own data";

    // Below this fraction of Pancake's reported order count for the day, the day is
    // flagged as incomplete. Not 100%: a small number of orders touched right at the
    // day boundary may not have synced yet by the time this check runs (delta syncs
    // run every 1-15 minutes, not instantly) — expected, not a sync gap. A large
    // shortfall is not expected.
    private const COMPLETENESS_THRESHOLD = 0.9;

    public function handle(): int
    {
        $apiKey = Setting::get('pancake_api_key', env('PANCAKE_API_KEY', ''));
        $shopId = Setting::get('shop_id', '');

        if (empty($apiKey) || empty($shopId)) {
            $this->error('API key or shop ID not configured.');
            return self::FAILURE;
        }

        $issues = array_merge(
            $this->checkCompleteness($apiKey, $shopId),
            $this->checkTagDrift($apiKey, $shopId),
        );

        foreach ($issues as $issue) {
            Log::warning('pancake:reconcile: ' . $issue);
        }

        Setting::set('reconciliation_last_run', now()->toIso8601String());
        Setting::set('reconciliation_issues', json_encode($issues));

        if (empty($issues)) {
            $this->info('No issues found.');
        } else {
            $this->warn(count($issues) . ' issue(s) found:');
            foreach ($issues as $issue) {
                $this->line('  - ' . $issue);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Compares Pancake's own order count for yesterday (Asia/Manila) against how
     * many local rows have a pancake_updated_at in that same window — pancake_updated_at
     * stores Pancake's raw update timestamp untouched (see the orders migration's
     * comment), so this is a genuine apples-to-apples comparison against Pancake's
     * updateStatus=updated_at count. Deliberately does NOT use pancake_created_at,
     * which is business-adjusted to "when a TSA actually worked this" (see
     * SyncTodayOrders::resolveWorkedAt()) and can land on a different calendar day
     * than the same order's raw updated_at — comparing against that field was this
     * check's original bug (confirmed empirically: for 2026-07-13, Pancake reported
     * 494 orders touched by updated_at, but only 366 local rows had a matching
     * pancake_created_at — pancake_created_at tracked close to Pancake's own
     * inserted_at count of 364 instead, an entirely different, unrelated number).
     */
    private function checkCompleteness(string $apiKey, string $shopId): array
    {
        $date         = Carbon::now('Asia/Manila')->subDay();
        $startOfDayTs = $date->copy()->startOfDay()->timestamp;
        $endOfDayTs   = $date->copy()->endOfDay()->timestamp;

        $response = Http::withHeaders(['Accept' => 'application/json'])->timeout(30)->get(
            "https://pos.pages.fm/api/v1/shops/{$shopId}/orders",
            [
                'api_key'       => $apiKey,
                'page_size'     => 1,
                'page_number'   => 1,
                'updateStatus'  => 'updated_at',
                'startDateTime' => $startOfDayTs,
                'endDateTime'   => $endOfDayTs,
            ]
        );

        if (!$response->successful()) {
            return ["Completeness check for {$date->toDateString()} failed: API error " . $response->status()];
        }

        $pancakeCount = (int) ($response->json()['total_entries'] ?? 0);
        if ($pancakeCount === 0) {
            return [];
        }

        $localCount = Order::whereBetween('pancake_updated_at', [
            $date->copy()->startOfDay(), $date->copy()->endOfDay(),
        ])->count();

        if ($localCount < $pancakeCount * self::COMPLETENESS_THRESHOLD) {
            return ["Completeness: Pancake reports {$pancakeCount} orders touched on {$date->toDateString()}, but only {$localCount} are synced locally — sync may have missed a window that day."];
        }

        return [];
    }

    /**
     * For every TSA, checks that AT LEAST ONE of their tag keywords appears in a
     * real tag name from Pancake's own tag catalog — not every keyword individually.
     * tag_keywords always auto-includes the TSA's literal first name (see
     * TsaManagementController::buildTagKeywords) alongside whatever "also matches"
     * extras were configured, and that auto-name is never guaranteed to be a real
     * Pancake tag itself (e.g. Kathleen's real tag is the abbreviation "KATH", not
     * "KATHLEEN" — she covers this with an extra keyword, exactly as intended).
     * Checking every keyword independently flagged that permanently, every single
     * run, for no reason — the real failure mode worth catching is a TSA with ZERO
     * working keywords: no way at all to attribute their orders, which is either a
     * typo or a tag renamed/removed on the Pancake side.
     */
    private function checkTagDrift(string $apiKey, string $shopId): array
    {
        $response = Http::withHeaders(['Accept' => 'application/json'])->timeout(30)->get(
            "https://pos.pages.fm/api/v1/shops/{$shopId}/orders/tags",
            ['api_key' => $apiKey]
        );

        if (!$response->successful()) {
            return ['Tag-drift check failed: API error ' . $response->status()];
        }

        $realTagNames = array_map(
            fn($t) => self::normalize($t['name'] ?? ''),
            $response->json()['data'] ?? []
        );

        $issues = [];

        foreach (TsaShift::all() as $shift) {
            $keywords = array_values(array_filter(
                array_map(fn($k) => self::normalize($k), $shift->tag_keywords_array)
            ));
            if (empty($keywords)) {
                continue;
            }

            $anyMatch = false;
            foreach ($keywords as $normalizedKeyword) {
                foreach ($realTagNames as $tagName) {
                    if (str_contains($tagName, $normalizedKeyword)) {
                        $anyMatch = true;
                        break 2;
                    }
                }
            }

            if (!$anyMatch) {
                $configured = implode(', ', $shift->tag_keywords_array);
                $issues[] = "Tag drift: TSA \"{$shift->tsa_key}\" has no tag keyword matching any tag currently in Pancake (configured: {$configured}) — check TSA Management for a typo or a renamed tag.";
            }
        }

        return $issues;
    }

    private static function normalize(string $s): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s));
    }
}
