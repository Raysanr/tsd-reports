<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\ArchiveDocument;
use App\Models\Department;
use App\Models\Employee;
use App\Support\LiveTsaBoard;

class OverviewController extends Controller
{
    public function index()
    {
        $departments = Department::active()->withCount(['employees' => fn ($q) => $q->current()])
            ->orderByDesc('employees_count')->get();

        $tsaRows = LiveTsaBoard::rows();

        return view('archive.overview', [
            'stats' => [
                'departments' => $departments->count(),
                'employees'   => Employee::current()->count(),
                'documents'   => ArchiveDocument::count(),
                'storage'     => (int) ArchiveDocument::sum('size'),
            ],
            'departments' => $departments->take(6),
            'maxHeadcount' => max(1, (int) $departments->max('employees_count')),
            'recentDocuments' => ArchiveDocument::with('department')->latest()->limit(6)->get(),
            'expiring' => ArchiveDocument::with('employee')->whereNotNull('expires_at')
                ->whereDate('expires_at', '<=', today()->addDays(30))->orderBy('expires_at')->limit(6)->get(),
            'tsaSummary' => LiveTsaBoard::summary($tsaRows),
            ...$this->monthTop($tsaRows),
        ]);
    }

    /**
     * "Top TSAs this month": each TSA's month-to-date Total Net Income (Data
     * Management's Expected Income, via LiveTsaBoard::monthFigures() — same
     * figures and cache as the TSD Scoreboard), ranked by net income.
     */
    private function monthTop(\Illuminate\Support\Collection $tsaRows): array
    {
        $figures = LiveTsaBoard::monthFigures(now());
        $rows = $tsaRows->map(fn ($r) => $r + [
            'month_sales' => (float) ($figures[$r['id']]['sales'] ?? 0),
            'month_net'   => (float) ($figures[$r['id']]['net'] ?? 0),
        ]);

        return [
            'topTsas'    => $rows->sortByDesc('month_net')->take(5)->values(),
            'monthTotal' => ['sales' => $rows->sum('month_sales'), 'net' => $rows->sum('month_net'), 'tsas' => $rows->count()],
            'maxNet'     => max(1, (float) $rows->max('month_net')),
        ];
    }
}
