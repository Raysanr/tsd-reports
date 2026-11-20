<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One-click Org Chart / Teams actions (2026-11-20 redesign): set a team's
 * head, add members, move a person, take a person out of a team. Each is a
 * plain form post that redirects back with a toast.
 */
class OrgActionController extends Controller
{
    /** Make someone the head of a team. They join the team, and anyone who
     *  reported to the old head now reports to the new one. */
    public function setHead(Request $request, Department $department)
    {
        $data = $request->validate(['employee_id' => ['nullable', 'integer', 'exists:employees,id']]);
        $new = isset($data['employee_id']) ? Employee::findOrFail($data['employee_id']) : null;
        $oldId = $department->head_employee_id;

        if ($new === null) {
            $department->update(['head_employee_id' => null]);
            ActivityLogger::log('archive.department.head', $department, "Removed the head of \"{$department->name}\"");

            return back()->with('success', "\"{$department->name}\" has no head now.");
        }
        if ($new->status === 'resigned') {
            throw ValidationException::withMessages(['employee_id' => "{$new->full_name} is a former TSA and can't be a team head."]);
        }

        DB::transaction(function () use ($department, $new, $oldId) {
            $department->update(['head_employee_id' => $new->id]);
            $changes = ['department_id' => $department->id];
            // a head can't report to someone inside their own team
            if ($new->manager_id && Employee::whereKey($new->manager_id)->where('department_id', $department->id)->exists()) {
                $changes['manager_id'] = null;
            }
            $new->update($changes);
            if ($oldId && $oldId !== $new->id) {
                Employee::current()->where('manager_id', $oldId)->whereKeyNot($new->id)
                    ->where('department_id', $department->id)->get()
                    ->each(fn ($e) => $e->update(['manager_id' => $new->id]));
            }
        });
        ActivityLogger::log('archive.department.head', $department, "{$new->full_name} is now head of \"{$department->name}\"");

        return back()->with('success', "{$new->full_name} is now head of {$department->name}.");
    }

    /** Add one or more people to a team; they report to its head. */
    public function addMembers(Request $request, Department $department)
    {
        $data = $request->validate([
            'employee_ids'   => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
        ], ['employee_ids.required' => 'Pick at least one person.']);

        $people = Employee::current()->whereKey($data['employee_ids'])->get();
        foreach ($people as $p) {
            $this->placeInTeam($p, $department);
        }
        ActivityLogger::log('archive.department.members', $department, 'Added ' . $people->pluck('full_name')->join(', ', ' and ') . " to \"{$department->name}\"");

        return back()->with('success', $people->count() === 1
            ? "{$people->first()->full_name} added to {$department->name}."
            : "{$people->count()} people added to {$department->name}.");
    }

    /** "Move to…": new team and/or new manager. */
    public function move(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'manager_id'    => ['nullable', 'integer', 'exists:employees,id'],
        ]);
        $managerId = $data['manager_id'] ?? null;
        if ($managerId && $this->wouldLoop($employee, (int) $managerId)) {
            throw ValidationException::withMessages(['manager_id' => 'That person reports (directly or not) to ' . $employee->full_name . ' — pick someone else.']);
        }
        $teamId = $data['department_id'] ?? null;

        DB::transaction(function () use ($employee, $teamId, $managerId) {
            // leaving a team they head → that team loses its head
            if ($employee->department_id !== $teamId) {
                Department::where('head_employee_id', $employee->id)->where('id', '!=', $teamId)->update(['head_employee_id' => null]);
            }
            $employee->update(['department_id' => $teamId, 'manager_id' => $managerId]);
        });
        $team = $teamId ? Department::find($teamId)?->name : null;
        $msg = $employee->full_name . ' moved to ' . ($team ?? 'no team') . ($managerId ? ', reporting to ' . Employee::find($managerId)->full_name : '');
        ActivityLogger::log('archive.employee.moved', $employee, $msg);

        return back()->with('success', $msg . '.');
    }

    /** Take someone out of their team (they stay in People). */
    public function removeFromTeam(Employee $employee)
    {
        $team = $employee->department?->name;
        DB::transaction(function () use ($employee) {
            Department::where('head_employee_id', $employee->id)->update(['head_employee_id' => null]);
            // their direct reports move up to this person's own manager
            Employee::where('manager_id', $employee->id)->get()->each(fn ($e) => $e->update(['manager_id' => $employee->manager_id]));
            $employee->update(['department_id' => null, 'manager_id' => null]);
        });
        ActivityLogger::log('archive.employee.moved', $employee, "Removed {$employee->full_name} from " . ($team ? "\"{$team}\"" : 'the chart'));

        return back()->with('success', "{$employee->full_name} removed from " . ($team ?? 'the chart') . '. They are still in People.');
    }

    private function placeInTeam(Employee $p, Department $team): void
    {
        $head = $team->head_employee_id;
        $changes = ['department_id' => $team->id];
        if ($head !== $p->id) {
            $changes['manager_id'] = $head && ! $this->wouldLoop($p, $head) ? $head : null;
        }
        if ($p->department_id !== $team->id) {
            Department::where('head_employee_id', $p->id)->update(['head_employee_id' => null]);
        }
        $p->update($changes);
    }

    /** True when $managerId is $employee or sits somewhere below them. */
    private function wouldLoop(Employee $employee, int $managerId): bool
    {
        $managers = Employee::whereNotNull('manager_id')->pluck('manager_id', 'id');
        for ($id = $managerId, $i = 0; $id !== null && $i < 500; $id = $managers[$id] ?? null, $i++) {
            if ((int) $id === $employee->id) {
                return true;
            }
        }

        return false;
    }
}
