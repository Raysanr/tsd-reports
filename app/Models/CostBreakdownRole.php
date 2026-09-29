<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One non-TSA payroll role on the Cost Breakdown page (CEO, Sales
 *  Director, Telesales Manager, QA Specialist, Junior AI Engineer, each
 *  shift's own Supervisor) — see create_cost_breakdown_roles_table's own
 *  doc comment for why every field here is manual entry. */
class CostBreakdownRole extends Model
{
    protected $fillable = ['label', 'person_name', 'base_salary', 'shared_bonus', 'shared_bonus_span', 'sort_order'];

    protected $casts = [
        'base_salary' => 'float',
        'shared_bonus' => 'float',
        'shared_bonus_span' => 'integer',
        'sort_order' => 'integer',
    ];

    /** The real sheet's own starting roster and numbers, confirmed against
     *  its own raw CSV export (2026-09-29) — see
     *  add_shared_bonus_span_to_cost_breakdown_roles_table's own doc
     *  comment for why shared_bonus is a purely informational figure
     *  visually spanning multiple rows, never added into base_salary or
     *  any total. Self-heals an empty table the same way ProjectionColumn::
     *  ensureSeeded() does. */
    public const SEED_ROLES = [
        // 10,341.13 visually spans this row + the next 2 (Sales Director,
        // Telesales Manager) in the real sheet — shown as a merged cell,
        // never added to anyone's own base_salary.
        ['label' => 'CEO', 'person_name' => null, 'base_salary' => 44095.59, 'shared_bonus' => 10341.13, 'shared_bonus_span' => 3, 'sort_order' => 0],
        ['label' => 'Sales Director', 'person_name' => null, 'base_salary' => 19998.00, 'shared_bonus' => null, 'shared_bonus_span' => 1, 'sort_order' => 1],
        ['label' => 'Telesales Manager', 'person_name' => 'Allaisa Jane Insigne', 'base_salary' => 60000.00, 'shared_bonus' => null, 'shared_bonus_span' => 1, 'sort_order' => 2],
        // 4,833.33 visually spans this row + the next 1 (Junior AI
        // Engineer).
        ['label' => 'QA Specialist', 'person_name' => 'Jake Yamson', 'base_salary' => 28000.00, 'shared_bonus' => 4833.33, 'shared_bonus_span' => 2, 'sort_order' => 3],
        ['label' => 'Junior AI Engineer', 'person_name' => 'Raysan Raymundo', 'base_salary' => 30000.00, 'shared_bonus' => null, 'shared_bonus_span' => 1, 'sort_order' => 4],
        // Supervisors: base_salary IS the real folded total (34,285.71 +
        // 5,714.29 = 40,000.00) — no separate bonus field or column at all,
        // same convention as the individual TSA rows below them
        // (CostBreakdownTsaEntry's own doc comment).
        ['label' => 'Telesales Supervisor (Opening Shift)', 'person_name' => 'Lhiza Alconera', 'base_salary' => 40000.00, 'shared_bonus' => null, 'shared_bonus_span' => 1, 'sort_order' => 5],
        ['label' => 'Telesales Supervisor (Closing Shift)', 'person_name' => 'Gretchen Orencio', 'base_salary' => 40000.00, 'shared_bonus' => null, 'shared_bonus_span' => 1, 'sort_order' => 6],
    ];

    public static function ensureSeeded(): void
    {
        if (static::query()->exists()) {
            return;
        }

        foreach (self::SEED_ROLES as $seed) {
            static::firstOrCreate(['label' => $seed['label']], $seed);
        }
    }
}
