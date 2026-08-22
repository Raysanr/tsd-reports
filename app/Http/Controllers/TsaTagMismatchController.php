<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\TsaShift;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * TSA Tag Mismatches (explicit request, 2026-08-21) — a real discrepancy the
 * user found by hand: Pancake POS showed 11 upsell orders tagged "ANGEL"
 * (Angelica Margallo's own name tag) for Aug 20, but her TSA Leaderboard
 * total that same day only counted 10.
 *
 * Root cause: SyncTodayOrders::extractTsaInfo() attributes an order to a TSA
 * PRIMARILY by the Pancake account that closed the upsell item
 * (assigning_seller), not by the order's own name tag — the tag is only a
 * fallback when no account matches (see that method's own long doc comment,
 * 2026-08-07). This is deliberate and right the overwhelming majority of the
 * time (two people's names never legitimately agree on one order), but it
 * means a tagged order can silently end up counted under a DIFFERENT TSA (or
 * nobody) whenever whoever actually clicked "closed" in Pancake isn't the
 * same person the tag names — a coverage swap, a teammate helping close a
 * lead, a stale/leftover tag from before a handoff, etc.
 *
 * This page surfaces every such case after the fact, purely by re-deriving
 * "what the tag alone would imply" from each order's own already-synced
 * raw_tags and comparing it to the tsa_name that sync actually settled on —
 * no re-fetch from Pancake needed. It exists to make that class of
 * discrepancy checkable across every TSA and every date range, not just the
 * one order a user happened to notice by hand.
 */
class TsaTagMismatchController extends Controller
{
    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', session('filters.tag_mismatches.date_from', now('Asia/Manila')->format('Y-m-d')));
        $dateTo   = $request->input('date_to', session('filters.tag_mismatches.date_to', $dateFrom));
        $from     = Carbon::parse($dateFrom, 'Asia/Manila')->startOfDay();
        $to       = Carbon::parse($dateTo, 'Asia/Manila')->endOfDay();

        session([
            'filters.tag_mismatches.date_from' => $dateFrom,
            'filters.tag_mismatches.date_to'   => $dateTo,
        ]);

        $tagMap       = $this->buildTagMap();
        $displayNames = TsaShift::pluck('display_name', 'tsa_key');

        $orders = Order::whereBetween('pancake_created_at', [$from, $to])
            ->whereNotNull('raw_tags')
            ->orderByDesc('pancake_created_at')
            ->get();

        $mismatches = $orders
            ->map(function (Order $order) use ($tagMap, $displayNames) {
                $tagImplied = null;
                foreach ($order->raw_tags ?? [] as $tag) {
                    $key = strtoupper(trim((string) $tag));
                    if (isset($tagMap[$key])) {
                        $tagImplied = $tagMap[$key];
                        break;
                    }
                }

                if ($tagImplied === null || $tagImplied === $order->tsa_name) {
                    return null;
                }

                return (object) [
                    'order'            => $order,
                    'tag_implied_key'  => $tagImplied,
                    'tag_implied_name' => $displayNames[$tagImplied] ?? $tagImplied,
                    'actual_key'       => $order->tsa_name,
                    'actual_name'      => $order->tsa_name ? ($displayNames[$order->tsa_name] ?? $order->tsa_name) : null,
                    'is_real_upsell'   => Order::isRealUpsell($order),
                ];
            })
            ->filter()
            ->values();

        $byTsa = $mismatches
            ->groupBy('tag_implied_name')
            ->map(fn (Collection $rows) => $rows->count())
            ->sortDesc();

        return view('tsa-tag-mismatches', [
            'mismatches' => $mismatches,
            'byTsa'      => $byTsa,
            'dateFrom'   => $from,
            'dateTo'     => $to,
        ]);
    }

    /** Tag keyword (uppercased) -> tsa_key, built the exact same way
     *  SyncTodayOrders::loadTsaMaps() builds its own $tsaMap, so "what the
     *  tag implies" here can never disagree with what a resync would also
     *  compute for the tag signal alone. TsaShift::all() (not scoped to
     *  active) — a tag can still name a now-deactivated TSA on an older order,
     *  and that's exactly the kind of thing worth surfacing here too. */
    private function buildTagMap(): array
    {
        $map = [];
        foreach (TsaShift::all() as $shift) {
            foreach ($shift->tag_keywords_array as $keyword) {
                $map[strtoupper($keyword)] = $shift->tsa_key;
            }
        }

        return $map;
    }
}
