<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Support\DataSet;
use App\Support\LiveTsaBoard;
use App\Support\Teams;
use Illuminate\Http\Request;

class LiveTsaController extends Controller
{
    public function index(Request $request)
    {
        // Team filter (All / each TSD team / each partner, 2026-11-19),
        // remembered per session like Call Tracker's own team toggle.
        // Partner tabs are keyed "p:<partner>".
        $teams = Teams::config();
        foreach (DataSet::partnerTeams() as $slug => $p) {
            $teams['p:' . $slug] = ['name' => $p['name'], 'order_team' => $p['order_team'], 'partner' => true];
        }
        $team = $request->input('team', session('filters.archive_live.team', 'all'));
        if ($team !== 'all' && ! isset($teams[$team])) {
            $team = 'all';
        }
        session(['filters.archive_live.team' => $team]);
        // All / Active / Former (2026-11-20), remembered like the team tab.
        $show = $request->input('show', session('filters.archive_live.show', 'all'));
        if (! in_array($show, ['all', 'active', 'former'], true)) {
            $show = 'all';
        }
        session(['filters.archive_live.show' => $show]);
        $isPartnerTab = $team !== 'all' && ! empty($teams[$team]['partner']);
        $withTsd = ! $isPartnerTab;
        $withPartners = $team === 'all' || $isPartnerTab;
        $orderTeam = $team === 'all' ? null : $teams[$team]['order_team'];

        $rows = collect();
        if ($withTsd) {
            $rows = $rows->concat(LiveTsaBoard::rows($orderTeam));
        }
        if ($withPartners) {
            $rows = $rows->concat(LiveTsaBoard::partnerRows($isPartnerTab ? $orderTeam : null));
        }
        // Card figures: this month so far, same numbers as the Summary Sales Report.
        $totals = ($withTsd ? LiveTsaBoard::monthFigures(now()) : []) + ($withPartners ? LiveTsaBoard::partnerMonthFigures(now()) : []);
        $summary = LiveTsaBoard::summary($rows);
        // One card per person on the All tab (2026-11-20: Jhun had a TSD and an M&J card).
        if ($team === 'all') {
            [$rows, $totals] = LiveTsaBoard::mergeCards($rows, $totals);
        }
        // Cards are the current roster, so the Former view has none.
        $data = [
            'rows' => $show === 'former' ? collect() : $rows->values(),
            'summary' => $summary,
            'updatedAt' => now(),
            'totals' => $totals,
            'show' => $show,
        ];

        // The page polls itself for just the board fragment every 30s.
        if ($request->boolean('partial')) {
            return view('archive.partials.live-board', $data);
        }

        $year = (int) $request->input('year', now()->year);
        if ($year < 2000 || $year > now()->year + 1) {
            $year = (int) now()->year;
        }

        $figures = match (true) {
            $isPartnerTab => LiveTsaBoard::partnerYearFigures($year, $orderTeam),
            $team === 'all' => LiveTsaBoard::mergeYearFigures(LiveTsaBoard::yearFigures($year), LiveTsaBoard::partnerYearFigures($year)),
            default => LiveTsaBoard::yearFigures($year, $orderTeam),
        };
        if ($show !== 'all') {
            $figures = LiveTsaBoard::withTotals(
                $figures['rows']->filter(fn ($r) => (bool) $r['former'] === ($show === 'former'))->values(),
                $figures['sheetMonths']
            );
        }

        return view('archive.live-tsa', $data + [
            'year' => $year,
            'figures' => $figures,
            'teams' => $teams,
            'team' => $team,
        ]);
    }
}
