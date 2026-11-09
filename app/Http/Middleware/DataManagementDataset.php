<?php

namespace App\Http\Middleware;

use App\Support\DataSet;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Data Management routes are registered once under a {dm} prefix — "data"
 * (TSD) or "partners" (Partners Data Management). This picks the
 * data set before route-model binding runs (see bootstrap/app.php's priority
 * list), so a bound Product/TsaShift comes from the right database, then
 * drops {dm} so controllers never see it.
 *
 * It also remembers the last page opened in each data set, so coming back from
 * the Hub resumes there (see the module's "/" route). Only real page visits
 * count, never fetches, downloads, or error pages. It lives in the session, so
 * it ends at logout and can't carry a page over to another account.
 */
class DataManagementDataset
{
    public function handle(Request $request, Closure $next): Response
    {
        $dm = $request->route('dm');
        $request->route()->forgetParameter('dm');

        if ($dm === 'partners') {
            DataSet::enterPartners();
        } else {
            DataSet::reset();
        }
        // Actual Cost Breakdown's own rows (2026-11-09) — set before
        // route-model binding, like the data set above.
        \App\Support\CostBreakdownVariant::set($request->routeIs('data.actual-cost-breakdown*')
            ? \App\Support\CostBreakdownVariant::ACTUAL
            : \App\Support\CostBreakdownVariant::BUDGET);

        try {
            $response = $next($request);
        } finally {
            \App\Support\CostBreakdownVariant::reset();
        }

        if ($request->isMethod('GET')
            && $request->header('Sec-Fetch-Dest') === 'document'
            && $response->getStatusCode() === 200
            && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            $request->session()->put(self::lastPageKey(), $request->getRequestUri());
        }

        return $response;
    }

    public static function lastPageKey(): string
    {
        return 'dm_last_page.'.(DataSet::isPartners() ? 'partners' : 'data');
    }
}
