<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Support\LiveTsaBoard;
use App\Support\Teams;
use Illuminate\Http\Request;

class LiveTsaController extends Controller
{
    public function index(Request $request)
    {
        // Team filter (All / each configured team), remembered per session
        // like Call Tracker's own team toggle.
        $teams = Teams::config();
        $team = $request->input('team', session('filters.archive_live.team', 'all'));
        if ($team !== 'all' && ! isset($teams[$team])) {
            $team = 'all';
        }
        session(['filters.archive_live.team' => $team]);
        $orderTeam = $team === 'all' ? null : $teams[$team]['order_team'];

        $rows = LiveTsaBoard::rows($orderTeam);
        // Card figures: this month so far, same numbers as the Summary Sales Report.
        $data = [
            'rows' => $rows,
            'summary' => LiveTsaBoard::summary($rows),
            'updatedAt' => now(),
            'totals' => LiveTsaBoard::monthFigures(now()),
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
            'figures' => LiveTsaBoard::yearFigures($year, $orderTeam),
            'teams' => $teams,
            'team' => $team,
        ]);
    }
}
