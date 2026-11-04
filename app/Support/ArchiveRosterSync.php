<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Scopes\TsaRosterScope;
use App\Models\TsaShift;
use App\Models\User;

/**
 * Keeps the Archiving System's People list in step with TSA Management:
 * every TSA roster row gets a linked Employee (in the Team matching its
 * selling team), and a TSA removed from the roster is marked Resigned.
 *
 * Idempotent and conservative: it only FILLS empty links, never overwrites
 * a name/position/team someone edited by hand, and never auto-reinstates a
 * resigned person.
 */
class ArchiveRosterSync
{
    /** @return array{created: int, updated: int} */
    public static function run(): array
    {
        $teamIds = self::ensureTeams();
        $usersByTsa = User::whereNotNull('tsa_id')->pluck('id', 'tsa_id');
        $created = $updated = 0;

        $shifts = TsaShift::withoutGlobalScope(TsaRosterScope::class)->orderBy('sort_order')->get();
        $existing = Employee::whereNotNull('tsa_shift_id')->get()->keyBy('tsa_shift_id');

        foreach ($shifts as $shift) {
            $slug = $shift->team ? Teams::slugForOrderTeam($shift->team) : null;
            $teamId = $slug ? ($teamIds[$slug] ?? null) : null;
            $removed = $shift->deleted_at !== null;
            $employee = $existing->get($shift->id);

            if (! $employee) {
                Employee::create([
                    'tsa_shift_id'  => $shift->id,
                    'user_id'       => $usersByTsa[$shift->id] ?? null,
                    'department_id' => $teamId,
                    'full_name'     => $shift->display_name ?: $shift->tsa_key,
                    'position'      => 'Telesales Agent',
                    'status'        => $removed ? 'resigned' : 'active',
                    'hired_at'      => $shift->created_at?->toDateString(),
                    'left_at'       => $removed ? $shift->deleted_at->toDateString() : null,
                ]);
                $created++;
                continue;
            }

            $changes = array_filter([
                'department_id' => $employee->department_id === null ? $teamId : null,
                'user_id'       => $employee->user_id === null ? ($usersByTsa[$shift->id] ?? null) : null,
            ]);
            if ($removed && $employee->status !== 'resigned') {
                $changes += ['status' => 'resigned', 'left_at' => $shift->deleted_at->toDateString()];
            }
            if ($changes) {
                $employee->update($changes);
                $updated++;
            }
        }

        if ($created || $updated) {
            ActivityLogger::log('archive.roster.synced', null, "Synced people from TSA Management ({$created} added, {$updated} updated)");
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /** One Team per configured selling team, created on first sync.
     *  @return array<string, int> slug => departments.id */
    public static function ensureTeams(): array
    {
        $colors = ['amber', 'emerald', 'sky', 'violet', 'rose'];
        $ids = [];
        $i = 0;
        foreach (Teams::config() as $slug => $team) {
            $ids[$slug] = Department::firstOrCreate(
                ['team_slug' => $slug],
                ['name' => $team['name'], 'color' => $colors[$i % count($colors)], 'sort_order' => $i],
            )->id;
            $i++;
        }

        return $ids;
    }
}
