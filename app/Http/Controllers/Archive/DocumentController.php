<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\ArchiveDocument;
use App\Models\Department;
use App\Models\Employee;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    private const PREVIEW_TYPES = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
    ];

    public function index(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $category = array_key_exists((string) $request->input('category'), ArchiveDocument::CATEGORIES) ? $request->input('category') : null;
        $departmentId = $request->integer('department') ?: null;
        $view = $request->input('view') === 'list' ? 'list' : 'grid';

        $documents = ArchiveDocument::with(['department', 'employee', 'uploader'])
            ->whereNull('process_folder') // Documented Process files have their own section
            ->whereNull('incident_id')    // so do files attached to an incident
            ->when($category, fn ($b) => $b->where('category', $category))
            ->when($departmentId, fn ($b) => $b->where('department_id', $departmentId))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('title', 'like', "%{$q}%")
                ->orWhere('original_name', 'like', "%{$q}%")->orWhere('tags', 'like', "%{$q}%")
                ->orWhere('description', 'like', "%{$q}%")))
            ->latest()->paginate(24)->withQueryString();

        return view('archive.documents', [
            'documents' => $documents,
            'categoryCounts' => ArchiveDocument::whereNull('process_folder')->whereNull('incident_id')->selectRaw('category, count(*) as c')->groupBy('category')->pluck('c', 'category'),
            'totalCount' => ArchiveDocument::whereNull('process_folder')->whereNull('incident_id')->count(),
            'departments' => Department::active()->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::current()->orderBy('full_name')->get(['id', 'full_name']),
            'filters' => ['q' => $q, 'category' => $category, 'department' => $departmentId],
            'view' => $view,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'files'         => ['required', 'array', 'min:1', 'max:10'],
            'files.*'       => ['file', 'max:' . ArchiveDocument::MAX_KB, 'extensions:' . implode(',', ArchiveDocument::ALLOWED_EXTENSIONS)],
            'title'         => ['nullable', 'string', 'max:160'],
            // Not asked for inside a Documented Process folder — the folder is the category.
            'category'      => ['required_without:process_folder', 'nullable', Rule::in(array_keys(ArchiveDocument::CATEGORIES))],
            'process_folder' => ['nullable', Rule::in(array_keys(\App\Support\DocumentedProcess::FOLDERS))],
            'process_subfolder_id' => ['nullable', 'integer', Rule::exists('process_subfolders', 'id')->where('folder', $request->input('process_folder'))],
            'description'   => ['nullable', 'string', 'max:2000'],
            'tags'          => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'employee_id'   => ['nullable', 'integer', 'exists:employees,id'],
            'expires_at'    => ['nullable', 'date'],
        ], [
            'files.*.extensions' => 'Allowed file types: ' . implode(', ', ArchiveDocument::ALLOWED_EXTENSIONS) . '.',
            'files.*.max' => 'Each file must be 20 MB or smaller.',
        ]);

        $data['category'] ??= 'sop'; // Documented Process uploads: the folder is the real grouping
        $files = $request->file('files');
        foreach ($files as $file) {
            $ext = strtolower($file->getClientOriginalExtension());
            $path = $file->storeAs(now()->format('Y/m'), Str::uuid() . '.' . $ext, ArchiveDocument::DISK);
            $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

            $doc = ArchiveDocument::create([
                // A typed title only makes sense for a single upload.
                'title'         => count($files) === 1 && ! empty($data['title']) ? $data['title'] : $name,
                'category'      => $data['category'],
                'process_folder' => $data['process_folder'] ?? null,
                'process_subfolder_id' => $data['process_subfolder_id'] ?? null,
                'description'   => $data['description'] ?? null,
                'tags'          => $this->normalizeTags($data['tags'] ?? null),
                'department_id' => $data['department_id'] ?? null,
                'employee_id'   => $data['employee_id'] ?? null,
                'expires_at'    => $data['expires_at'] ?? null,
                'uploaded_by'   => auth()->id(),
                'path'          => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type'     => $file->getMimeType(),
                'size'          => $file->getSize(),
            ]);
            ActivityLogger::log('archive.document.uploaded', $doc, "Uploaded \"{$doc->original_name}\"");
        }

        return back()->with('success', count($files) === 1 ? 'File uploaded.' : count($files) . ' files uploaded.');
    }

    public function update(Request $request, ArchiveDocument $document)
    {
        $data = $request->validate([
            'title'         => ['required', 'string', 'max:160'],
            // Not asked for inside a Documented Process folder — the folder is the category.
            'category'      => ['required_without:process_folder', 'nullable', Rule::in(array_keys(ArchiveDocument::CATEGORIES))],
            'process_folder' => ['nullable', Rule::in(array_keys(\App\Support\DocumentedProcess::FOLDERS))],
            'description'   => ['nullable', 'string', 'max:2000'],
            'tags'          => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'employee_id'   => ['nullable', 'integer', 'exists:employees,id'],
            'expires_at'    => ['nullable', 'date'],
        ]);
        $data['tags'] = $this->normalizeTags($data['tags'] ?? null);
        $data['category'] ??= $document->category;
        $document->update($data);
        ActivityLogger::log('archive.document.updated', $document, "Updated details of \"{$document->title}\"");

        return back()->with('success', 'Document updated.');
    }

    public function download(ArchiveDocument $document)
    {
        abort_unless(Storage::disk(ArchiveDocument::DISK)->exists($document->path), 404, 'File is missing from storage.');

        return Storage::disk(ArchiveDocument::DISK)->download($document->path, $document->original_name);
    }

    public function preview(ArchiveDocument $document)
    {
        abort_unless($document->isPreviewable(), 404);
        $disk = Storage::disk(ArchiveDocument::DISK);
        abort_unless($disk->exists($document->path), 404, 'File is missing from storage.');

        return response()->file($disk->path($document->path), [
            // From the extension allow-list, never the stored/sniffed mime —
            // a disguised HTML file must not render inline as text/html.
            'Content-Type' => self::PREVIEW_TYPES[$document->extension()],
            'Content-Disposition' => 'inline; filename="' . addslashes($document->original_name) . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(ArchiveDocument $document)
    {
        $document->delete();
        ActivityLogger::log('archive.document.trashed', $document, "Moved \"{$document->title}\" to trash");

        return back()->with('success', "\"{$document->title}\" moved to trash.");
    }

    public function restore(int $id)
    {
        $document = ArchiveDocument::onlyTrashed()->findOrFail($id);
        $document->restore();
        ActivityLogger::log('archive.document.restored', $document, "Restored \"{$document->title}\" from trash");

        return back()->with('success', "\"{$document->title}\" restored.");
    }

    public function forceDestroy(int $id)
    {
        $document = ArchiveDocument::onlyTrashed()->findOrFail($id);
        Storage::disk(ArchiveDocument::DISK)->delete($document->path);
        $title = $document->title;
        $document->forceDelete();
        ActivityLogger::log('archive.document.deleted', null, "Permanently deleted \"{$title}\"");

        return back()->with('success', "\"{$title}\" permanently deleted.");
    }

    private function normalizeTags(?string $tags): ?string
    {
        $list = array_unique(array_filter(array_map(fn ($t) => trim($t), explode(',', (string) $tags))));

        return $list ? implode(', ', $list) : null;
    }
}
