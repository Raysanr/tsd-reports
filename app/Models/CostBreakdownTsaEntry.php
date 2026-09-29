<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One real TSA's own Cost Breakdown payroll numbers — see
 *  create_cost_breakdown_tsa_entries_table's own doc comment for why
 *  base_salary is manual but `days` drives a real formula. base_salary is
 *  her REAL full monthly total (explicit follow-up, 2026-09-29: "there's
 *  no bonus on the sheets so it should no bonus in that" — see
 *  drop_tsa_bonus_from_cost_breakdown_tsa_entries_table's own doc
 *  comment), not a base figure with a separate bonus added elsewhere. */
class CostBreakdownTsaEntry extends Model
{
    protected $fillable = ['tsa_id', 'base_salary', 'days'];

    protected $casts = [
        'base_salary' => 'float',
        'days' => 'integer',
    ];

    /** Real full monthly totals confirmed against the sheet's own raw CSV
     *  export, keyed by tsa_key (matches TsaShift's own tsa_key column,
     *  not display_name — the app's real roster's own stable identifier).
     *  Only the 6 TSAs currently in this app's own live roster have a real
     *  starting value here; ensureSeeded() below is a no-op for anyone not
     *  listed (a TSA added later starts at 0/manual entry, same as any
     *  other page in this module). */
    public const SEED_BASE_SALARIES = [
        'Julie' => 40388.75,
        'Joana' => 38308.75,
        'Marisol' => 40388.75,
        'Gemma' => 40388.75,
        'Mariel' => 40876.25,
        'Kathleen' => 40876.25,
    ];

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
                ['base_salary' => $baseSalary, 'days' => 30]
            );
        }
    }

    public function tsa(): BelongsTo
    {
        return $this->belongsTo(TsaShift::class, 'tsa_id');
    }
}
