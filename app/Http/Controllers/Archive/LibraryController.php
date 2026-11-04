<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\ArchiveDocument;
use App\Models\Department;
use App\Models\LibraryItem;
use App\Models\ProcessSubfolder;
use App\Models\Product;
use App\Support\ActivityLogger;
use App\Support\DocumentedProcess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Script & product library — every saved edit becomes a new version. */
class LibraryController extends Controller
{
    /** TSD Documented Process home: the Drive-matching folders, or search results. */
    public function index(Request $request)
    {
        $q = trim((string) $request->input('q', ''));

        $pageCounts = LibraryItem::whereNull('archived_at')->selectRaw('folder, count(*) as c, max(updated_at) as last')->groupBy('folder')->get()->keyBy('folder');
        $fileCounts = ArchiveDocument::whereNotNull('process_folder')->selectRaw('process_folder, count(*) as c, max(updated_at) as last')->groupBy('process_folder')->get()->keyBy('process_folder');

        $folderCounts = ProcessSubfolder::selectRaw('folder, count(*) as c')->groupBy('folder')->pluck('c', 'folder');

        $folders = collect(DocumentedProcess::FOLDERS)->map(function ($meta, $slug) use ($pageCounts, $fileCounts, $folderCounts) {
            $last = collect([$pageCounts[$slug]->last ?? null, $fileCounts[$slug]->last ?? null])->filter()->max();

            return [
                'slug' => $slug, 'label' => $meta[0], 'tint' => $meta[1], 'icon' => $meta[2],
                'pages' => (int) ($pageCounts[$slug]->c ?? 0), 'files' => (int) ($fileCounts[$slug]->c ?? 0),
                'folders' => (int) ($folderCounts[$slug] ?? 0),
                'updated' => $last ? \Illuminate\Support\Carbon::parse($last) : null,
            ];
        });

        $results = null;
        if ($q !== '') {
            $results = [
                'pages' => LibraryItem::whereNull('archived_at')
                    ->where(fn ($w) => $w->where('title', 'like', "%{$q}%")->orWhere('body', 'like', "%{$q}%"))
                    ->orderBy('title')->limit(50)->get(),
                'files' => ArchiveDocument::with('processSubfolder')->whereNotNull('process_folder')
                    ->where(fn ($w) => $w->where('title', 'like', "%{$q}%")->orWhere('original_name', 'like', "%{$q}%")->orWhere('tags', 'like', "%{$q}%"))
                    ->latest()->limit(50)->get(),
            ];
        }

        return view('archive.library', compact('folders', 'q', 'results'));
    }

    /** One folder (or one of its subfolders): its subfolders, uploaded
     *  files, and — at the top level only — its written pages. */
    public function folder(string $folder, ?ProcessSubfolder $subfolder = null)
    {
        abort_unless(DocumentedProcess::exists($folder), 404);
        abort_if($subfolder && $subfolder->folder !== $folder, 404);

        return view('archive.library-folder', [
            'folder' => $folder,
            'meta' => DocumentedProcess::FOLDERS[$folder],
            'subfolder' => $subfolder,
            'trail' => $subfolder?->trail() ?? collect(),
            'subfolders' => ProcessSubfolder::where('folder', $folder)->where('parent_id', $subfolder?->id)
                ->withCount(['children', 'documents'])->orderBy('name')->get(),
            'files' => ArchiveDocument::with('uploader')->where('process_folder', $folder)
                ->where('process_subfolder_id', $subfolder?->id)->latest()->get(),
            'pages' => $subfolder ? collect() : LibraryItem::with(['product', 'department', 'editor'])->whereNull('archived_at')->where('folder', $folder)->orderBy('title')->get(),
            'teams' => Department::active()->orderBy('name')->get(['id', 'name']),
            'products' => Product::orderBy('sort_order')->get(['id', 'display_name']),
            // for the shared upload modal
            'departments' => Department::active()->orderBy('name')->get(['id', 'name']),
            'employees' => \App\Models\Employee::current()->orderBy('full_name')->get(['id', 'full_name']),
        ]);
    }

    public function storeSubfolder(Request $request, string $folder)
    {
        abort_unless(DocumentedProcess::exists($folder), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', Rule::exists('process_subfolders', 'id')->where('folder', $folder)],
        ]);
        $name = trim($data['name']);
        if (ProcessSubfolder::where('folder', $folder)->where('parent_id', $data['parent_id'] ?? null)->where('name', $name)->exists()) {
            return back()->with('error', "There's already a folder named \"{$name}\" here.");
        }
        $sub = ProcessSubfolder::create(['folder' => $folder, 'parent_id' => $data['parent_id'] ?? null, 'name' => $name, 'created_by' => auth()->id()]);
        ActivityLogger::log('archive.library.folder_created', $sub, 'Created folder "' . DocumentedProcess::label($folder) . ' / ' . $sub->path() . '"');

        return back()->with('success', "Folder \"{$name}\" created.");
    }

    public function renameSubfolder(Request $request, ProcessSubfolder $subfolder)
    {
        $name = trim($request->validate(['name' => ['required', 'string', 'max:120']])['name']);
        if (ProcessSubfolder::where('folder', $subfolder->folder)->where('parent_id', $subfolder->parent_id)->where('name', $name)->whereKeyNot($subfolder->id)->exists()) {
            return back()->with('error', "There's already a folder named \"{$name}\" here.");
        }
        $old = $subfolder->name;
        $subfolder->update(['name' => $name]);
        ActivityLogger::log('archive.library.folder_renamed', $subfolder, "Renamed folder \"{$old}\" to \"{$name}\"");

        return back()->with('success', 'Folder renamed.');
    }

    /** Only an empty subfolder can be deleted — nothing is ever lost by accident. */
    public function destroySubfolder(ProcessSubfolder $subfolder)
    {
        if ($subfolder->children()->exists() || $subfolder->documents()->exists()) {
            return back()->with('error', "\"{$subfolder->name}\" isn't empty — move or delete what's inside first.");
        }
        $parent = $subfolder->parent_id ? ProcessSubfolder::find($subfolder->parent_id) : null;
        $subfolder->delete();
        ActivityLogger::log('archive.library.folder_deleted', null, "Deleted folder \"{$subfolder->name}\" from " . DocumentedProcess::label($subfolder->folder));

        return redirect($parent ? route('archive.library.subfolder', [$subfolder->folder, $parent]) : route('archive.library.folder', $subfolder->folder))
            ->with('success', "Folder \"{$subfolder->name}\" deleted.");
    }

    public function show(LibraryItem $item)
    {
        $item->load(['product', 'department', 'editor', 'versions.user']);

        return view('archive.library-item', [
            'item' => $item,
            'teams' => Department::active()->orderBy('name')->get(['id', 'name']),
            'products' => Product::orderBy('sort_order')->get(['id', 'display_name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $item = LibraryItem::create(collect($data)->except('change_note')->all() + ['version' => 1, 'updated_by' => auth()->id()]);
        $item->versions()->create(['version' => 1, 'title' => $item->title, 'body' => $item->body, 'change_note' => $data['change_note'] ?? 'First version', 'user_id' => auth()->id()]);
        ActivityLogger::log('archive.library.created', $item, 'Added ' . LibraryItem::KINDS[$item->kind]['label'] . " \"{$item->title}\"");

        return redirect()->route('archive.library.show', $item)->with('success', 'Page saved.');
    }

    public function update(Request $request, LibraryItem $item)
    {
        $data = $this->validated($request);
        $contentChanged = $data['title'] !== $item->title || $data['body'] !== $item->body;

        $item->fill(collect($data)->except('change_note')->all());
        if ($contentChanged) {
            $item->version++;
            $item->updated_by = auth()->id();
        }
        $item->save();

        if ($contentChanged) {
            $item->versions()->create(['version' => $item->version, 'title' => $item->title, 'body' => $item->body, 'change_note' => $data['change_note'] ?? null, 'user_id' => auth()->id()]);
            ActivityLogger::log('archive.library.updated', $item, "Updated \"{$item->title}\" to v{$item->version}");
        }

        return back()->with('success', $contentChanged ? "Saved as version {$item->version}." : 'Details updated.');
    }

    public function restore(LibraryItem $item, int $version)
    {
        $old = $item->versions()->where('version', $version)->firstOrFail();
        $item->update(['title' => $old->title, 'body' => $old->body, 'version' => $item->version + 1, 'updated_by' => auth()->id()]);
        $item->versions()->create(['version' => $item->version, 'title' => $old->title, 'body' => $old->body, 'change_note' => "Restored from v{$version}", 'user_id' => auth()->id()]);
        ActivityLogger::log('archive.library.restored', $item, "Restored \"{$item->title}\" from v{$version}");

        return back()->with('success', "Restored v{$version} as version {$item->version}.");
    }

    public function archive(LibraryItem $item)
    {
        $item->update(['archived_at' => now()]);
        ActivityLogger::log('archive.library.archived', $item, "Archived \"{$item->title}\"");

        return redirect()->route('archive.library.folder', $item->folder ?? 'training-process')->with('success', "\"{$item->title}\" archived — find it in Archived Records → Documented Process.");
    }

    public function unarchive(LibraryItem $item)
    {
        $item->update(['archived_at' => null]);
        ActivityLogger::log('archive.library.unarchived', $item, "Restored \"{$item->title}\" to the library");

        return back()->with('success', "\"{$item->title}\" is back in the library.");
    }

    /** Permanent delete — only from Archived Records, only once archived. */
    public function destroy(LibraryItem $item)
    {
        abort_unless($item->archived_at, 404);
        $title = $item->title;
        $item->delete(); // versions cascade
        ActivityLogger::log('archive.library.deleted', null, "Permanently deleted library item \"{$title}\"");

        return back()->with('success', "\"{$title}\" permanently deleted.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'kind'          => ['required', Rule::in(array_keys(LibraryItem::KINDS))],
            'folder'        => ['required', Rule::in(array_keys(DocumentedProcess::FOLDERS))],
            'title'         => ['required', 'string', 'max:200'],
            'body'          => ['required', 'string', 'max:50000'],
            'product_id'    => ['nullable', 'integer', 'exists:products,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'change_note'   => ['nullable', 'string', 'max:255'],
        ]);
    }
}
