<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class UnmatchedOrdersController extends Controller
{
    /** UI/UX review finding: the actual task here is "which PRODUCT NAMES are
     *  missing from Product Management so I can add them" — a flat,
     *  chronological list of individual orders (previously the only view)
     *  answers a different question and doesn't scale once this list runs
     *  into the thousands. The grouped summary below answers the real
     *  question directly; clicking a product name filters the list beneath
     *  it to just that product's orders. */
    public function index(Request $request)
    {
        $selectedProduct = $request->query('product');

        $query = Order::whereNull('team');
        if ($selectedProduct === '(no product name)') {
            // The COALESCE fallback below stands in for a genuinely NULL
            // `product` column — that literal string never exists as a real
            // value, so filtering by it directly would always match nothing.
            $query->whereNull('product');
        } elseif ($selectedProduct) {
            $query->where('product', $selectedProduct);
        }

        $orders = $query->orderByDesc('pancake_created_at')
            ->paginate(30)
            ->withQueryString();

        $totalUnmatched = Order::whereNull('team')->count();

        $byProduct = Order::whereNull('team')
            ->selectRaw('COALESCE(product, \'(no product name)\') as product_name, COUNT(*) as order_count, SUM(amount) as total_amount')
            ->groupBy('product_name')
            ->orderByDesc('order_count')
            ->get();

        return view('unmatched-orders', compact('orders', 'totalUnmatched', 'byProduct', 'selectedProduct'));
    }

    /** Manually triggers the existing orders:reinfer-teams command — the same
     *  thing that already runs automatically after saving a product, exposed
     *  here so an admin can re-check without needing to touch Product
     *  Management first (e.g. after editing a TSA's keywords instead, or just
     *  to recheck after fixing a typo they already saved). */
    public function reinfer(Request $request)
    {
        Artisan::call('orders:reinfer-teams');
        $output = trim(Artisan::output());

        // The command's own final line is "Re-inference complete: updated N
        // orders." — surface that directly rather than re-deriving the count
        // ourselves, so this can never disagree with what the command itself
        // reports.
        $summaryLine = collect(explode("\n", $output))->last(fn ($line) => str_contains($line, 'Re-inference complete'));

        ActivityLogger::log('unmatched-orders.reinfer', null, $summaryLine ?: 'Re-inference ran.');

        return redirect()->route('unmatched-orders')
            ->with('success', $summaryLine ?: 'Re-inference ran.');
    }
}
