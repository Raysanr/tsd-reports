<?php

namespace App\Http\Middleware;

use App\Support\CostBreakdownVariant;
use App\Support\DmMonthLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses any Data Management save that touches a locked month (2026-11-23,
 * "Lock closed months"). Runs inside DataManagementDataset (dataset and Cost
 * Breakdown variant already set). Reads only — GETs — always pass, as do
 * saves that belong to no month (product groups, product costs, labels).
 */
class DataManagementMonthLock
{
    /** Routes that pick their month from the session (the page's month picker). */
    private const SESSION_MONTHS = [
        'data.projections.*' => 'projections.month',
        'data.rows.reorder'  => 'projections.month',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->routeIs('data.month-locks*')) {
            return $next($request);
        }

        $month = self::monthOf($request);
        if (! DmMonthLock::isLocked($month)) {
            return $next($request);
        }

        $message = DmMonthLock::label($month) . ' is locked. Ask an admin to unlock it in Month locks before changing it.';
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['success' => false, 'message' => $message], 423);
        }

        return back()->with('error', $message);
    }

    /** The month (Y-m) a save belongs to, or null when it has none. */
    public static function monthOf(Request $request): ?string
    {
        $route = $request->route();
        $date = $route?->parameter('date');
        if (is_string($date) && preg_match('/^\d{4}-\d{2}/', $date)) {
            return substr($date, 0, 7);
        }
        foreach (['projectionColumn', 'projectionCustomRow', 'costBreakdownRole', 'costBreakdownPool'] as $param) {
            $model = $route?->parameter($param);
            if (is_object($model) && preg_match('/^\d{4}-\d{2}$/', (string) ($model->month ?? ''))) {
                return $model->month;
            }
        }
        $input = (string) $request->input('month', '');
        if (preg_match('/^\d{4}-\d{2}/', $input)) {
            return substr($input, 0, 7);
        }
        if ($request->routeIs('data.cost-breakdown*', 'data.actual-cost-breakdown*')) {
            return (string) session(CostBreakdownVariant::slug() . '.month', now()->format('Y-m'));
        }
        foreach (self::SESSION_MONTHS as $pattern => $key) {
            if ($request->routeIs($pattern)) {
                return (string) session($key, now()->format('Y-m'));
            }
        }

        return null;
    }
}
