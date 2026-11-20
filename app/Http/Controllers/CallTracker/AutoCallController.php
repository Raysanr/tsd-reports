<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Support\AutoCall;
use Illuminate\Http\Request;

/** Auto-call (2026-10-26): the next lead the signed-in TSA's browser should
 *  dial, or null. See App\Support\AutoCall. */
class AutoCallController extends Controller
{
    public function next(Request $request)
    {
        $tsa = $request->user()->tsa;
        if ($tsa) {
            \App\Console\Commands\ExpireWrapUpStatuses::expireIfDue($tsa->fresh());
        }
        $tsa = $tsa?->fresh();
        $lead = null;
        // only one browser may dial this phone at a time (see AutoCall::claimDialSlot())
        if ($tsa && in_array($tsa->status, AutoCall::AVAILABLE_STATUSES, true) && AutoCall::isOn($tsa)
            && ! AutoCall::phoneInUseByOther($tsa) && AutoCall::claimDialSlot($tsa)) {
            $lead = AutoCall::next($tsa);
            if (! $lead) {
                AutoCall::releaseDialSlot($tsa);
            }
        }

        return response()->json(['lead' => $lead ? [
            'id' => $lead->id,
            'name' => $lead->customer_name ?: 'Customer',
            'number' => $lead->phone_number ?: $lead->dialable_number,
            'dialNumber' => $lead->dialable_number,
            'dialHost' => $tsa->dialer_host,
            'product' => $lead->product?->display_name,
        ] : null]);
    }
}
