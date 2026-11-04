<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Employee;
use App\Models\ScoreboardImport;
use App\Models\Scopes\TsaRosterScope;
use App\Models\TsaShift;

/**
 * Loads database/data/scoreboard-sheet-2026-jan-apr.json (extracted from the
 * team's Google Sheet) into scoreboard_imports — the TSD Scoreboard then uses
 * these figures, not the system's, for those months (explicit request,
 * 2026-10-20: "from january to april the sheets is the basis").
 *
 * Names are matched to TSA roster rows — removed ones included — by tsa_key,
 * full display name, or first name; anyone unmatched is kept as a former TSA
 * (tsa_shift_id null). Re-running replaces the same months (idempotent).
 */
class ScoreboardSheetImport
{
    /** Sheet spelling => roster spelling, where they differ. */
    private const ALIASES = ['JOANNA' => 'JOANA'];

    /** @return array{rows: int, matched: int, former: array<int, string>} */
    public static function run(?string $path = null): array
    {
        $data = json_decode(file_get_contents($path ?? database_path('data/scoreboard-sheet-2026-jan-apr.json')), true);
        $index = self::rosterIndex();
        $rows = $matched = 0;
        $former = [];

        foreach ($data['months'] as $ym => $month) {
            $monthDate = $ym . '-01';
            foreach ($month['people'] as $p) {
                $name = mb_strtoupper(trim($p['name']));
                $tsaId = $index[self::ALIASES[$name] ?? $name] ?? null;
                // whereDate, not updateOrCreate's exact match — the date cast
                // stores "Y-m-d 00:00:00" on SQLite (same fix as PerformanceSnapshotter).
                $row = ScoreboardImport::whereDate('month', $monthDate)->where('name', $name)->first()
                    ?? new ScoreboardImport(['month' => $monthDate, 'name' => $name]);
                $row->fill(['team_slug' => $p['team'], 'tsa_shift_id' => $tsaId, 'sales' => $p['sales'], 'net' => $p['net'], 'source' => $month['sheet']])->save();
                $rows++;
                $tsaId ? $matched++ : $former[$name] = $name;
            }
            \Illuminate\Support\Facades\Cache::forget('scoreboard:figures:' . $ym);
        }

        self::syncFormerPeople();

        return ['rows' => $rows, 'matched' => $matched, 'former' => array_values($former)];
    }

    /**
     * Former TSAs from the sheet (no roster match) also get a People entry,
     * status Resigned, in their last sheet team (explicit request,
     * 2026-10-20: "add that former to the people page"). Skips anyone already
     * in People under the same name. Created without model events: the
     * automatic "Joined" timeline entry would be dated today, which is wrong
     * for someone who left months ago.
     *
     * @return int how many people were added
     */
    public static function syncFormerPeople(): int
    {
        $added = 0;
        $formers = ScoreboardImport::whereNull('tsa_shift_id')->orderByDesc('month')->get()->unique('name');
        foreach ($formers as $import) {
            $fullName = self::displayName($import->name);
            if (Employee::whereRaw('UPPER(full_name) = ?', [mb_strtoupper($fullName)])->exists()) {
                continue;
            }
            Employee::withoutEvents(fn () => Employee::create([
                'full_name'     => $fullName,
                'position'      => 'Telesales Agent',
                'status'        => 'resigned',
                'department_id' => $import->team_slug ? Department::where('team_slug', $import->team_slug)->value('id') : null,
                'notes'         => 'Imported from the IS Report Google Sheet (former TSA — last seen there in ' . $import->month->format('F Y') . ').',
            ]));
            $added++;
        }

        return $added;
    }

    /** "MARY JANE CRUZAT" → "Mary Jane Cruzat", but initials stay caps: "MJ CALIWAN" → "MJ Caliwan". */
    public static function displayName(string $sheetName): string
    {
        return collect(preg_split('/\s+/', trim($sheetName)))
            ->map(fn ($w) => mb_strlen($w) <= 2 ? mb_strtoupper($w) : mb_convert_case(mb_strtolower($w), MB_CASE_TITLE))
            ->join(' ');
    }

    /** UPPERCASE name variant => tsa_shift id (tsa_key, display name, first name). */
    private static function rosterIndex(): array
    {
        $index = [];
        foreach (TsaShift::withoutGlobalScope(TsaRosterScope::class)->orderBy('deleted_at')->get() as $t) {
            $display = mb_strtoupper(trim((string) $t->display_name));
            foreach (array_filter([mb_strtoupper(trim((string) $t->tsa_key)), $display, strtok($display, ' ')]) as $variant) {
                $index[$variant] ??= $t->id; // current roster wins over a removed duplicate
            }
        }

        return $index;
    }
}
