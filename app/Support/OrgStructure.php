<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * The Archiving System's self-building Org Chart (2026-11-20 redesign).
 *
 * Nobody draws lines any more: the chart is derived from Teams and People.
 *   team  →  its head  →  the head's members
 * A person's place, in priority order:
 *   1. head of an active team        → directly under that team
 *   2. "Reports to" someone current  → under that person
 *   3. in a team that has a head     → under the head
 *   4. in a team without a head      → directly under the team
 *   5. no team                       → the "Not in a team yet" list
 * A sub-team sits under its parent team's head (or the parent team itself
 * when it has no head). Loops in bad data are broken, never followed.
 */
class OrgStructure
{
    /**
     * @return array{roots: array, unassigned: Collection, teams: Collection, people: Collection}
     *   roots: nested nodes ['type' => 'team'|'person', 'model' => …, 'children' => [...], 'isHead' => bool]
     */
    public static function build(): array
    {
        $teams = Department::active()->with('head')
            ->withCount(['employees' => fn ($q) => $q->current()])
            ->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');
        $people = Employee::current()->with('department')->orderBy('full_name')->get()->keyBy('id');

        // person id => team id they head. Heading several teams: the
        // top-most one wins (head of TEAM PARTNERS and of its sub-team
        // PEAK.CO sits under TEAM PARTNERS, above all its sub-teams).
        $depth = function (int $id) use ($teams) {
            $d = 0;
            for ($p = $teams[$id]->parent_id; $p && $teams->has($p) && $d < 50; $p = $teams[$p]->parent_id) {
                $d++;
            }

            return $d;
        };
        $headOf = [];
        foreach ($teams as $t) {
            $h = $t->head_employee_id;
            if ($h && $people->has($h) && (! isset($headOf[$h]) || $depth($t->id) < $depth($headOf[$h]))) {
                $headOf[$h] = $t->id;
            }
        }
        $teamHead = fn (int $teamId) => ($h = $teams[$teamId]->head_employee_id ?? null) && ($headOf[$h] ?? null) === $teamId ? $h : null;

        // parent keys: "t:<id>" / "p:<id>" / null (root) / "none" (unassigned)
        $parent = [];
        foreach ($people as $p) {
            $parent['p:' . $p->id] = self::personParent($p, $people, $teams, $headOf, $teamHead);
        }
        foreach ($teams as $t) {
            $up = $t->parent_id && $teams->has($t->parent_id) ? $t->parent_id : null;
            $parent['t:' . $t->id] = $up === null ? null : (($h = $teamHead($up)) ? 'p:' . $h : 't:' . $up);
        }

        // break loops: walk up from every node; a node that reaches itself
        // falls back to its team (people) or the top (teams)
        foreach (array_keys($parent) as $key) {
            $seen = [$key => true];
            for ($k = $parent[$key]; $k !== null && $k !== 'none'; $k = $parent[$k] ?? null) {
                if (isset($seen[$k])) {
                    $parent[$key] = str_starts_with($key, 'p:') ? self::teamFallback($people[(int) substr($key, 2)], $teams) : null;
                    break;
                }
                $seen[$k] = true;
            }
        }

        $children = [];
        foreach ($parent as $key => $up) {
            if ($up !== 'none') {
                $children[$up ?? 'root'][] = $key;
            }
        }

        $make = function (string $key) use (&$make, $children, $teams, $people, $headOf) {
            $isTeam = str_starts_with($key, 't:');
            $model = $isTeam ? $teams[(int) substr($key, 2)] : $people[(int) substr($key, 2)];
            $kids = array_map($make, $children[$key] ?? []);
            // heads first, then sub-teams, then people with their own reports, then the rest by name
            usort($kids, fn ($a, $b) => [! $a['isHead'], $a['type'] === 'person', empty($a['children']), $a['sortName']]
                <=> [! $b['isHead'], $b['type'] === 'person', empty($b['children']), $b['sortName']]);

            return [
                'type' => $isTeam ? 'team' : 'person',
                'model' => $model,
                'isHead' => ! $isTeam && isset($headOf[$model->id]),
                'headOfTeam' => ! $isTeam && isset($headOf[$model->id]) ? $teams[$headOf[$model->id]] : null,
                'sortName' => mb_strtolower($isTeam ? $model->name : $model->full_name),
                'children' => $kids,
            ];
        };

        // teams first (in their sort order), then any people at the top
        $rootKeys = collect($children['root'] ?? [])->sortBy(fn ($k) => str_starts_with($k, 't:')
            ? [0, $teams[(int) substr($k, 2)]->sort_order, mb_strtolower($teams[(int) substr($k, 2)]->name)]
            : [1, 0, mb_strtolower($people[(int) substr($k, 2)]->full_name)])->values();

        return [
            'roots' => $rootKeys->map($make)->all(),
            'unassigned' => $people->filter(fn ($p) => $parent['p:' . $p->id] === 'none')->values(),
            'teams' => $teams->values(),
            'people' => $people->values(),
        ];
    }

    private static function personParent(Employee $p, Collection $people, Collection $teams, array $headOf, \Closure $teamHead): ?string
    {
        if (isset($headOf[$p->id])) {
            return 't:' . $headOf[$p->id];
        }
        if ($p->manager_id && $p->manager_id !== $p->id && $people->has($p->manager_id)
            && ! self::headsAParentTeam($p->manager_id, $p->department_id, $teams, $headOf)) {
            return 'p:' . $p->manager_id;
        }

        return self::teamFallback($p, $teams, $teamHead);
    }

    /** Reporting to the head of a team ABOVE your own (Gretchen heads TEAM
     *  SH E-COM, Jhun is in its sub-team CLOSING): you're shown inside your
     *  own team, which already sits under that head (2026-11-20). */
    private static function headsAParentTeam(int $managerId, ?int $teamId, Collection $teams, array $headOf): bool
    {
        $managerTeam = $headOf[$managerId] ?? null;
        if ($managerTeam === null || ! $teamId || ! $teams->has($teamId)) {
            return false;
        }
        for ($t = $teams[$teamId]->parent_id, $i = 0; $t && $teams->has($t) && $i < 50; $t = $teams[$t]->parent_id, $i++) {
            if ($t === $managerTeam) {
                return true;
            }
        }

        return false;
    }

    private static function teamFallback(Employee $p, Collection $teams, ?\Closure $teamHead = null): string
    {
        if (! $p->department_id || ! $teams->has($p->department_id)) {
            return 'none';
        }
        $head = $teamHead ? $teamHead($p->department_id) : null;

        return $head && $head !== $p->id ? 'p:' . $head : 't:' . $p->department_id;
    }

    /**
     * The 3-step setup guide shown on Org Chart / Teams / People until done.
     * @return array{done: bool, steps: array<int, array{title: string, text: string, done: bool}>}
     */
    public static function setup(): array
    {
        $teams = Department::active()->withCount(['employees' => fn ($q) => $q->current()])->get(['id', 'head_employee_id']);
        $inTeam = Employee::current()->whereIn('department_id', $teams->pluck('id'))->count();
        $headless = $teams->filter(fn ($t) => $t->employees_count > 0 && ! $t->head_employee_id)->count();

        $steps = [
            ['title' => 'Create your teams', 'text' => $teams->isEmpty() ? 'e.g. Team Opening, Team Closing, QA.' : $teams->count() . ' ' . \Illuminate\Support\Str::plural('team', $teams->count()) . ' created.', 'done' => $teams->isNotEmpty()],
            ['title' => 'Add people to teams', 'text' => $inTeam ? "{$inTeam} " . \Illuminate\Support\Str::plural('person', $inTeam) . ' in a team.' : 'Add people, or import the TSAs from TSA Management.', 'done' => $inTeam > 0],
            ['title' => 'Pick a head for each team', 'text' => $headless ? "{$headless} " . \Illuminate\Support\Str::plural('team', $headless) . ' still without a head.' : 'Every team with people has a head.', 'done' => $teams->isNotEmpty() && $inTeam > 0 && $headless === 0],
        ];

        return ['done' => collect($steps)->every('done'), 'steps' => $steps];
    }
}
