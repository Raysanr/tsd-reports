<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One non-TSA payroll role on the Cost Breakdown page (CEO, Sales
 *  Director, Telesales Manager, QA Specialist, Junior AI Engineer, each
 *  shift's own Supervisor) — see create_cost_breakdown_roles_table's own
 *  doc comment for why every field here is manual entry. */
class CostBreakdownRole extends Model
{
    protected $fillable = ['label', 'person_name', 'team', 'base_salary', 'overhead_group', 'overhead_divisor', 'sort_order'];

    protected $casts = [
        'base_salary' => 'float',
        'sort_order' => 'integer',
    ];

    /** The real sheet's own starting roster and numbers, confirmed against
     *  its own raw CSV export (2026-09-29) — overhead_group/
     *  overhead_divisor replace what this app used to (wrongly) treat as a
     *  manually-typed "shared bonus" — see
     *  rework_shared_bonus_into_overhead_group_on_cost_breakdown_roles_
     *  table's own doc comment for the real formula this class's own
     *  overheadPerTsa() now computes fresh instead of reading a stored
     *  number. Self-heals an empty table the same way
     *  ProjectionColumn::ensureSeeded() does. */
    public const SEED_ROLES = [
        // Real formula, confirmed from the sheet's own cell (2026-09-29):
        // =(C5+C6+C7)/12 — CEO + Sales Director + Telesales Manager's own
        // base salaries, divided by the TOTAL real TSA headcount (12 in
        // the sheet's own snapshot; this app uses whatever the real
        // TsaShift roster's own count is right now instead of a frozen
        // 12 — see overheadPerTsa()'s own doc comment).
        ['label' => 'CEO', 'person_name' => null, 'base_salary' => 44095.59, 'overhead_group' => 'executive', 'overhead_divisor' => 'total', 'sort_order' => 0],
        ['label' => 'Sales Director', 'person_name' => null, 'base_salary' => 19998.00, 'overhead_group' => 'executive', 'overhead_divisor' => 'total', 'sort_order' => 1],
        ['label' => 'Telesales Manager', 'person_name' => 'Allaisa Jane Insigne', 'base_salary' => 60000.00, 'overhead_group' => 'executive', 'overhead_divisor' => 'total', 'sort_order' => 2],
        // =(C9+C10)/12 — QA Specialist + Junior AI Engineer, divided by
        // the total real TSA headcount too.
        ['label' => 'QA Specialist', 'person_name' => 'Jake Yamson', 'base_salary' => 28000.00, 'overhead_group' => 'support', 'overhead_divisor' => 'total', 'sort_order' => 3],
        ['label' => 'Junior AI Engineer', 'person_name' => 'Raysan Raymundo', 'base_salary' => 30000.00, 'overhead_group' => 'support', 'overhead_divisor' => 'total', 'sort_order' => 4],
        // =C12/6 — each Supervisor's own base salary alone, divided by HER
        // OWN team's TSA headcount — confirmed exact: 34,285.71 ÷ 6 =
        // 5,714.285. team (explicit follow-up, 2026-09-29: "the supervisor
        // of opening and closing is in the rows of their TSA's") is how
        // the controller knows which real TsaShift rows to nest directly
        // beneath her — matches TsaShift's own `team` column values
        // exactly (TeamShiftWindow's own OPENING_TEAM/CLOSING_TEAM
        // constants). overhead_group is UNIQUE PER SUPERVISOR (not shared
        // "supervisor" for both) — a shared group name would make the
        // controller's own merged-cell rowspan span across BOTH shifts'
        // rows as if they were one group, root-caused live 2026-09-29 from
        // a screenshot showing the Opening Shift's own merged cell
        // visually bleeding down into the Closing Shift's own row.
        ['label' => 'Telesales Supervisor (Opening Shift)', 'person_name' => 'Lhiza Alconera', 'team' => 'Eyecare Team', 'base_salary' => 34285.71, 'overhead_group' => 'supervisor_opening', 'overhead_divisor' => 'team', 'sort_order' => 5],
        ['label' => 'Telesales Supervisor (Closing Shift)', 'person_name' => 'Gretchen Orencio', 'team' => 'SH Naturals', 'base_salary' => 34285.71, 'overhead_group' => 'supervisor_closing', 'overhead_divisor' => 'team', 'sort_order' => 6],
    ];

    /** The sheet's OWN fixed TSA headcount per overhead_divisor value —
     *  12 total, 6 per team — confirmed exact against its own 3 real
     *  cells (see CostBreakdownCalculator::overheadPerTsa()'s own doc
     *  comment). Explicit decision, 2026-09-29: always match the sheet's
     *  own numbers exactly, NOT this app's own real (currently smaller)
     *  TsaShift roster — an earlier version divided by the real live
     *  headcount instead, which produced different figures than the sheet
     *  while the real roster is still below 12. */
    public const OVERHEAD_DIVISOR_COUNTS = [
        'total' => 12,
        'team' => 6,
    ];

    /** Creates any missing role (with its full seed values, base_salary
     *  included) AND re-syncs every STRUCTURAL field (person_name/team/
     *  overhead_group/overhead_divisor/sort_order) on an ALREADY-EXISTING
     *  role on every call, not just when the table is first empty —
     *  root-caused live, 2026-09-29: an earlier "only seed if empty"
     *  version left a dev database's already-existing rows silently stuck
     *  on old values after this class's own SEED_ROLES changed in later
     *  commits, since firstOrCreate() never touches a row that already
     *  exists. base_salary is deliberately EXCLUDED from the re-sync
     *  (existing rows only) — it's the one field this app's own UI
     *  actually lets someone type a real edit into, so overwriting it on
     *  every page load would silently discard that edit the next time
     *  this runs. */
    public static function ensureSeeded(): void
    {
        foreach (self::SEED_ROLES as $seed) {
            $role = static::firstOrCreate(['label' => $seed['label']], $seed);
            $role->fill(collect($seed)->except(['label', 'base_salary'])->all());
            if ($role->isDirty()) {
                $role->save();
            }
        }
    }
}
