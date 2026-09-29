<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One real TSA's own Cost Breakdown payroll numbers — see
 *  create_cost_breakdown_tsa_entries_table's own doc comment for why
 *  base_salary is manual but `days` drives a real formula. base_salary is
 *  her RAW base salary ONLY (explicit reversal, 2026-09-30, from the
 *  user's own formula-bar screenshot: "=D5+D9+D12+C13" — her own row's
 *  displayed TOTAL is base_salary + every applicable overhead group's own
 *  per-TSA reference figure, computed fresh by
 *  CostBreakdownCalculator::tsaTotal(), NOT typed directly. An earlier
 *  2026-09-29 conclusion ("there's no bonus ... base_salary IS her real
 *  full monthly total") was wrong — it matched the numbers by
 *  coincidence because every TSA on a given team adds the exact same
 *  combined overhead figure to her own base). */
class CostBreakdownTsaEntry extends Model
{
    protected $fillable = ['tsa_id', 'base_salary', 'days'];

    protected $casts = [
        'base_salary' => 'float',
        'days' => 'integer',
    ];

    /** Real RAW base salaries (column C in the sheet, not her own folded
     *  TOTAL column) confirmed against the sheet's own raw CSV export,
     *  keyed by tsa_key (matches TsaShift's own tsa_key column, not
     *  display_name — the app's real roster's own stable identifier).
     *  Only the 6 TSAs currently in this app's own live roster have a real
     *  starting value here; ensureSeeded() below is a no-op for anyone not
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
