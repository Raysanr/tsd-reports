<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->input('q', ''));

        $departments = Department::active()
            ->with(['parent', 'head'])
            ->withCount(['employees' => fn ($e) => $e->current(), 'documents', 'children'])
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")))
            ->orderBy('sort_order')->orderBy('name')->get();

        return view('archive.departments', [
            'departments' => $departments,
            'parentOptions' => Department::active()->orderBy('name')->get(['id', 'name']),
            'employeeOptions' => Employee::current()->orderBy('full_name')->get(['id', 'full_name', 'department_id']),
            'q' => $q,
        ]);
    }

    public function store(Request $request)
    {
        $department = Department::create($this->validated($request));
        ActivityLogger::log('archive.department.created', $department, "Created team \"{$department->name}\"");

        return back()->with('success', "Team \"{$department->name}\" created.");
    }

    public function update(Request $request, Department $department)
    {
        $data = $this->validated($request, $department);
        $department->update($data);
        ActivityLogger::log('archive.department.updated', $department, "Updated team \"{$department->name}\"");

        return back()->with('success', "Team \"{$department->name}\" updated.");
    }

    public function archive(Department $department)
    {
        $department->update(['archived_at' => now()]);
        ActivityLogger::log('archive.department.archived', $department, "Archived team \"{$department->name}\"");

        return back()->with('success', "Team \"{$department->name}\" moved to Archived Records.");
    }

    public function restore(Department $department)
    {
        $department->update(['archived_at' => null]);
        ActivityLogger::log('archive.department.restored', $department, "Restored team \"{$department->name}\"");

        return back()->with('success', "Team \"{$department->name}\" restored.");
    }

    private function validated(Request $request, ?Department $department = null): array
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:120'],
            'code'             => ['nullable', 'string', 'max:20'],
            'description'      => ['nullable', 'string', 'max:2000'],
            'color'            => ['required', Rule::in(array_keys(Department::COLORS))],
            'parent_id'        => ['nullable', 'integer', 'exists:departments,id'],
            'head_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'sort_order'       => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        // A department can't sit under itself or anything below it.
        if ($department && ! empty($data['parent_id']) && in_array((int) $data['parent_id'], $department->descendantIds(), true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'parent_id' => 'A team cannot be placed under itself or one of its own sub-teams.',
            ]);
        }

        return $data;
    }
}
