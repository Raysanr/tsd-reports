<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Smart distribution (explicit request, 2026-11-25). One global switch in
 * TSA Management (Setting smart_distribution_enabled, off by default).
 *
 * While on:
 *  - Every TSA handles every product — the per-TSA product checkboxes
 *    (product_tsa) are ignored for who gets which lead, and kept untouched
 *    so turning this off restores them exactly.
 *  - Auto-call's waiting queue (AutoCall::next()) is ordered so the product
 *    with the most waiting leads goes first, oldest lead within it — except
 *    any lead that has waited STALE_MINUTES or more jumps the line, so small
 *    products never starve.
 *
 * Off: everything behaves exactly as before (product_tsa rosters,
 * oldest-lead-first).
 */
class SmartDistribution
{
    public const SETTING_KEY = 'smart_distribution_enabled';

    /** A waiting lead this old goes first regardless of product backlog. */
    public const STALE_MINUTES = 10;

    public static function enabled(): bool
    {
        return (bool) Setting::get(self::SETTING_KEY, false);
    }

    /** Products $tsa can receive leads for. */
    public static function productIdsFor(TsaShift $tsa): Collection
    {
        return self::enabled()
            ? Product::pluck('id')
            : $tsa->products()->pluck('products.id');
    }

    /** Base query for a product's roster (before status/active filters). */
    public static function rosterQuery(Product $product)
    {
        return self::enabled()
            ? TsaShift::query()->orderBy('id')
            : $product->tsas();
    }

    /**
     * Applies the smart order to a waiting-leads query (status 'unassigned'
     * already filtered by the caller). $waitingCounts is product_id => count
     * of leads waiting. Order: stale leads first (oldest first), then the
     * biggest-backlog product, then oldest.
     */
    public static function orderWaiting($query, Collection $waitingCounts)
    {
        $staleBefore = Carbon::now()->subMinutes(self::STALE_MINUTES)->toDateTimeString();
        $query->orderByRaw('CASE WHEN pancake_created_at <= ? THEN 0 ELSE 1 END', [$staleBefore]);

        // Stale leads keep oldest-first among themselves: the CASE below
        // only ranks by backlog, and for stale rows that would reshuffle
        // them — so rank stale rows equally (0) and only rank fresh ones.
        $ranked = $waitingCounts->sortDesc()->keys()->values();
        if ($ranked->isNotEmpty()) {
            $cases = $ranked->map(fn ($id, $rank) => 'WHEN ' . (int) $id . ' THEN ' . ($rank + 1))->implode(' ');
            $query->orderByRaw(
                "CASE WHEN pancake_created_at <= ? THEN 0 ELSE (CASE product_id {$cases} ELSE " . ($ranked->count() + 1) . ' END) END',
                [$staleBefore]
            );
        }

        return $query->orderBy('pancake_created_at');
    }
}
