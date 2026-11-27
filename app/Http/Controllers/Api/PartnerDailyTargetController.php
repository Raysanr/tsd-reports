<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectionColumn;
use App\Support\DataSet;
use App\Support\TsaDailyTarget;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GET /api/partner/v1/daily-target?partner=<slug>&month=YYYY-MM (2026-11-27).
 * A partner app's Daily Target bubble reads its Individual TSA Daily Target
 * from THIS app's Partners Data Management → Projections ("<partner> —
 * Individual TSA: Daily Target", Gross Sales), the same figure TSD's own
 * bubble uses for Opening/Closing (TsaDailyTarget::targetForShiftKey()).
 * daily_target is null when that month has no Projections yet.
 */
class PartnerDailyTargetController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'partner' => ['required', 'string', 'in:' . implode(',', array_keys(config('partners.partners', [])))],
            'month'   => ['nullable', 'date_format:Y-m'],
        ]);
        $date = isset($data['month']) ? Carbon::createFromFormat('Y-m-d', $data['month'] . '-01') : today();
        $shiftKey = str_replace('-', '_', $data['partner']) . '_shift';

        $target = DataSet::onPartners(fn () => in_array($shiftKey, ProjectionColumn::shiftKeys(), true)
            ? TsaDailyTarget::targetForShiftKey($shiftKey, $date)
            : null);

        return response()->json([
            'partner'      => $data['partner'],
            'name'         => config("partners.partners.{$data['partner']}.name"),
            'month'        => $date->format('Y-m'),
            'daily_target' => $target !== null && $target > 0 ? round($target, 2) : null,
        ]);
    }
}
