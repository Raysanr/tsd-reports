<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One P&L "column" on the Projections page (Telesales Department, Opening
 *  Shift: Monthly Target, etc.) — see the create_projection_columns_table
 *  migration's own doc comment for the full formula-chain reasoning. Only
 *  ever stores the raw INPUTS a person can type; every derived figure
 *  (Orders Needed, Gross Sales, Net Income, ...) is computed fresh by
 *  ProjectionCalculator from these inputs plus the shared rate Settings. */
class ProjectionColumn extends Model
{
    protected $fillable = ['key', 'label', 'net_income_target', 'average_order_value', 'orders_override', 'tsa_count', 'sort_order'];

    protected $casts = [
        'net_income_target'   => 'float',
        'average_order_value' => 'float',
        'orders_override'      => 'float',
        'tsa_count'            => 'integer',
        'sort_order'           => 'integer',
    ];

    /** The 7 real columns and their seed inputs — the same values the
     *  create_projection_columns_table + add_closing_shift_columns_to_projections
     *  migrations insert between them. Shared here (not just in the
     *  migrations) because a table that's migrated-but-empty is a real
     *  failure mode seen in practice (a dev DB reset after a migration
     *  already ran, so it never re-executes) and silently renders an
     *  empty page with no error — self-healing via ensureSeeded() below
     *  is cheaper than chasing that down by hand again. firstOrCreate
     *  keeps this idempotent: running it against an already-seeded table
     *  is a no-op.
     *
     *  Two independently-editable base columns now (Opening Shift AND
     *  Closing Shift — explicit request, 2026-09-23: "okay now in the
     *  downpart is the closing team"), each with its own derived
     *  Individual TSA Monthly/Daily pair; Telesales Department is derived
     *  from BOTH (Opening + Closing — see ProjectionCalculator's own doc
     *  comment). */
    public const SEED_COLUMNS = [
        ['key' => 'telesales_department', 'label' => 'Telesales Department', 'net_income_target' => 1200000, 'average_order_value' => 800, 'tsa_count' => 12, 'sort_order' => 0],
        ['key' => 'opening_shift', 'label' => 'Opening Shift: Monthly Target', 'net_income_target' => 600000, 'average_order_value' => 800, 'tsa_count' => 6, 'sort_order' => 1],
        ['key' => 'opening_individual_tsa_monthly', 'label' => 'Opening — Individual TSA: Monthly Target', 'net_income_target' => 100000, 'average_order_value' => 800, 'tsa_count' => 1, 'sort_order' => 2],
        ['key' => 'opening_individual_tsa_daily', 'label' => 'Opening — Individual TSA: Daily Target', 'net_income_target' => 4200, 'average_order_value' => 800, 'tsa_count' => 1, 'sort_order' => 3],
        ['key' => 'closing_shift', 'label' => 'Closing Shift: Monthly Target', 'net_income_target' => 600000, 'average_order_value' => 800, 'tsa_count' => 6, 'sort_order' => 4],
        ['key' => 'closing_individual_tsa_monthly', 'label' => 'Closing — Individual TSA: Monthly Target', 'net_income_target' => 100000, 'average_order_value' => 800, 'tsa_count' => 1, 'sort_order' => 5],
        ['key' => 'closing_individual_tsa_daily', 'label' => 'Closing — Individual TSA: Daily Target', 'net_income_target' => 4200, 'average_order_value' => 800, 'tsa_count' => 1, 'sort_order' => 6],
    ];

    public static function ensureSeeded(): void
    {
        if (static::query()->exists()) {
            return;
        }

        foreach (self::SEED_COLUMNS as $seed) {
            static::firstOrCreate(['key' => $seed['key']], $seed);
        }
    }
}
