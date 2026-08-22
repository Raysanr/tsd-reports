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
 * The default view below catches ONE class of gap: SyncTodayOrders::
 * extractTsaInfo() attributes an order to a TSA PRIMARILY by the Pancake
 * account that closed the upsell item (assigning_seller), not by the order's
 * own name tag — the tag is only a fallback when no account matches (see
 * that method's own long doc comment, 2026-08-07). That's deliberate and
 * right almost every time, but can silently credit a tagged order to a
 * DIFFERENT TSA (or nobody).
 *
 * Live investigation (2026-08-22) proved that isn't always the whole story:
 * the user confirmed 11 real orders tagged "ANGEL", all genuinely hers, with
 * this page reporting ZERO mismatches for that date — yet her Leaderboard
 * total was still short by one. That means the gap can also be a COUNTING
 * exclusion that happens AFTER correct attribution: an order can be tagged
 * right, credited to the right TSA, and still never count as a real upsell
 * because of an excluded seller account (config/excluded_upsell_sellers.php
 * — same shape as the Ralph Cruz incident this session already fixed once),
 * a cancelled/removed add-on, a void/restocking status, or simply never
 * having carried the real "UPSELL TSD" Pancake tag despite a human-readable
 * note saying "WITH UPSELL".
 *
 * ?tsa={tsa_key} switches this page into a per-TSA audit: every order
 * carrying that TSA's own tag for the range, each one labeled with exactly
 * why it did or didn't count — so the next time this happens, the reason is
 * directly visible instead of requiring another investigation from scratch.
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
        $tsaOptions   = collect($tagMap)->unique()->values()
            ->mapWithKeys(fn ($key) => [$key => $displayNames[$key] ?? $key])
            ->sort();

        $orders = Order::whereBetween('pancake_created_at', [$from, $to])
            ->whereNotNull('raw_tags')
            ->orderByDesc('pancake_created_at')
            ->get();

        $tsaFilter = $request->input('tsa');

        if ($tsaFilter) {
            $rows = $orders
                ->map(fn (Order $order) => $this->tagImpliedKey($order, $tagMap) === $tsaFilter
                    ? $this->buildAuditRow($order, $tsaFilter, $displayNames)
                    : null)
                ->filter()
                ->values();

            return view('tsa-tag-mismatches', [
                'mode'          => 'audit',
                'rows'          => $rows,
                'tsaFilter'     => $tsaFilter,
                'tsaFilterName' => $displayNames[$tsaFilter] ?? $tsaFilter,
                'tsaOptions'    => $tsaOptions,
                'dateFrom'      => $from,
                'dateTo'        => $to,
            ]);
        }

        $mismatches = $orders
            ->map(function (Order $order) use ($tagMap, $displayNames) {
                $tagImplied = $this->tagImpliedKey($order, $tagMap);

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
            'mode'       => 'mismatches',
            'mismatches' => $mismatches,
            'byTsa'      => $byTsa,
            'tsaOptions' => $tsaOptions,
            'dateFrom'   => $from,
            'dateTo'     => $to,
        ]);
    }

    /** Every order carrying $tsaKey's own name tag for the range, labeled
     *  with exactly why it did or didn't count toward her real-upsell total
     *  — a superset of the mismatch view above: covers both "credited to
     *  someone else" AND "credited correctly but excluded from the count
     *  anyway", since the live investigation this page grew out of proved
     *  a tag-mismatch check alone isn't enough to explain every gap. */
    private function buildAuditRow(Order $order, string $tsaKey, Collection $displayNames): object
    {
        $counted = Order::isRealUpsell($order);

        return (object) [
            'order'         => $order,
            'actual_key'    => $order->tsa_name,
            'actual_name'   => $order->tsa_name ? ($displayNames[$order->tsa_name] ?? $order->tsa_name) : null,
            'agrees'        => $order->tsa_name === $tsaKey,
            'has_real_tag'  => Order::hasUpsellTag($order->raw_tags ?? []),
            'counted'       => $counted,
            'reason'        => $counted ? null : $this->notCountedReason($order),
        ];
    }

    /** Mirrors, in order, every branch that can force is_upsell/is_returned_
     *  upsell false at sync time (SyncTodayOrders' $hasUpsellTag/$isUpsell/
     *  $isReturnedUpsell/$isRestockingUpsell chain) — checked in the same
     *  precedence so the reason shown here always matches the actual cause,
     *  not just a plausible-sounding guess. */
    private function notCountedReason(Order $order): string
    {
        if ($order->excluded_upsell_seller) {
            return 'Closed under an excluded seller account (config/excluded_upsell_sellers.php)';
        }
        if ($order->is_cancelled_upsell) {
            return 'Upsell add-on was cancelled/removed from the order';
        }
        if ($order->is_restocking_upsell) {
            return 'Order is in Restocking status';
        }
        if (in_array($order->status_code, Order::VOID_STATUSES, true)) {
            return 'Order status: ' . (Order::STATUS_LABELS[$order->status_code] ?? $order->status_code);
        }
        if (!Order::hasUpsellTag($order->raw_tags ?? [])) {
            return 'No "UPSELL TSD" tag on this order — only the TSA name tag';
        }

        return 'Not classified as a real upsell';
    }

    /** First raw_tags entry (uppercased) that names a known TSA, or null. */
    private function tagImpliedKey(Order $order, array $tagMap): ?string
    {
        foreach ($order->raw_tags ?? [] as $tag) {
            $key = strtoupper(trim((string) $tag));
            if (isset($tagMap[$key])) {
                return $tagMap[$key];
            }
        }

        return null;
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
