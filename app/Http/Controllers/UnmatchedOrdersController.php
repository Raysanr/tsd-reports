<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class UnmatchedOrdersController extends Controller
{
    public function index()
    {
        $orders = Order::whereNull('team')
            ->orderByDesc('pancake_created_at')
            ->paginate(30)
            ->withQueryString();

        $totalUnmatched = Order::whereNull('team')->count();

        return view('unmatched-orders', compact('orders', 'totalUnmatched'));
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

        return redirect()->route('unmatched-orders')
            ->with('success', $summaryLine ?: 'Re-inference ran.');
    }
}
