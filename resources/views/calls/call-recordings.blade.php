@extends('layouts.calls')
@section('title', 'Call Recordings')
@section('subtitle', 'Real recorded calls, uploaded from each TSA\'s own PC')

@section('content')

<div class="mb-6">
    <form method="GET" class="flex items-center gap-3 flex-wrap">
        <select name="tsa" onchange="this.form.submit()"
                class="text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            <option value="">All TSAs</option>
            @foreach($tsas as $tsa)
            <option value="{{ $tsa->id }}" @selected($selectedTsa === $tsa->id)>{{ $tsa->display_name }}</option>
            @endforeach
        </select>

        @include('partials.date-picker', [
            'mode' => 'range', 'id' => 'callsRecordingsDrp',
            'dateFrom' => \Illuminate\Support\Carbon::parse($dateFrom),
            'dateTo'   => \Illuminate\Support\Carbon::parse($dateTo),
            'showLabel' => true,
        ])
    </form>
</div>

<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    @if($recordings->isEmpty())
    <div class="py-20 flex flex-col items-center justify-center gap-3">
        <svg class="w-10 h-10 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 010 12.728M16.463 8.288a5.25 5.25 0 010 7.424M6.75 8.25l4.72-4.72a.75.75 0 011.28.53v15.88a.75.75 0 01-1.28.53l-4.72-4.72H4.5a.75.75 0 01-.75-.75V9a.75.75 0 01.75-.75h2.25z"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">No call recordings uploaded for this range yet.</p>
        <p class="text-xs font-mono text-slate-300 dark:text-slate-600">Set up Phone Link + a system-audio recorder on a TSA's PC, and point it at the upload endpoint from Call Rotation.</p>
    </div>
    @else
    <div class="overflow-x-auto">
    <table class="w-full text-sm font-mono">
        <thead class="bg-slate-100 dark:bg-slate-700 border-b border-slate-200 dark:border-slate-700">
            <tr>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Recorded</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">TSA</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Lead</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Size</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Recording</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($recordings as $recording)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                <td class="px-4 py-3 text-slate-500 dark:text-slate-400 whitespace-nowrap">{{ $recording->recorded_at->format('M j, g:i A') }}</td>
                <td class="px-4 py-3 text-slate-600 dark:text-slate-300 whitespace-nowrap">{{ $recording->tsa?->display_name ?? '—' }}</td>
                <td class="px-4 py-3">
                    @if($recording->lead)
                    <a href="{{ route('calls.leads.show', $recording->lead) }}" class="text-primary hover:underline">{{ $recording->lead->customer_name ?: '#'.$recording->lead->pancake_order_id }}</a>
                    @else
                    <span class="text-slate-300 dark:text-slate-600">no match</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-slate-500 dark:text-slate-400 whitespace-nowrap">{{ $recording->file_size_bytes ? number_format($recording->file_size_bytes / 1024 / 1024, 1) . ' MB' : '—' }}</td>
                <td class="px-4 py-3 min-w-[260px]">
                    <audio controls preload="none" class="w-full h-8" src="{{ route('calls.call-recordings.stream', $recording) }}"></audio>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    <div class="px-4 py-3 border-t border-slate-100 dark:border-slate-700">
        {{ $recordings->links('partials.pagination') }}
    </div>
    @endif
</div>

@endsection
