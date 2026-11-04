<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\ArchiveDocument;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\Request;

/** Archived Records — resigned people, archived departments, trashed files. */
class RecordsController extends Controller
{
    public const TABS = ['people', 'departments', 'library', 'trash'];

    public function index(Request $request)
    {
        $tab = in_array($request->input('tab'), self::TABS, true) ? $request->input('tab') : 'people';

        return view('archive.records', [
            'tab' => $tab,
            'counts' => [
                'people' => Employee::where('status', 'resigned')->count(),
                'departments' => Department::whereNotNull('archived_at')->count(),
                'library' => \App\Models\LibraryItem::whereNotNull('archived_at')->count(),
                'trash' => ArchiveDocument::onlyTrashed()->count(),
            ],
            'people' => $tab === 'people' ? Employee::with('department')->where('status', 'resigned')->orderByDesc('left_at')->get() : collect(),
            'departments' => $tab === 'departments' ? Department::whereNotNull('archived_at')->withCount('employees')->orderByDesc('archived_at')->get() : collect(),
            'library' => $tab === 'library' ? \App\Models\LibraryItem::with(['product', 'department'])->whereNotNull('archived_at')->orderByDesc('archived_at')->get() : collect(),
            'trash' => $tab === 'trash' ? ArchiveDocument::onlyTrashed()->with('department')->orderByDesc('deleted_at')->get() : collect(),
        ]);
    }

    public function reinstate(Employee $employee)
    {
        $employee->update(['status' => 'active', 'left_at' => null]);
        \App\Support\ActivityLogger::log('archive.employee.reinstated', $employee, "Reinstated {$employee->full_name}");

        return back()->with('success', "{$employee->full_name} reinstated.");
    }
}
