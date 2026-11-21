<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Support\ActivityLogger;
use App\Support\ArchiveUndo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** People page: tick several people, then move them to a team, set who they
 *  report to, or set their status in one go (2026-11-21). Undo-able. */
class PeopleBulkController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'ids'           => ['required', 'array', 'min:1', 'max:500'],
            'ids.*'         => ['integer', 'exists:employees,id'],
            'action'        => ['required', Rule::in(['team', 'manager', 'status'])],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'manager_id'    => ['nullable', 'integer', 'exists:employees,id'],
            'status'        => ['nullable', Rule::in(['active', 'resigned'])],
        ]);
        $people = Employee::whereIn('id', $data['ids'])->get();
        $n = $people->count() . ' ' . \Illuminate\Support\Str::plural('person', $people->count());

        if ($data['action'] === 'team') {
            $teamId = $data['department_id'] ?? null;
            $before = array_merge(
                ArchiveUndo::capture($people, ['department_id', 'manager_id']),
                ArchiveUndo::capture(Department::whereIn('head_employee_id', $people->pluck('id'))->get(), ['head_employee_id']),
            );
            DB::transaction(function () use ($people, $teamId) {
                foreach ($people as $p) {
                    if ($p->department_id !== $teamId) {
                        // leaving a team they head → that team loses its head
                        Department::where('head_employee_id', $p->id)->where('id', '!=', $teamId)->update(['head_employee_id' => null]);
                    }
                    // no "Reports to": they sit under the new team's head
                    $p->update(['department_id' => $teamId, 'manager_id' => null]);
                }
            });
            $label = "Moved {$n} to " . ($teamId ? Department::find($teamId)->name : 'no team');
        } elseif ($data['action'] === 'manager') {
            $managerId = $data['manager_id'] ?? null;
            $managers = Employee::whereNotNull('manager_id')->pluck('manager_id', 'id');
            $loops = fn (Employee $p) => (function () use ($p, $managerId, $managers) {
                for ($id = $managerId, $i = 0; $id !== null && $i < 500; $id = $managers[$id] ?? null, $i++) {
                    if ((int) $id === $p->id) {
                        return true;
                    }
                }

                return false;
            })();
            $skipped = $people->filter($loops);
            $people = $people->diff($skipped);
            $before = ArchiveUndo::capture($people, ['manager_id']);
            $people->each(fn ($p) => $p->update(['manager_id' => $managerId]));
            $label = "Set {$n} to report to " . ($managerId ? Employee::find($managerId)->full_name : 'nobody')
                . ($skipped->isNotEmpty() ? ' (skipped ' . $skipped->pluck('full_name')->join(', ') . ': they would report to themselves)' : '');
        } else {
            $status = $data['status'] ?? 'active';
            $before = ArchiveUndo::capture($people, ['status', 'left_at', 'employment_status']);
            foreach ($people as $p) {
                $changes = ['status' => $status];
                if ($status === 'resigned') {
                    $changes['left_at'] = $p->left_at ?? today();
                } else {
                    $changes['left_at'] = null;
                    if (Employee::EMPLOYMENT_STATUSES[$p->employment_status]['former'] ?? false) {
                        $changes['employment_status'] = null;
                    }
                }
                $p->update($changes);
            }
            $label = 'Set ' . $n . ' to ' . ($status === 'resigned' ? 'Resigned (Former)' : 'Active');
        }

        ActivityLogger::log('archive.people.bulk', null, $label);
        ArchiveUndo::offer($label, $before);

        return back()->with('success', $label . '.');
    }
}
