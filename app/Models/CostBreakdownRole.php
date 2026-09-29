<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One non-TSA payroll role on the Cost Breakdown page (CEO, Sales
 *  Director, Telesales Manager, QA Specialist, Junior AI Engineer, each
 *  shift's own Supervisor) — see create_cost_breakdown_roles_table's own
 *  doc comment for why every field here is manual entry. */
class CostBreakdownRole extends Model
{
    protected $fillable = ['label', 'person_name', 'base_salary', 'shared_bonus', 'sort_order'];

    protected $casts = [
        'base_salary' => 'float',
        'shared_bonus' => 'float',
        'sort_order' => 'integer',
    ];

    /** The real sheet's own starting roster and numbers — self-heals an
     *  empty table the same way ProjectionColumn::ensureSeeded() does,
     *  since a migrated-but-empty table is a real failure mode already
     *  seen in this app (a dev DB reset after the migration already ran). */
    public const SEED_ROLES = [
        // Bonus placement confirmed against the sheet's own raw CSV export
        // (2026-09-29): the 10,341.13 figure sits in the CEO's OWN row,
        // not the Sales Director's, despite how the rendered sheet visually
        // groups them — don't re-guess this from a screenshot again.
        ['label' => 'CEO', 'person_name' => null, 'base_salary' => 44095.59, 'shared_bonus' => 10341.13, 'sort_order' => 0],
        ['label' => 'Sales Director', 'person_name' => null, 'base_salary' => 19998.00, 'shared_bonus' => null, 'sort_order' => 1],
        ['label' => 'Telesales Manager', 'person_name' => 'Allaisa Jane Insigne', 'base_salary' => 60000.00, 'shared_bonus' => null, 'sort_order' => 2],
        ['label' => 'QA Specialist', 'person_name' => 'Jake Yamson', 'base_salary' => 28000.00, 'shared_bonus' => 4833.33, 'sort_order' => 3],
        ['label' => 'Junior AI Engineer', 'person_name' => 'Raysan Raymundo', 'base_salary' => 30000.00, 'shared_bonus' => null, 'sort_order' => 4],
        ['label' => 'Telesales Supervisor (Opening Shift)', 'person_name' => 'Lhiza Alconera', 'base_salary' => 34285.71, 'shared_bonus' => 5714.29, 'sort_order' => 5],
        ['label' => 'Telesales Supervisor (Closing Shift)', 'person_name' => 'Gretchen Orencio', 'base_salary' => 34285.71, 'shared_bonus' => 5714.29, 'sort_order' => 6],
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
