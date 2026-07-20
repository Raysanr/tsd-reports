<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\TagConflictReview;
use App\Support\TagConflicts;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class TagConflictReviewController extends Controller
{
    /** There's no SQL-level way to run Product::matchesText()'s normalized
     *  substring matching, so detecting a conflict means loading every
     *  candidate order into PHP and checking it — confirmed ~5s for a 90-day
     *  window (28k orders) vs ~15s scanning this app's entire history (106k).
     *  90 days comfortably covers everything an admin could still meaningfully
     *  act on in Pancake anyway, so it's a hard cap, not just a default. */
    private const MAX_WINDOW_DAYS = 90;
    private const PER_PAGE = 30;

    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', session('filters.tag_conflicts.date_from', now()->subDays(29)->toDateString()));
        $dateTo   = $request->input('date_to',   session('filters.tag_conflicts.date_to', now()->toDateString()));

        $from = Carbon::parse($dateFrom)->startOfDay();
        $to   = Carbon::parse($dateTo)->endOfDay();
        if ($from > $to) [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];

        $clamped = false;
        if ($from->diffInDays($to) > self::MAX_WINDOW_DAYS) {
            $from    = $to->copy()->subDays(self::MAX_WINDOW_DAYS)->startOfDay();
            $clamped = true;
        }
        $dateFrom = $from->toDateString();
        $dateTo   = $to->toDateString();

        session([
            'filters.tag_conflicts.date_from' => $dateFrom,
            'filters.tag_conflicts.date_to'   => $dateTo,
        ]);

        // 'reviewed=1' flips this into a small archive view of past decisions —
        // an admin who marked something reviewed by mistake, or wants to confirm
        // what was already checked, has somewhere to look instead of it just
        // vanishing. Everyone else lands on the open queue (the default).
        $showReviewed = $request->boolean('reviewed');

        $products = Product::orderBy('sort_order')->get();

        // whereNotNull(team/product/raw_tags): every one of those is required for
        // findConflict() to ever return non-null, so excluding them here up front
        // shrinks what has to be pulled into PHP without changing the result.
        $candidateOrders = Order::whereNotNull('team')
            ->whereNotNull('product')
            ->whereNotNull('raw_tags')
            ->whereBetween('pancake_created_at', [$from, $to])
            ->orderByDesc('pancake_created_at')
            ->get();

        // Small table (reviewing is rare — dozens, not thousands) — loading it
        // whole and flipping to a keyed set is cheaper than a query per order.
        $reviewedOrderIds = TagConflictReview::pluck('order_id')->flip();

        $conflicts = $candidateOrders
            ->map(function (Order $order) use ($products) {
                $conflict = TagConflicts::findConflict($order, $products);
                return $conflict ? $conflict + ['order' => $order] : null;
            })
            ->filter()
            ->filter(fn (array $row) => $showReviewed === $reviewedOrderIds->has($row['order']->id))
            ->values();

        $totalConflicts = $conflicts->count();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $pagedConflicts = new LengthAwarePaginator(
            $conflicts->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            $totalConflicts,
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('tag-conflicts', [
            'conflicts'      => $pagedConflicts,
            'totalConflicts' => $totalConflicts,
            'dateFrom'       => $dateFrom,
            'dateTo'         => $dateTo,
            'clamped'        => $clamped,
            'showReviewed'   => $showReviewed,
            'maxWindowDays'  => self::MAX_WINDOW_DAYS,
        ]);
    }

    /** Doesn't (can't) touch Pancake's tags — this just stops the conflict from
     *  resurfacing here once an admin has checked it in Pancake POS directly and
     *  decided it's already fine or already fixed there. */
    public function review(Request $request, Order $order)
    {
        TagConflictReview::markReviewed($order->id, $request->user()->id);

        return redirect()->route('tag-conflicts', $request->only(['date_from', 'date_to']))
            ->with('success', "Order #{$order->pancake_order_id} marked reviewed.");
    }

    /** Undo for an accidental/mistaken "Mark reviewed" — puts the order back in
     *  the open queue instead of it being reviewed-forever with no way back. */
    public function unreview(Request $request, Order $order)
    {
        TagConflictReview::where('order_id', $order->id)->delete();

        return redirect()->route('tag-conflicts', $request->only(['date_from', 'date_to']) + ['reviewed' => 1])
            ->with('success', "Order #{$order->pancake_order_id} moved back to the open queue.");
    }
}
