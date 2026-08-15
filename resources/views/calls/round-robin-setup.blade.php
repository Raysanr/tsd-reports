@extends('layouts.calls')
@section('title', 'Round Robin Setup')
@section('subtitle', "Per-TSA daily lead cap — round-robin skips a TSA once they've hit it today")

@section('content')

<div class="space-y-4">
    @foreach($tsas as $row)
        @php $tsa = $row['tsa']; $assigned = $row['assigned_today']; $cap = $tsa->daily_lead_cap; @endphp
        <form method="POST" action="{{ route('calls.round-robin-setup.update', $tsa) }}"
              class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-6 flex items-center justify-between gap-6 flex-wrap">
            @csrf

            <div class="min-w-0">
                <h2 class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono">{{ $tsa->display_name }}</h2>
                <p class="text-[11px] text-slate-400 font-mono mt-0.5">{{ $tsa->tsa_key }} · {{ $tsa->team }}</p>
            </div>

            <div class="flex items-center gap-3 shrink-0">
                <div class="text-right">
                    <p class="text-[11px] font-mono font-semibold text-slate-400 uppercase tracking-wide">Assigned today</p>
                    <p class="text-lg font-bold font-mono {{ $cap !== null && $assigned >= $cap ? 'text-red-600 dark:text-red-400' : 'text-slate-800 dark:text-slate-100' }}">
                        {{ $assigned }}{{ $cap !== null ? ' / ' . $cap : '' }}
                    </p>
                </div>

                @if($cap !== null && $assigned >= $cap)
                <span class="text-[11px] font-mono font-bold text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 rounded-full px-3 py-1">
                    At cap — skipped by round-robin
                </span>
                @endif

                <div>
                    <label class="block text-[11px] font-mono font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1">Daily cap</label>
                    <input type="number" name="daily_lead_cap" min="1" value="{{ $cap }}" placeholder="Unlimited"
                           class="w-28 text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                </div>

                <button type="submit"
                        class="text-xs font-semibold text-white bg-primary hover:bg-primary-dark rounded-lg px-4 py-2 cursor-pointer self-end">
                    Save
                </button>
            </div>
        </form>
    @endforeach
</div>

@endsection
