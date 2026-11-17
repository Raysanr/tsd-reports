<?php

namespace App\Http\Controllers;

use App\Models\HubAgenda;
use App\Support\PhHolidays;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Hub calendar (explicit request, 2026-11-17): everyone sees it with the
 * PH holidays and the agendas; only admins add, change or delete agendas
 * (routes are role:super_admin,admin).
 */
class HubCalendarController extends Controller
{
    /** Everything the Hub page needs to draw the calendar without a fetch. */
    public static function viewData(): array
    {
        $year = (int) now()->year;
        $years = range($year - 1, $year + 2);

        return [
            'calendar' => [
                'today'    => now()->toDateString(),
                'canEdit'  => (bool) auth()->user()?->isAtLeastAdmin(),
                'holidays' => PhHolidays::forYears($years),
                'agendas'  => HubAgenda::whereBetween('date', ["{$years[0]}-01-01", end($years) . '-12-31'])
                    ->orderBy('date')->orderByRaw('time is null')->orderBy('time')->orderBy('id')->get()
                    ->map->toCalendarArray()->values(),
                'colors'   => HubAgenda::COLORS,
            ],
        ];
    }

    public function store(Request $request)
    {
        $agenda = HubAgenda::create($this->validated($request) + ['created_by' => $request->user()->id]);

        return response()->json(['agenda' => $agenda->toCalendarArray()], 201);
    }

    public function update(Request $request, HubAgenda $agenda)
    {
        $agenda->update($this->validated($request));

        return response()->json(['agenda' => $agenda->fresh()->toCalendarArray()]);
    }

    public function destroy(HubAgenda $agenda)
    {
        $agenda->delete();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'date'  => ['required', 'date_format:Y-m-d'],
            'title' => ['required', 'string', 'max:120'],
            'time'  => ['nullable', 'date_format:H:i'],
            'note'  => ['nullable', 'string', 'max:1000'],
            'color' => ['nullable', Rule::in(HubAgenda::COLORS)],
        ]);
        $data['color'] ??= 'blue';

        // A field left out clears it (an edit that removes the time).
        return $data + ['time' => null, 'note' => null];
    }
}
