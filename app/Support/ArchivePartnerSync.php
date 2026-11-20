<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Employee;
use App\Models\TsaShift;
use App\Services\PartnerFeed;
use Illuminate\Support\Facades\DB;

/**
 * Partner counterpart of ArchiveRosterSync (2026-11-20): every ACTIVE TSA
 * synced from PEAK.CO / M&J / MLC (partners database, PartnerSync) gets a
 * linked Employee under TEAM PARTNERS, in a sub-team named after its partner.
 *
 * - TSAs a partner removed (deleted_at set) are ignored — partner apps keep
 *   TSD people they briefly listed, and those must not become partner staff.
 * - A TSD TSA who left TSD and now sells for a partner is ONE person: their
 *   existing record is linked and moved to the partner's sub-team.
 * - Only fills empty links; never overwrites a hand-edited name/position.
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
        $partnerTeamIds = array_values($teamIds);
        $active = $rows->whereNull('deleted_at')->keyBy('tsa_key');
        $existing = Employee::whereNotNull('partner_tsa_key')->get()->keyBy('partner_tsa_key');
        $leftTsd = self::leftTsd($existing->pluck('tsa_shift_id')->filter()->all());
        $created = $updated = 0;

        foreach ($active as $key => $row) {
            $teamId = $teamIds[$row->team] ?? $teamIds[''];
            $employee = $existing->get($key);
            $name = $row->display_name ?: substr($key, strlen((string) $row->team) + 1) ?: $key;

            if (! $employee && ($employee = self::movedFromTsd($name))) {
                $employee->update(['partner_tsa_key' => $key]);
                $leftTsd += self::leftTsd([$employee->tsa_shift_id]);
                $updated++;
            }

            if (! $employee) {
                Employee::create([
                    'partner_tsa_key' => $key,
                    'department_id'   => $teamId,
                    'full_name'       => $name,
                    'position'        => 'Telesales Agent',
                    'status'          => 'active',
                    'hired_at'        => $row->created_at ? substr((string) $row->created_at, 0, 10) : null,
                ]);
                $created++;
                continue;
            }

            $changes = $employee->department_id === null ? ['department_id' => $teamId] : [];
            // Left TSD for this partner and not moved yet → into the partner team, active.
            if ($employee->tsa_shift_id && isset($leftTsd[$employee->tsa_shift_id])
                && ! in_array($employee->department_id, $partnerTeamIds, true)) {
                $changes = ['department_id' => $teamId, 'status' => 'active', 'left_at' => null];
            }
            if ($changes) {
                $employee->update($changes);
                $updated++;
            }
        }

        // Partner no longer lists this TSA as active → unlink; resign unless still a TSD TSA.
        foreach ($existing as $key => $employee) {
            if ($active->has($key)) {
                continue;
            }
            $changes = ['partner_tsa_key' => null];
            $stillTsd = $employee->tsa_shift_id && ! isset($leftTsd[$employee->tsa_shift_id]);
            if (! $stillTsd && $employee->status !== 'resigned') {
                $changes += ['status' => 'resigned', 'left_at' => today()->toDateString()];
            }
            $employee->update($changes);
            $updated++;
        }

        if ($created || $updated) {
            ActivityLogger::log('archive.roster.synced', null, "Synced people from partners ({$created} added, {$updated} updated)");
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /** TSD roster ids (of $ids) that were removed from TSA Management.
     *  @return array<int, true> */
    private static function leftTsd(array $ids): array
    {
        return $ids === [] ? [] : TsaShift::withoutGlobalScopes()->whereIn('id', $ids)->whereNotNull('deleted_at')
            ->pluck('id')->mapWithKeys(fn ($id) => [$id => true])->all();
    }

    /** The one TSD person (not yet partner-linked) with this name, or null
     *  when none or several match — a guess would merge two people. */
    private static function movedFromTsd(string $name): ?Employee
    {
        $matches = Employee::whereNull('partner_tsa_key')->whereNotNull('tsa_shift_id')->get()
            ->filter(fn (Employee $e) => LiveTsaBoard::sameName($e->full_name, $name));

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** TEAM PARTNERS (reuses a hand-made top-level "…Partners…" team, any
     *  case — Postgres LIKE is case-sensitive) plus one sub-team per partner.
     *  @return array<string, int> partner name => id ('' => parent) */
    public static function ensureTeams(): array
    {
        $parent = Department::where('team_slug', self::PARENT_SLUG)->first()
            ?? Department::whereNull('parent_id')->whereNull('team_slug')
                ->whereRaw('LOWER(name) LIKE ?', ['%partner%'])
                ->orderByRaw('archived_at IS NULL DESC')->orderBy('id')->first()
            ?? new Department(['name' => 'TEAM PARTNERS', 'color' => 'sky', 'sort_order' => 90]);
        if ($parent->team_slug !== self::PARENT_SLUG) {
            $parent->team_slug = self::PARENT_SLUG;
            $parent->save();
        }

        $ids = ['' => $parent->id];
        $i = 0;
        foreach (PartnerFeed::partners() as $slug => $partner) {
            $team = Department::where('team_slug', "partner-{$slug}")->first()
                ?? Department::where('parent_id', $parent->id)->whereNull('team_slug')
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($partner['name'])])->first()
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
