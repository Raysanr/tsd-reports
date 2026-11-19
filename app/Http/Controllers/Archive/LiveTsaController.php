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
        $data = [
            'rows' => $rows->values(),
            'summary' => LiveTsaBoard::summary($rows),
            'updatedAt' => now(),
            'totals' => ($withTsd ? LiveTsaBoard::monthFigures(now()) : []) + ($withPartners ? LiveTsaBoard::partnerMonthFigures(now()) : []),
        ];

        // The page polls itself for just the board fragment every 30s.
        if ($request->boolean('partial')) {
            return view('archive.partials.live-board', $data);
        }

        $year = (int) $request->input('year', now()->year);
        if ($year < 2000 || $year > now()->year + 1) {
            $year = (int) now()->year;
        }

        return view('archive.live-tsa', $data + [
            'year' => $year,
            'figures' => match (true) {
                $isPartnerTab => LiveTsaBoard::partnerYearFigures($year, $orderTeam),
                $team === 'all' => LiveTsaBoard::mergeYearFigures(LiveTsaBoard::yearFigures($year), LiveTsaBoard::partnerYearFigures($year)),
                default => LiveTsaBoard::yearFigures($year, $orderTeam),
            },
            'teams' => $teams,
            'team' => $team,
        ]);
    }
}
