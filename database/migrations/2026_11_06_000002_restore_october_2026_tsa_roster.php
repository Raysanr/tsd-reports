<?php

use App\Support\DataSet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Puts back October 2026's real teams and eye (explicit request, 2026-11-06).
 * Shift per month started Nov 3, so October was saved from that day's teams.
 * This list comes from the Summary Sales Report exported for October: its team
 * totals only add up with Marsha, Grace and Hannah in Opening, hidden. Anyone
 * else wasn't on that report, so they're hidden in October with their team
 * unchanged. Matched by first name, since display names vary. TSD only.
 */
return new class extends Migration
{
    private const MONTH = '2026-10';

    private const OPENING = 'Eyecare Team';

    private const CLOSING = 'SH Naturals';

    private const ROSTER = [
        'gemma' => [self::OPENING, false], 'julie' => [self::OPENING, false], 'joana' => [self::OPENING, false],
        'marisol' => [self::OPENING, false], 'jhun' => [self::OPENING, false],
        'marsha' => [self::OPENING, true], 'grace' => [self::OPENING, true], 'hannah' => [self::OPENING, true],
        'mariel' => [self::CLOSING, false], 'kathleen' => [self::CLOSING, false], 'katherine' => [self::CLOSING, false],
        'angel' => [self::CLOSING, false], 'lika' => [self::CLOSING, false], 'anne' => [self::CLOSING, false],
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getName() === DataSet::PARTNERS || !DB::table('tsa_month_settings')->where('month', self::MONTH)->exists()) {
            return;
        }

        foreach (DB::table('tsa_shifts')->get() as $tsa) {
            $firstName = strtolower(strtok(trim($tsa->display_name), ' '));
            [$team, $hidden] = self::ROSTER[$firstName] ?? [$tsa->team, true];
            $keys = ['month' => self::MONTH, 'tsa_shift_id' => $tsa->id];
            $saved = DB::table('tsa_month_settings')->where($keys)->first();

            DB::table('tsa_month_settings')->updateOrInsert($keys, [
                'team'             => $team,
                'is_hidden'        => $hidden,
                'shift_start'      => $saved->shift_start ?? $tsa->shift_start,
                'shift_end'        => $saved->shift_end ?? $tsa->shift_end,
                'rest_day_of_week' => $saved->rest_day_of_week ?? $tsa->rest_day_of_week,
                'tiktok_upsell'    => $saved->tiktok_upsell ?? $tsa->tiktok_upsell,
                'updated_at'       => now(),
                'created_at'       => $saved->created_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        // The earlier October values were wrong, so there's nothing to put back.
    }
};
