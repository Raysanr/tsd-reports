<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Employee;
use App\Services\PartnerFeed;
use Illuminate\Support\Facades\DB;

/**
 * Partner counterpart of ArchiveRosterSync (2026-11-20): every TSA synced
 * from PEAK.CO / M&J / MLC (partners database, PartnerSync) gets a linked
 * Employee under TEAM PARTNERS, in a sub-team named after its partner.
 *
 * Same rules as the TSA Management sync: only FILLS empty links, never
 * overwrites a hand-edited name/position/team, never auto-reinstates.
 */
class ArchivePartnerSync
{
    public const PARENT_SLUG = 'partners';

    /** @return array{created: int, updated: int} */
    public static function run(): array
    {
        try {
            $rows = DB::connection(DataSet::PARTNERS)->table('tsa_shifts')
                ->get(['tsa_key', 'display_name', 'team', 'created_at', 'deleted_at']);
        } catch (\Throwable) {
            return ['created' => 0, 'updated' => 0]; // partners database not set up here
        }

        // Nothing synced yet (feed secret missing / fresh DB): don't touch anyone.
        if ($rows->isEmpty()) {
            return ['created' => 0, 'updated' => 0];
        }

        $teamIds = self::ensureTeams();
        $existing = Employee::whereNotNull('partner_tsa_key')->get()->keyBy('partner_tsa_key');
        $seen = [];
        $created = $updated = 0;

        foreach ($rows as $row) {
            $seen[$row->tsa_key] = true;
            $teamId = $teamIds[$row->team] ?? $teamIds[''];
            $removed = $row->deleted_at !== null;
            $employee = $existing->get($row->tsa_key);
            $name = $row->display_name ?: substr($row->tsa_key, strlen((string) $row->team) + 1) ?: $row->tsa_key;

            if (! $employee && ($employee = self::movedFromTsd($name))) {
                // A TSD TSA who moved to a partner: one person, not two.
                $changes = ['partner_tsa_key' => $row->tsa_key];
                if ($employee->status === 'resigned' && ! $removed) {
                    $changes += ['department_id' => $teamId, 'status' => 'active', 'left_at' => null];
                }
                $employee->update($changes);
                $updated++;
                continue;
            }

            if (! $employee) {
                Employee::create([
                    'partner_tsa_key' => $row->tsa_key,
                    'department_id'   => $teamId,
                    'full_name'       => $name,
                    'position'        => 'Telesales Agent',
                    'status'          => $removed ? 'resigned' : 'active',
                    'hired_at'        => $row->created_at ? substr((string) $row->created_at, 0, 10) : null,
                    'left_at'         => $removed ? substr((string) $row->deleted_at, 0, 10) : null,
                ]);
                $created++;
                continue;
            }

            $changes = $employee->department_id === null ? ['department_id' => $teamId] : [];
            if ($removed && $employee->status !== 'resigned') {
                $changes += ['status' => 'resigned', 'left_at' => substr((string) $row->deleted_at, 0, 10)];
            }
            if ($changes) {
                $employee->update($changes);
                $updated++;
            }
        }

        // Partner stopped sending a TSA (removed on their side) → Resigned.
        foreach ($existing as $key => $employee) {
            if (! isset($seen[$key]) && $employee->status !== 'resigned') {
                $employee->update(['status' => 'resigned', 'left_at' => today()->toDateString()]);
                $updated++;
            }
        }

        if ($created || $updated) {
            ActivityLogger::log('archive.roster.synced', null, "Synced people from partners ({$created} added, {$updated} updated)");
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /** The one TSD person (not yet partner-linked) with this name, or null
     *  when none or several match — a guess would merge two people. */
    private static function movedFromTsd(string $name): ?Employee
    {
        $matches = Employee::whereNull('partner_tsa_key')->whereNotNull('tsa_shift_id')->get()
            ->filter(fn (Employee $e) => LiveTsaBoard::sameName($e->full_name, $name));

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** TEAM PARTNERS (reuses a hand-made top-level "…Partners…" team) plus
     *  one sub-team per partner. @return array<string, int> partner name => id ('' => parent) */
    public static function ensureTeams(): array
    {
        $parent = Department::where('team_slug', self::PARENT_SLUG)->first()
            ?? Department::whereNull('parent_id')->whereNull('team_slug')->where('name', 'like', '%partner%')->orderBy('id')->first()
            ?? new Department(['name' => 'TEAM PARTNERS', 'color' => 'sky', 'sort_order' => 90]);
        if ($parent->team_slug !== self::PARENT_SLUG) {
            $parent->team_slug = self::PARENT_SLUG;
            $parent->save();
        }

        $ids = ['' => $parent->id];
        $i = 0;
        foreach (PartnerFeed::partners() as $slug => $partner) {
            $team = Department::where('team_slug', "partner-{$slug}")->first()
                ?? Department::where('parent_id', $parent->id)->whereNull('team_slug')->where('name', $partner['name'])->first()
                ?? new Department(['name' => $partner['name'], 'parent_id' => $parent->id, 'color' => $parent->color ?: 'sky', 'sort_order' => $i]);
            if ($team->team_slug !== "partner-{$slug}") {
                $team->team_slug = "partner-{$slug}";
                $team->save();
            }
            $ids[$partner['name']] = $team->id;
            $i++;
        }

        return $ids;
    }
}
