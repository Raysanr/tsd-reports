<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OrgLink;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Merge a duplicate person into this one (2026-11-21, e.g. SH Mari listed by
 * both M&J and PEAK.CO). Everything recorded on the duplicate — timeline,
 * incidents, coaching, files, reports, team-head spots — moves here; empty
 * details are filled from it; then the duplicate is deleted. Its roster
 * links are remembered (employee_merged_links) so the syncs don't re-add it.
 */
class PeopleMergeController extends Controller
{
    private const FILL = ['position', 'email', 'phone', 'birthday', 'department_id', 'manager_id', 'user_id',
        'employment_status', 'shift_schedule', 'manual_status', 'photo_path'];

    public function merge(Request $request, Employee $employee)
    {
        $data = $request->validate(['duplicate_id' => ['required', 'integer', 'exists:employees,id']]);
        $dup = Employee::findOrFail($data['duplicate_id']);
        if ($dup->is($employee)) {
            throw ValidationException::withMessages(['duplicate_id' => 'Pick a different person to merge in.']);
        }

        DB::transaction(function () use ($employee, $dup) {
            foreach (['employee_events', 'incidents', 'coaching_records', 'archive_documents'] as $table) {
                DB::table($table)->where('employee_id', $dup->id)->update(['employee_id' => $employee->id]);
            }
            Employee::where('manager_id', $dup->id)->whereKeyNot($employee->id)->update(['manager_id' => $employee->id]);
            Department::where('head_employee_id', $dup->id)->update(['head_employee_id' => $employee->id]);
            Department::whereNotNull('under_employee_ids')->get()->each(function (Department $d) use ($dup, $employee) {
                $ids = $d->under_employee_ids;
                if (in_array($dup->id, $ids, true)) {
                    $d->update(['under_employee_ids' => array_values(array_unique(array_map(fn ($id) => $id === $dup->id ? $employee->id : $id, $ids)))]);
                }
            });
            OrgLink::where('type', 'employee')->where('parent_id', $dup->id)->update(['parent_id' => $employee->id]);
            OrgLink::where('type', 'employee')->where('child_id', $dup->id)->update(['child_id' => $employee->id]);
            OrgLink::where('type', 'employee')->whereColumn('parent_id', 'child_id')->delete();

            // roster links: take them when empty here, otherwise remember them
            $links = [];
            $changes = [];
            foreach (['tsa_shift_id' => 'tsa_shift', 'partner_tsa_key' => 'partner_tsa'] as $col => $kind) {
                if ($dup->{$col} === null) {
                    continue;
                }
                $employee->{$col} === null ? $changes[$col] = $dup->{$col} : $links[] = [$kind, (string) $dup->{$col}];
            }
            foreach (DB::table('employee_merged_links')->where('employee_id', $dup->id)->get() as $l) {
                $links[] = [$l->kind, $l->value];
            }
            foreach (self::FILL as $col) {
                if (blank($employee->{$col}) && filled($dup->{$col}) && ! ($col === 'manager_id' && (int) $dup->{$col} === $employee->id)) {
                    $changes[$col] = $dup->{$col};
                }
            }
            if ($dup->hired_at && (! $employee->hired_at || $dup->hired_at->lt($employee->hired_at))) {
                $changes['hired_at'] = $dup->hired_at;
            }
            if (filled($dup->notes)) {
                $changes['notes'] = trim(($employee->notes ? $employee->notes . "\n\n" : '') . "From merged record \"{$dup->full_name}\": {$dup->notes}");
            }
            if ($dup->status !== 'resigned' && $employee->status === 'resigned') {
                $changes += ['status' => 'active', 'left_at' => null];
            }

            // free the unique columns and keep the photo file before deleting
            $dup->forceFill(['tsa_shift_id' => null, 'partner_tsa_key' => null, 'photo_path' => isset($changes['photo_path']) ? null : $dup->photo_path])->saveQuietly();
            DB::table('employee_merged_links')->where('employee_id', $dup->id)->delete();
            $employee->update($changes);
            foreach ($links as [$kind, $value]) {
                DB::table('employee_merged_links')->updateOrInsert(['kind' => $kind, 'value' => $value], ['employee_id' => $employee->id, 'updated_at' => now(), 'created_at' => now()]);
            }
            $employee->events()->create(['type' => 'milestone', 'title' => "Merged duplicate record \"{$dup->full_name}\" into this profile", 'occurred_on' => today(), 'user_id' => auth()->id()]);
            $dup->delete();
        });
        ActivityLogger::log('archive.employee.merged', $employee, "Merged \"{$dup->full_name}\" into \"{$employee->full_name}\"");

        return redirect()->route('archive.people.show', $employee)->with('success', "{$dup->full_name} merged into {$employee->full_name}.");
    }
}
