@extends('layouts.calls')
@section('title', 'Call Recordings')
@section('subtitle', 'Recordings uploaded from each TSA\'s own PC (Phone Link)')

@push('topbar-right')
<select onchange="window.location.href=this.value"
        class="text-xs font-semibold font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-1.5 bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-yellow-500">
    <option value="{{ route('calls.call-recordings', ['tsa' => '', 'date_from' => $dateFrom, 'date_to' => $dateTo]) }}" @selected(!$selectedTsa)>All TSAs</option>
    @foreach($tsas as $tsa)
    <option value="{{ route('calls.call-recordings', ['tsa' => $tsa->id, 'date_from' => $dateFrom, 'date_to' => $dateTo]) }}" @selected($selectedTsa === $tsa->id)>{{ $tsa->display_name }}</option>
    @endforeach
</select>

@include('partials.date-picker', [
    'mode' => 'range', 'id' => 'callRecordingsDrp',
    'dateFrom' => \Illuminate\Support\Carbon::parse($dateFrom), 'dateTo' => \Illuminate\Support\Carbon::parse($dateTo),
    'submit' => 'navigate', 'navigateBase' => route('calls.call-recordings'),
])
@endpush

@section('content')

<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    @if($recordings->isEmpty())
    <div class="py-12 flex flex-col items-center justify-center gap-2">
        <svg class="w-9 h-9 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">No recordings for this range yet.</p>
    </div>
    @else
    <div class="overflow-x-auto">
    <table class="w-full text-sm font-mono">
        <thead class="bg-slate-100 dark:bg-slate-700 border-b border-slate-200 dark:border-slate-700">
            <tr>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">When</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">TSA</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Matched Lead</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">File</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Play</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($recordings as $recording)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ $recording->recorded_at->format('M j, g:i A') }}</td>
                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $recording->tsa?->display_name ?? '—' }}</td>
                <td class="px-4 py-3">
                    @if($recording->lead)
                    <a href="{{ route('calls.leads.show', $recording->lead) }}" class="text-primary hover:underline">{{ $recording->lead->customer_name ?: '#'.$recording->lead->pancake_order_id }}</a>
                    @else
                    <span class="text-slate-300 dark:text-slate-600">no match</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ $recording->original_filename ?? basename($recording->disk_path) }}</td>
                <td class="px-4 py-3">
                    <audio controls preload="none" class="h-8 max-w-[220px]" src="{{ route('calls.call-recordings.stream', $recording) }}"></audio>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    @endif
</div>

@endsection
