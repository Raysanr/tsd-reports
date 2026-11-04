<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\ArchiveDocument;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Incident;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Incidents & NTEs — disciplinary records with attached files. The reply
 *  workflow (awaiting reply / replied / resolved, reply due) was removed
 *  (explicit request, 2026-10-22); those columns stay in the table unused. */
class IncidentController extends Controller
{
    public function index(Request $request)
    {
        $teamId = $request->integer('team') ?: null;
        $personId = $request->integer('person') ?: null;
        $type = array_key_exists((string) $request->input('type'), Incident::TYPES) ? $request->input('type') : null;

        $incidents = Incident::with(['employee.department', 'author', 'attachments'])
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($personId, fn ($q) => $q->where('employee_id', $personId))
            ->when($teamId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $teamId)))
            ->orderByDesc('incident_date')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return view('archive.incidents', [
            'incidents' => $incidents,
            'stats' => [
                'month' => Incident::where('incident_date', '>=', now()->startOfMonth()->toDateString())->count(),
                'ntes'  => Incident::where('type', 'nte')->where('incident_date', '>=', now()->startOfMonth()->toDateString())->count(),
                'year'  => Incident::where('incident_date', '>=', now()->startOfYear()->toDateString())->count(),
                'files' => ArchiveDocument::whereNotNull('incident_id')->count(),
            ],
            'teams' => Department::active()->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::orderBy('full_name')->get(['id', 'full_name']),
            'filters' => ['type' => $type, 'team' => $teamId, 'person' => $personId],
        ]);
    }

    public function store(Request $request)
    {
        $incident = Incident::create($this->validated($request) + ['status' => 'open', 'created_by' => auth()->id()]);
        $incident->load('employee');
        $files = $this->attachFiles($request, $incident);
        ActivityLogger::log('archive.incident.created', $incident, 'Recorded ' . Incident::TYPES[$incident->type] . " for {$incident->employee->full_name}: {$incident->title}" . ($files ? " ({$files} " . Str::plural('file', $files) . ')' : ''));

        return back()->with('success', 'Incident recorded.');
    }

    public function update(Request $request, Incident $incident)
    {
        $incident->update($this->validated($request));
        $files = $this->attachFiles($request, $incident);
        ActivityLogger::log('archive.incident.updated', $incident, "Updated incident for {$incident->employee->full_name}: {$incident->title}" . ($files ? " (+{$files} " . Str::plural('file', $files) . ')' : ''));

        return back()->with('success', 'Incident updated.');
    }

    public function destroy(Incident $incident)
    {
        $label = "{$incident->employee->full_name}: {$incident->title}";
        // Its files go to the trash (restorable from Archived Records), not lost.
        $incident->attachments()->get()->each->delete();
        $incident->delete();
        ActivityLogger::log('archive.incident.deleted', null, "Deleted incident — {$label}");

        return back()->with('success', 'Incident deleted.');
    }

    /** Store uploaded files as archive documents linked to the incident. */
    private function attachFiles(Request $request, Incident $incident): int
    {
        $files = $request->file('files', []);
        foreach ($files as $file) {
            $ext = strtolower($file->getClientOriginalExtension());
            $doc = ArchiveDocument::create([
                'title'         => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'category'      => 'evaluation',
                'incident_id'   => $incident->id,
                'employee_id'   => $incident->employee_id,
                'uploaded_by'   => auth()->id(),
                'path'          => $file->storeAs(now()->format('Y/m'), Str::uuid() . '.' . $ext, ArchiveDocument::DISK),
                'original_name' => $file->getClientOriginalName(),
                'mime_type'     => $file->getMimeType(),
                'size'          => $file->getSize(),
            ]);
            ActivityLogger::log('archive.document.uploaded', $doc, "Attached \"{$doc->original_name}\" to incident: {$incident->title}");
        }

        return count($files);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'employee_id'   => ['required', 'integer', 'exists:employees,id'],
            'type'          => ['required', Rule::in(array_keys(Incident::TYPES))],
            'incident_date' => ['required', 'date', 'before_or_equal:today'],
            'title'         => ['required', 'string', 'max:200'],
            'description'   => ['nullable', 'string', 'max:10000'],
            'decision'      => ['nullable', 'string', 'max:10000'],
            'files'         => ['nullable', 'array', 'max:10'],
            'files.*'       => ['file', 'max:' . ArchiveDocument::MAX_KB, 'extensions:' . implode(',', ArchiveDocument::ALLOWED_EXTENSIONS)],
        ], [
            'files.*.extensions' => 'Allowed file types: ' . implode(', ', ArchiveDocument::ALLOWED_EXTENSIONS) . '.',
            'files.*.max' => 'Each file must be 20 MB or smaller.',
        ]);
        unset($data['files']);

        return $data;
    }
}
