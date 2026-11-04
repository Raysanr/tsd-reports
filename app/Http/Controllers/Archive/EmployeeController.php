<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\ArchiveDocument;
use App\Models\Department;
use App\Models\Employee;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\ArchiveRosterSync;
use App\Support\LiveTsaBoard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        ArchiveRosterSync::run(); // new TSAs in TSA Management appear here automatically

        $q = trim((string) $request->input('q', ''));
        $departmentId = $request->integer('department') ?: null;
        $status = $request->input('status');
        if (! array_key_exists((string) $status, Employee::STATUSES)) {
            $status = null;
        }

        $employees = Employee::with(['department', 'manager', 'tsa'])
            ->withCount('documents')
            ->when($status, fn ($b) => $b->where('status', $status), fn ($b) => $b->current())
            ->when($departmentId, fn ($b) => $b->where('department_id', $departmentId))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('full_name', 'like', "%{$q}%")
                ->orWhere('position', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->orderBy('full_name')
            ->paginate(100)->withQueryString();

        // Default ("Current") view also lists former people below, same filters.
        $former = $status === null
            ? Employee::with(['department', 'manager', 'tsa'])->withCount('documents')
                ->where('status', 'resigned')
                ->when($departmentId, fn ($b) => $b->where('department_id', $departmentId))
                ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('full_name', 'like', "%{$q}%")
                    ->orWhere('position', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
                ->orderBy('full_name')->get()
            : collect();

        return view('archive.people', [
            'employees' => $employees,
            'former' => $former,
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'color']),
            'managerOptions' => Employee::current()->orderBy('full_name')->get(['id', 'full_name']),
            'tsaOptions' => TsaShift::orderBy('sort_order')->get(['id', 'tsa_key', 'display_name']),
            'userOptions' => User::orderBy('name')->get(['id', 'name', 'email']),
            'filters' => ['q' => $q, 'department' => $departmentId, 'status' => $status],
        ]);
    }

    public function show(Employee $employee)
    {
        $employee->load(['department', 'manager', 'reports', 'tsa', 'user', 'coachingRecords.coach', 'incidents', 'events.user']);
        $live = $employee->tsa_shift_id
            ? LiveTsaBoard::rows()->firstWhere('id', $employee->tsa_shift_id)
            : null;

        return view('archive.person', [
            'employee' => $employee,
            'documents' => $documents = ArchiveDocument::where('employee_id', $employee->id)->latest()->get(),
            'timeline' => $this->timeline($employee, $documents),
            'snapshots' => $employee->tsa_shift_id
                ? \App\Models\PerformanceSnapshot::where('tsa_shift_id', $employee->tsa_shift_id)->orderByDesc('month')->limit(12)->get()
                : collect(),
            'live' => $live,
            'categories' => ArchiveDocument::CATEGORIES,
            // Edit-profile + upload modals on this page.
            'departments' => Department::active()->orderBy('name')->get(['id', 'name']),
            'managerOptions' => Employee::current()->whereKeyNot($employee->id)->orderBy('full_name')->get(['id', 'full_name']),
            'tsaOptions' => TsaShift::orderBy('sort_order')->get(['id', 'tsa_key', 'display_name']),
            'userOptions' => User::orderBy('name')->get(['id', 'name', 'email']),
            'employees' => Employee::current()->orderBy('full_name')->get(['id', 'full_name']),
        ]);
    }

    /** Lifecycle timeline: recorded milestones + coaching/QA + incidents + uploads, newest first. */
    private function timeline(Employee $employee, $documents)
    {
        $items = collect();
        foreach ($employee->events as $ev) {
            $items->push(['date' => $ev->occurred_on, 'type' => $ev->type, 'title' => $ev->title, 'details' => $ev->details, 'by' => $ev->user?->name, 'event_id' => $ev->type === 'milestone' ? $ev->id : null, 'sort' => $ev->created_at]);
        }
        foreach ($employee->coachingRecords as $r) {
            $items->push(['date' => $r->session_date, 'type' => $r->type === 'qa' ? 'qa' : 'coaching', 'title' => ($r->type === 'qa' ? 'QA scorecard' . ($r->score !== null ? " — {$r->score}%" : '') : 'Coaching') . ": {$r->topic}", 'details' => null, 'by' => $r->coach?->name, 'event_id' => null, 'sort' => $r->created_at]);
        }
        foreach ($employee->incidents as $i) {
            $items->push(['date' => $i->incident_date, 'type' => 'incident', 'title' => \App\Models\Incident::TYPES[$i->type] . ": {$i->title}", 'details' => \App\Models\Incident::STATUSES[$i->status]['label'], 'by' => null, 'event_id' => null, 'sort' => $i->created_at]);
        }
        foreach ($documents as $d) {
            $items->push(['date' => $d->created_at, 'type' => 'document', 'title' => "Filed: {$d->title}", 'details' => null, 'by' => null, 'event_id' => null, 'sort' => $d->created_at]);
        }

        return $items->sortByDesc(fn ($i) => [$i['date']->format('Y-m-d'), $i['sort']?->timestamp ?? 0])->values();
    }

    public function storeEvent(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'title'       => ['required', 'string', 'max:200'],
            'details'     => ['nullable', 'string', 'max:2000'],
            'occurred_on' => ['required', 'date', 'before_or_equal:today'],
        ]);
        $employee->events()->create($data + ['type' => 'milestone', 'user_id' => auth()->id()]);
        ActivityLogger::log('archive.employee.milestone', $employee, "Added milestone for {$employee->full_name}: {$data['title']}");

        return back()->with('success', 'Milestone added.');
    }

    public function destroyEvent(Employee $employee, \App\Models\EmployeeEvent $event)
    {
        abort_unless($event->employee_id === $employee->id && $event->type === 'milestone', 404);
        $event->delete();

        return back()->with('success', 'Milestone removed.');
    }

    private const PHOTO_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    /** Serve a profile photo (admin-only route; never a public URL). */
    public function photo(Employee $employee)
    {
        $disk = Storage::disk(\App\Models\ArchiveDocument::DISK);
        abort_unless($employee->photo_path && $disk->exists($employee->photo_path), 404);
        $ext = strtolower(pathinfo($employee->photo_path, PATHINFO_EXTENSION));

        return response()->file($disk->path($employee->photo_path), [
            'Content-Type' => self::PHOTO_TYPES[$ext] ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=604800', // URL carries a version, so caching is safe
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Change the photo straight from the profile page. */
    public function updatePhoto(Request $request, Employee $employee)
    {
        $request->validate(['photo' => ['required', ...self::photoRules()]], self::photoMessages());
        $this->storePhoto($request->file('photo'), $employee);
        ActivityLogger::log('archive.employee.photo', $employee, "Updated {$employee->full_name}'s profile photo");

        return back()->with('success', 'Profile photo updated.');
    }

    public function destroyPhoto(Employee $employee)
    {
        $this->deletePhoto($employee);
        ActivityLogger::log('archive.employee.photo', $employee, "Removed {$employee->full_name}'s profile photo");

        return back()->with('success', 'Profile photo removed.');
    }

    private static function photoRules(): array
    {
        return ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];
    }

    private static function photoMessages(): array
    {
        return ['photo.max' => 'The photo must be 5 MB or smaller.', 'photo.mimes' => 'The photo must be a JPG, PNG or WEBP image.', 'photo.image' => 'The photo must be an image.'];
    }

    /** From the add/edit form: an optional new photo, or a "remove photo" tick. */
    private function savePhotoFromRequest(Request $request, Employee $employee): void
    {
        if ($request->hasFile('photo')) {
            $this->storePhoto($request->file('photo'), $employee);
        } elseif ($request->boolean('remove_photo')) {
            $this->deletePhoto($employee);
        }
    }

    private function storePhoto(\Illuminate\Http\UploadedFile $file, Employee $employee): void
    {
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        $path = $file->storeAs('photos', $employee->id . '-' . Str::random(10) . '.' . $ext, \App\Models\ArchiveDocument::DISK);
        $this->deletePhoto($employee);
        $employee->forceFill(['photo_path' => $path])->saveQuietly();
    }

    private function deletePhoto(Employee $employee): void
    {
        if ($employee->photo_path) {
            Storage::disk(\App\Models\ArchiveDocument::DISK)->delete($employee->photo_path);
            $employee->forceFill(['photo_path' => null])->saveQuietly();
        }
    }

    public function sync()
    {
        ['created' => $c, 'updated' => $u] = ArchiveRosterSync::run();

        return back()->with('success', $c || $u ? "Synced from TSA Management — {$c} added, {$u} updated." : 'Already in sync with TSA Management.');
    }

    public function store(Request $request)
    {
        $employee = Employee::create($this->validated($request));
        $this->savePhotoFromRequest($request, $employee);
        ActivityLogger::log('archive.employee.created', $employee, "Added {$employee->full_name} to the people directory");

        return back()->with('success', "{$employee->full_name} added.");
    }

    public function update(Request $request, Employee $employee)
    {
        $employee->update($this->validated($request, $employee));
        $this->savePhotoFromRequest($request, $employee);
        ActivityLogger::log('archive.employee.updated', $employee, "Updated {$employee->full_name}'s profile");

        return back()->with('success', "{$employee->full_name} updated.");
    }

    /** Inline edit of one Manpower table cell (People page, 2026-10-21). */
    public function updateManpower(Request $request, Employee $employee)
    {
        $rules = [
            'birthday'          => ['nullable', 'date'],
            'hired_at'          => ['nullable', 'date'],
            'left_at'           => ['nullable', 'date'],
            'employment_status' => ['nullable', Rule::in(array_keys(Employee::EMPLOYMENT_STATUSES))],
            'shift_schedule'    => ['nullable', Rule::in(array_keys(Employee::SHIFT_SCHEDULES))],
            'manual_status'     => ['nullable', 'string', 'max:160'],
        ];
        $field = $request->input('field');
        abort_unless(array_key_exists($field, $rules), 422);
        $value = $request->validate(['value' => $rules[$field]])['value'] ?? null;
        $value = is_string($value) && trim($value) === '' ? null : $value;

        $changes = [$field => $value];
        // Resigned / Terminated moves the person to Former TSAs; any other
        // status brings a former person back.
        if ($field === 'employment_status' && $value !== null) {
            $isFormer = Employee::EMPLOYMENT_STATUSES[$value]['former'];
            if ($isFormer && $employee->status !== 'resigned') {
                $changes['status'] = 'resigned';
            } elseif (! $isFormer && $employee->status === 'resigned') {
                $changes['status'] = 'active';
            }
        }
        $old = $employee->{$field};
        $employee->update($changes);
        $label = ['birthday' => 'birthday', 'hired_at' => 'hiring date', 'left_at' => 'last day', 'employment_status' => 'status', 'shift_schedule' => 'shift schedule', 'manual_status' => 'manual status'][$field];
        $show = fn ($v) => $v instanceof \DateTimeInterface ? $v->format('M j, Y') : (Employee::EMPLOYMENT_STATUSES[$v]['label'] ?? Employee::SHIFT_SCHEDULES[$v] ?? ($v ?: '—'));
        ActivityLogger::log('archive.employee.updated', $employee, "{$employee->full_name}: {$label} {$show($old)} → {$show($employee->{$field})}");

        return response()->json(['ok' => true, 'moved' => isset($changes['status'])]);
    }

    public function destroy(Employee $employee)
    {
        $name = $employee->full_name;
        $employee->delete();
        ActivityLogger::log('archive.employee.deleted', null, "Permanently deleted {$name} from the people directory");

        return redirect()->route('archive.records', ['tab' => 'people'])->with('success', "{$name} permanently deleted.");
    }

    private function validated(Request $request, ?Employee $employee = null): array
    {
        $data = $request->validate([
            'full_name'     => ['required', 'string', 'max:120'],
            'position'      => ['nullable', 'string', 'max:120'],
            'email'         => ['nullable', 'email', 'max:160'],
            'phone'         => ['nullable', 'string', 'max:40'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'manager_id'    => ['nullable', 'integer', 'exists:employees,id'],
            'user_id'       => ['nullable', 'integer', 'exists:users,id'],
            'tsa_shift_id'  => ['nullable', 'integer', 'exists:tsa_shifts,id'],
            'status'        => ['required', Rule::in(array_keys(Employee::STATUSES))],
            'hired_at'      => ['nullable', 'date'],
            'left_at'       => ['nullable', 'date', 'after_or_equal:hired_at'],
            'notes'         => ['nullable', 'string', 'max:5000'],
            'photo'         => ['nullable', ...self::photoRules()],
            'remove_photo'  => ['nullable', 'boolean'],
        ], self::photoMessages());
        unset($data['photo'], $data['remove_photo']);

        if ($employee && (int) ($data['manager_id'] ?? 0) === $employee->id) {
            throw ValidationException::withMessages(['manager_id' => 'A person cannot report to themselves.']);
        }
        if ($data['status'] === 'resigned' && empty($data['left_at'])) {
            $data['left_at'] = $employee?->left_at?->toDateString() ?? today()->toDateString();
        }
        if ($data['status'] !== 'resigned') {
            $data['left_at'] = null;
        }

        return $data;
    }
}
