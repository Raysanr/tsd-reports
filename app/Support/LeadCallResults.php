<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\LeadCallResult;
use App\Models\TsaShift;
use Illuminate\Support\Carbon;

/**
 * Exact call-result record per lead (2026-11-13), for FollowUpAnalytics.
 *
 * Pancake's order `histories` log every tag change with its time and
 * editor, so each result tag added (Not answering, Confirmed via Call, …)
 * becomes one lead_call_results row: exact time, credited to the TSA who
 * added it (editor_id → TsaShift::pos_user_id), else the lead's TSA at
 * the time it was recorded. Unlike the sync's LeadActivity notes this
 * doesn't depend on the lead's status/disposition, isn't delayed by the
 * sync, and keeps tags added and removed between two sync ticks.
 *
 * Kept current by SyncPancakeLeads (refresh() when a lead's result tags
 * change) and filled for past leads by `leads:backfill-call-results`.
 */
class LeadCallResults
{
    /** Every call result we recognise (results vs. product/other tags). */
    public const RESULTS = ['not answering', 'unattended', 'invalid number', 'dfr', 'double order', 'fsd',
        'confirmed via call', 'upsell', 'call back', 'call dropped', 'repeat order', 'rude customer', 'relatives'];

    public static function isResult(string $tag): bool
    {
        return self::matches($tag, self::RESULTS);
    }

    public static function matches(string $result, array $keywords): bool
    {
        $result = str_replace("'", '', $result);
        foreach ($keywords as $keyword) {
            if (stripos($result, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Stable fingerprint of an order's current result tags. */
    public static function signature(array $tagNames): string
    {
        $results = collect($tagNames)->map(fn ($t) => strtoupper(trim((string) $t)))
            ->filter(fn ($t) => $t !== '' && self::isResult($t))->unique()->sort()->values()->all();

        return substr(implode('|', $results), 0, 255);
    }

    /**
     * Called by the lead sync for every existing lead. Re-reads Pancake's
     * history only when the lead's result tags changed since last time.
     * Returns true when it made a Pancake call (callers cap these per tick).
     */
    public static function refreshIfChanged(Lead $lead, array $raw, PancakeOrderTagApi $api): bool
    {
        $signature = self::signature(collect($raw['tags'] ?? [])->pluck('name')->filter()->all());
        if ($lead->result_tags_signature === $signature) {
            return false;
        }
        if ($lead->result_tags_signature === null && $signature === '') {
            $lead->forceFill(['result_tags_signature' => ''])->saveQuietly();
            return false;
        }

        $histories = $api->getOrderHistories($lead->pancake_order_id);
        if ($histories === null) {
            return true; // Pancake unreachable — retry next tick.
        }

        self::recordFromHistories($lead, $histories);
        $lead->forceFill(['result_tags_signature' => $signature])->saveQuietly();

        return true;
    }

    /** Insert a row for every result tag ever added in this order's history. */
    public static function recordFromHistories(Lead $lead, array $histories): int
    {
        $tsaByPosUser = TsaShift::withTrashed()->whereNotNull('pos_user_id')->pluck('id', 'pos_user_id');
        $added = 0;

        $entries = collect($histories)
            ->filter(fn ($e) => isset($e['tags']['new'], $e['updated_at']))
            ->sortBy('updated_at');

        foreach ($entries as $entry) {
            $old = self::names($entry['tags']['old'] ?? []);
            foreach (array_diff(self::names($entry['tags']['new']), $old) as $tag) {
                if (!self::isResult($tag)) {
                    continue;
                }
                $editor = $entry['editor_id'] ?? null;
                $row = LeadCallResult::firstOrCreate(
                    [
                        'lead_id'     => $lead->id,
                        'result'      => $tag,
                        'occurred_at' => Carbon::parse($entry['updated_at'], 'UTC')->setTimezone(config('app.timezone')),
                    ],
                    ['tsa_id' => ($editor ? $tsaByPosUser[$editor] ?? null : null) ?? $lead->tsa_id],
                );
                $added += $row->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $added;
    }

    private static function names(array $tags): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($t) => strtoupper(trim(\is_array($t) ? ($t['name'] ?? '') : (string) $t)),
            $tags
        ))));
    }
}
