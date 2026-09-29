<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One real TSA's own Cost Breakdown payroll numbers — see
 *  create_cost_breakdown_tsa_entries_table's own doc comment for why
 *  base_salary/tsa_bonus are manual but `days` drives a real formula. */
class CostBreakdownTsaEntry extends Model
{
    protected $fillable = ['tsa_id', 'base_salary', 'tsa_bonus', 'days'];

    protected $casts = [
        'base_salary' => 'float',
        'tsa_bonus' => 'float',
        'days' => 'integer',
    ];

    /** Real base salaries confirmed against the sheet's own raw CSV, keyed
     *  by tsa_key (matches TsaShift's own tsa_key column, not display_name
     *  — the app's real roster's own stable identifier). Every real TSA
     *  gets the exact same flat 20,888.75 tsa_bonus, confirmed across
     *  every one of the sheet's 12 named TSAs regardless of shift or base
     *  amount — see CostBreakdownCalculator's own doc comment. Only the 6
     *  TSAs currently in this app's own live roster have a real starting
     *  base_salary here; ensureSeeded() below is a no-op for anyone not
     *  listed (a TSA added later starts at 0/manual entry, same as any
     *  other page in this module). */
    public const SEED_BASE_SALARIES = [
        'Julie' => 19500.00,
        'Joana' => 17420.00,
        'Marisol' => 19500.00,
        'Gemma' => 19500.00,
        'Mariel' => 19987.50,
        'Kathleen' => 19987.50,
    ];

    public const TSA_BONUS = 20888.75;

    /** Self-heals an empty table the same way CostBreakdownRole::
     *  ensureSeeded() does. Seeds only the TSAs already in the real
     *  roster at the time this runs — a TSA added afterward simply starts
     *  with no entry (the controller's own fallback shows her at 0/30
     *  days until someone types real numbers in). */
    public static function ensureSeeded(): void
    {
        if (static::query()->exists()) {
            return;
        }

        foreach (self::SEED_BASE_SALARIES as $tsaKey => $baseSalary) {
            $tsa = TsaShift::where('tsa_key', $tsaKey)->first();
            if (!$tsa) {
                continue;
            }

            static::firstOrCreate(
                ['tsa_id' => $tsa->id],
                ['base_salary' => $baseSalary, 'tsa_bonus' => self::TSA_BONUS, 'days' => 30]
            );
        }
    }

    public function tsa(): BelongsTo
    {
        return $this->belongsTo(TsaShift::class, 'tsa_id');
    }
}
