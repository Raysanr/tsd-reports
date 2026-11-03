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

        // A TSA removed today is already gone from today's figures here.
        \App\Models\TsaShift::excludeRemovalDay();

        return $next($request);
    }
}
