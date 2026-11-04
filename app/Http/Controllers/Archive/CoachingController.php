<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\CoachingRecord;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CoachingController extends Controller
{
    public function store(Request $request)
    {
        $record = CoachingRecord::create($this->validated($request) + ['coach_id' => auth()->id()]);
        $record->load('employee');
        ActivityLogger::log('archive.coaching.created', $record, "Logged " . CoachingRecord::TYPES[$record->type]['label'] . " for {$record->employee->full_name}: {$record->topic}");

        return back()->with('success', 'Record saved.');
    }

    public function update(Request $request, CoachingRecord $record)
    {
        $record->update($this->validated($request));
        ActivityLogger::log('archive.coaching.updated', $record, "Updated " . CoachingRecord::TYPES[$record->type]['label'] . " for {$record->employee->full_name}");

        return back()->with('success', 'Record updated.');
    }

    public function acknowledge(CoachingRecord $record)
    {
        $record->update(['acknowledged_at' => $record->acknowledged_at ? null : now()]);
        ActivityLogger::log('archive.coaching.acknowledged', $record, ($record->acknowledged_at ? 'Marked acknowledged' : 'Cleared acknowledgment') . " — {$record->employee->full_name}: {$record->topic}");

        return back()->with('success', $record->acknowledged_at ? 'Marked as acknowledged by the TSA.' : 'Acknowledgment cleared.');
    }

    public function destroy(CoachingRecord $record)
    {
        $label = "{$record->employee->full_name}: {$record->topic}";
        $record->delete();
        ActivityLogger::log('archive.coaching.deleted', null, "Deleted coaching/QA record — {$label}");

        return back()->with('success', 'Record deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'employee_id'  => ['required', 'integer', 'exists:employees,id'],
            'type'         => ['required', Rule::in(array_keys(CoachingRecord::TYPES))],
            'session_date' => ['required', 'date', 'before_or_equal:today'],
            'topic'        => ['required', 'string', 'max:160'],
            'reference'    => ['nullable', 'string', 'max:120'],
            'score'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes'        => ['nullable', 'string', 'max:5000'],
            'action_plan'  => ['nullable', 'string', 'max:5000'],
        ]);
        if ($data['type'] !== 'qa') {
            $data['score'] = null; // scores only mean something on a QA scorecard
        }

        return $data;
    }
}
