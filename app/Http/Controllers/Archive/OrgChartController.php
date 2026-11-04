<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OrgLink;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrgChartController extends Controller
{
    public function index(Request $request)
    {
        $view = $request->input('view') === 'people' ? 'people' : 'departments';

        $data = $view === 'people' ? $this->peopleTree() : $this->departmentTree();
        $visible = $data['nodes']->map(fn ($n) => $n['model']->id)->all();
        $links = OrgLink::where('type', $view === 'people' ? 'employee' : 'department')
            ->whereIn('parent_id', $visible)->whereIn('child_id', $visible)
            ->get(['kind', 'parent_id', 'child_id']);
        $data['extraLinks'] = $links->where('kind', 'child')->map(fn ($l) => ['parent' => (string) $l->parent_id, 'child' => (string) $l->child_id])->values();
        $data['sideLinks'] = $links->where('kind', 'side')->map(fn ($l) => ['a' => (string) $l->parent_id, 'b' => (string) $l->child_id])->values();

        return view('archive.org-chart', array_merge(
            ['view' => $view, 'totalPeople' => Employee::current()->count()],
            $data,
        ));
    }

    /**
     * Draw a line: put `child` under `parent`. If the child has no main
     * parent yet this becomes it; otherwise it's added as an extra
     * ("also reports to") line. Rejects loops across ALL lines.
     */
    public function connect(Request $request): JsonResponse
    {
        if ($request->input('mode') === 'side') {
            return $this->connectSide($request);
        }

        ['type' => $type, 'parent_id' => $parentId, 'child_id' => $childId] = $request->validate([
            'type'      => ['required', 'in:department,employee'],
            'parent_id' => ['required', 'integer', 'different:child_id'],
            'child_id'  => ['required', 'integer'],
        ]);
        [$child, $parent] = [$this->node($type, $childId), $this->node($type, $parentId)];
        $col = $type === 'department' ? 'parent_id' : 'manager_id';

        if ($this->ancestorIds($type, $parent->id)->contains($child->id)) {
            return response()->json(['message' => "{$this->label($parent)} is already under {$this->label($child)} — that would make a loop."], 422);
        }
        if (($child->{$col} !== null && (int) $child->{$col} === $parent->id) || OrgLink::where(compact('type'))->where('kind', 'child')->where('parent_id', $parent->id)->where('child_id', $child->id)->exists()) {
            return response()->json(['message' => 'Already connected.', 'kind' => 'none']);
        }

        if ($child->{$col} === null) {
            $child->update([$col => $parent->id]);
            $kind = 'main';
        } else {
            OrgLink::create(['type' => $type, 'kind' => 'child', 'parent_id' => $parent->id, 'child_id' => $child->id]);
            $kind = 'extra';
        }
        $message = $type === 'employee'
            ? "{$this->label($child)} " . ($kind === 'main' ? 'now reports to' : 'also reports to') . " {$this->label($parent)}"
            : "\"{$this->label($child)}\" " . ($kind === 'main' ? 'moved under' : 'also linked under') . " \"{$this->label($parent)}\"";
        ActivityLogger::log("archive.{$type}.connected", $child, $message);

        return response()->json(['message' => $message, 'kind' => $kind]);
    }

    /** Remove one line. Removing the main line promotes an extra one, if any. */
    public function disconnect(Request $request): JsonResponse
    {
        if ($request->input('mode') === 'side') {
            return $this->disconnectSide($request);
        }

        ['type' => $type, 'parent_id' => $parentId, 'child_id' => $childId] = $request->validate([
            'type'      => ['required', 'in:department,employee'],
            'parent_id' => ['required', 'integer'],
            'child_id'  => ['required', 'integer'],
        ]);
        $child = $this->node($type, $childId);
        $col = $type === 'department' ? 'parent_id' : 'manager_id';
        $promoted = null;

        if ($child->{$col} !== null && (int) $child->{$col} === $parentId) {
            $next = OrgLink::where(compact('type'))->where('kind', 'child')->where('child_id', $child->id)->oldest('id')->first();
            $child->update([$col => $next?->parent_id]);
            $promoted = $next?->parent_id;
            $next?->delete();
        } else {
            OrgLink::where(compact('type'))->where('kind', 'child')->where('parent_id', $parentId)->where('child_id', $child->id)->delete();
        }
        $parentLabel = $this->label($this->node($type, $parentId));
        ActivityLogger::log("archive.{$type}.disconnected", $child, "Removed line {$parentLabel} → {$this->label($child)}");

        return response()->json(['message' => "Removed the line from {$parentLabel}.", 'promoted' => $promoted]);
    }

    /** Side-to-side link between two cards (left/right dots). Not part of
     *  the reporting tree, so it can join ANY two cards — including a card
     *  and the one above it — without creating a loop. */
    private function connectSide(Request $request): JsonResponse
    {
        ['type' => $type, 'a' => $a, 'b' => $b] = $request->validate([
            'type' => ['required', 'in:department,employee'],
            'a'    => ['required', 'integer', 'different:b'],
            'b'    => ['required', 'integer'],
        ]);
        [$x, $y] = [$this->node($type, $a), $this->node($type, $b)];
        [$lo, $hi] = $x->id < $y->id ? [$x->id, $y->id] : [$y->id, $x->id];

        $link = OrgLink::firstOrCreate(['type' => $type, 'kind' => 'side', 'parent_id' => $lo, 'child_id' => $hi]);
        if (! $link->wasRecentlyCreated) {
            return response()->json(['message' => 'Already linked.', 'kind' => 'none']);
        }
        $message = "Linked {$this->label($x)} ↔ {$this->label($y)}";
        ActivityLogger::log("archive.{$type}.linked", $x, $message);

        return response()->json(['message' => $message, 'kind' => 'side']);
    }

    private function disconnectSide(Request $request): JsonResponse
    {
        ['type' => $type, 'a' => $a, 'b' => $b] = $request->validate([
            'type' => ['required', 'in:department,employee'],
            'a'    => ['required', 'integer'],
            'b'    => ['required', 'integer'],
        ]);
        [$lo, $hi] = $a < $b ? [$a, $b] : [$b, $a];
        OrgLink::where(['type' => $type, 'kind' => 'side', 'parent_id' => $lo, 'child_id' => $hi])->delete();
        $message = "Removed the side link {$this->label($this->node($type, $a))} ↔ {$this->label($this->node($type, $b))}";
        ActivityLogger::log("archive.{$type}.unlinked", null, $message);

        return response()->json(['message' => $message . '.']);
    }

    private function node(string $type, int $id): Department|Employee
    {
        return $type === 'department' ? Department::findOrFail($id) : Employee::findOrFail($id);
    }

    private function label(Department|Employee $n): string
    {
        return $n instanceof Department ? $n->name : $n->full_name;
    }

    /** Everyone above $id through main AND extra lines (bounded walk). */
    private function ancestorIds(string $type, int $id): \Illuminate\Support\Collection
    {
        $col = $type === 'department' ? 'parent_id' : 'manager_id';
        $mainParents = ($type === 'department' ? Department::query() : Employee::query())->whereNotNull($col)->pluck($col, 'id');
        $extra = OrgLink::where(compact('type'))->where('kind', 'child')->get()->groupBy('child_id')->map(fn ($g) => $g->pluck('parent_id'));

        $seen = collect([$id]);
        $frontier = [$id];
        while ($frontier && $seen->count() < 5000) {
            $next = [];
            foreach ($frontier as $n) {
                foreach (collect([$mainParents[$n] ?? null])->merge($extra[$n] ?? [])->filter() as $p) {
                    if (! $seen->contains($p)) {
                        $seen->push($p);
                        $next[] = $p;
                    }
                }
            }
            $frontier = $next;
        }

        return $seen;
    }

    /** Save where a card was dropped on the canvas. */
    public function position(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:department,employee'],
            'id'   => ['required', 'integer'],
            'x'    => ['required', 'integer', 'min:0', 'max:100000'],
            'y'    => ['required', 'integer', 'min:0', 'max:100000'],
        ]);
        $model = $data['type'] === 'department' ? Department::findOrFail($data['id']) : Employee::findOrFail($data['id']);
        $model->forceFill(['chart_x' => $data['x'], 'chart_y' => $data['y']])->saveQuietly();

        return response()->json(['ok' => true]);
    }

    /** "Auto-arrange": forget saved positions for one view. */
    public function resetLayout(Request $request)
    {
        $view = $request->input('view') === 'people' ? 'people' : 'departments';
        ($view === 'people' ? Employee::query() : Department::query())->update(['chart_x' => null, 'chart_y' => null]);
        ActivityLogger::log('archive.orgchart.reset', null, 'Auto-arranged the ' . ($view === 'people' ? 'People' : 'Teams') . ' org chart');

        return redirect()->route('archive.org-chart', ['view' => $view])->with('success', 'Chart auto-arranged.');
    }

    private function departmentTree(): array
    {
        $all = Department::active()
            ->with(['head', 'employees' => fn ($q) => $q->current()->orderBy('full_name')])
            ->orderBy('sort_order')->orderBy('name')->get();
        $ids = $all->pluck('id')->all();

        return [
            'nodes' => $all->map(fn ($d) => [
                'model' => $d,
                // a parent that was archived doesn't count — the card becomes a root
                'parent' => in_array($d->parent_id, $ids, true) ? $d->parent_id : null,
            ]),
            'nodeCount' => $all->count(),
            'unassigned' => Employee::current()->whereNull('department_id')->orderBy('full_name')->get(),
        ];
    }

    private function peopleTree(): array
    {
        $all = Employee::current()->with('department')->orderBy('full_name')->get();
        $ids = $all->pluck('id')->all();
        $parents = $all->mapWithKeys(fn ($e) => [$e->id => in_array($e->manager_id, $ids, true) ? $e->manager_id : null]);

        // depth (0 = top) colors the card band; guard against bad-data loops
        $depth = function (int $id) use ($parents) {
            $d = 0;
            for ($p = $parents[$id]; $p !== null && $d < 50; $p = $parents[$p] ?? null) {
                $d++;
            }

            return $d;
        };

        return [
            'nodes' => $all->map(fn ($e) => ['model' => $e, 'parent' => $parents[$e->id], 'depth' => $depth($e->id)]),
            'nodeCount' => $all->count(),
            'unassigned' => collect(),
        ];
    }
}
