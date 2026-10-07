<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

/**
 * TSD Data Management — Activity Log (explicit request, 2026-10-07: "can
 * you add another page that is ACTIVITY LOG? ... the history of who
 * edited this ... and what she edited ... all activites in every page
 * should be recorded on that"). Reuses the app's existing ActivityLog
 * model/table (see App\Models\ActivityLog and App\Support\ActivityLogger)
 * — the same audit trail the CONFIG-only /audit-log page already reads —
 * rather than a second logging system, filtered down to just the 5 Data
 * Management pages' own action prefixes (projection.*, dsppr.*,
 * tsa_sales.*, expected_income.*, cost_breakdown.*).
 *
 * Visible to normal (TSA) users too (explicit confirmation when asked:
 * "visible to TSAs too, but Cost Breakdown entries hidden from them" —
 * matches this module's own 2026-10-07 TSA-access reversal, where every
 * page except Cost Breakdown opened up to normal users). A normal user's
 * own query here EXCLUDES cost_breakdown.* entries by that same action
 * prefix — no separate "visibility" column needed, same mechanical
 * filtering ActivityLogger::logFieldUpdate()'s own doc comment describes.
 */
class ActivityLogController extends Controller
{
    /** Every Data Management action prefix this page shows, in sidebar/
     *  filter-pill order. Kept as a single source of truth here so a 6th
     *  Data Management page later only needs one line added. */
    private const MODULES = [
        'projection'       => 'Projections',
        'dsppr'            => 'DSPPR - TSM Report',
        'tsa_sales'        => 'Summary Sales Report',
        'expected_income'  => 'Expected Income',
        'cost_breakdown'   => 'Cost Breakdown',
    ];

    public function index(Request $request)
    {
        $query = trim((string) $request->query('q', ''));
        $module = $request->query('module', '');
        $module = array_key_exists($module, self::MODULES) ? $module : '';

        $isAdmin = auth()->user()->isAtLeastAdmin();
        $visibleModules = $isAdmin ? self::MODULES : collect(self::MODULES)->except('cost_breakdown')->all();

        $logs = ActivityLog::with('user')
            ->where(function ($q) use ($visibleModules) {
                foreach (array_keys($visibleModules) as $prefix) {
                    $q->orWhere('action', 'like', "{$prefix}.%");
                }
            })
            ->when($module !== '', fn ($q) => $q->where('action', 'like', "{$module}.%"))
            ->when($query !== '', fn ($q) => $q->where('description', 'like', "%{$query}%"))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('data.activity-log', [
            'logs' => $logs,
            'query' => $query,
            'module' => $module,
            'modules' => $visibleModules,
        ]);
    }
}
