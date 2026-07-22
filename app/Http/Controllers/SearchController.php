<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    private const MAX_RESULTS_PER_TYPE = 5;

    /**
     * Powers the topbar search box: TSA agents, Products, Orders, and (admin
     * only) Users and Audit Log entries.
     *
     * Orders have no order-detail page anywhere in this app to link a single
     * result to — a match instead links to Leads Report for that order's own
     * team + effective date (same pancake_inserted_at ?? pancake_created_at
     * Leads Report itself buckets by), where the order will actually be
     * visible in that day's Orders table. An Unmatched order (team NULL) has
     * nowhere sensible to link to at all, so those are dropped from results
     * entirely rather than linking somewhere wrong.
     *
     * Users and Audit Log are admin-only pages (see routes/web.php's
     * role:super_admin,admin group) — gated the same way the sidebar nav
     * itself is (User::isAtLeastAdmin()), so a normal/guest user's search
     * never surfaces a result they'd be denied access to.
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['tsas' => [], 'products' => [], 'orders' => [], 'users' => [], 'auditLog' => []]);
        }

        $teamSlugsByOrderTeam = collect(config('teams', []))
            ->mapWithKeys(fn ($cfg, $slug) => [$cfg['order_team'] => $slug]);

        $tsas = TsaShift::where('display_name', 'like', "%{$query}%")
            ->orWhere('tsa_key', 'like', "%{$query}%")
            ->orderBy('display_name')
            ->limit(self::MAX_RESULTS_PER_TYPE)
            ->get()
            ->map(function (TsaShift $shift) use ($teamSlugsByOrderTeam) {
                $teamSlug = $teamSlugsByOrderTeam->get($shift->team);
                if ($teamSlug === null) return null;

                return [
                    'label' => $shift->display_name,
                    'url'   => route('tsa-performance.individual', ['team' => $teamSlug, 'tsaKey' => $shift->tsa_key]),
                ];
            })
            ->filter()
            ->values();

        $products = Product::where('display_name', 'like', "%{$query}%")
            ->where('is_hidden', false)
            ->orderBy('display_name')
            ->limit(self::MAX_RESULTS_PER_TYPE)
            ->get()
            ->map(function (Product $product) use ($teamSlugsByOrderTeam) {
                $teamSlug = $teamSlugsByOrderTeam->get($product->team);
                if ($teamSlug === null) return null;

                return [
                    'label' => $product->display_name,
                    'url'   => route('tsa-performance', ['team' => $teamSlug, 'product' => $product->display_name]),
                ];
            })
            ->filter()
            ->values();

        $orders = Order::where('pancake_order_id', 'like', "%{$query}%")
            ->orWhere('product', 'like', "%{$query}%")
            ->orWhere('bundle_description', 'like', "%{$query}%")
            ->orWhere('disposition', 'like', "%{$query}%")
            ->orderByDesc('pancake_created_at')
            ->limit(self::MAX_RESULTS_PER_TYPE)
            ->get()
            ->map(function (Order $order) use ($teamSlugsByOrderTeam) {
                $teamSlug = $teamSlugsByOrderTeam->get($order->team);
                $date     = $order->effective_created_at;
                if ($teamSlug === null || $date === null) return null;

                return [
                    'label' => "#{$order->pancake_order_id} — {$order->product}",
                    'url'   => route('leads-report', [
                        'team' => $teamSlug, 'range' => 'dates',
                        'date_from' => $date->toDateString(), 'date_to' => $date->toDateString(),
                    ]),
                ];
            })
            ->filter()
            ->values();

        $users    = collect();
        $auditLog = collect();

        if ($request->user()?->isAtLeastAdmin()) {
            $users = User::where('name', 'like', "%{$query}%")
                ->orWhere('email', 'like', "%{$query}%")
                ->orderBy('name')
                ->limit(self::MAX_RESULTS_PER_TYPE)
                ->get()
                ->map(fn (User $user) => [
                    'label' => "{$user->name} ({$user->email})",
                    'url'   => route('user-management'),
                ]);

            // Audit Log has its own real search (unlike Users/Orders above, which
            // just link to the general page): route('audit-log', ['q' => ...])
            // hits ActivityLogController's server-side filter, so the destination
            // page is already narrowed to this exact query, not just the full list.
            $auditLog = ActivityLog::where('description', 'like', "%{$query}%")
                ->orderByDesc('created_at')
                ->limit(self::MAX_RESULTS_PER_TYPE)
                ->get()
                ->map(fn (ActivityLog $log) => [
                    'label' => $log->description,
                    'url'   => route('audit-log', ['q' => $query]),
                ]);
        }

        return response()->json([
            'tsas'     => $tsas,
            'products' => $products,
            'orders'   => $orders,
            'users'    => $users,
            'auditLog' => $auditLog,
        ]);
    }
}
