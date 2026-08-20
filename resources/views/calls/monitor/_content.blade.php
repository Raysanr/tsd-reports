{{--
    Monitor TSA's pollable content (explicit request, 2026-08-20) — split out
    from monitor.blade.php exactly like Leads' own index/_table split, so
    MonitorController::index() can return just this partial on an
    X-Table-Refresh poll (see monitor.blade.php's own inline script) without
    the toolbar/search box above it ever being touched — retyping a search
    box mid-poll would be a bad time.

    Status color classes are computed HERE, not in a PHP model/controller
    file — this app's Tailwind content scanning only covers resources/**
    (see resources/css/calls.css's @source lines), so a literal class string
    living in app/Models/TsaShift.php would never be picked up by the
    build. Same reasoning Dashboard's own $statusDot/$statusBadge closures
    already follow (dashboard.blade.php) — this is a 3rd, more granular
    variant (9 distinct statuses, not just login/logout/locked/other) since
    Monitor's whole point is telling every status apart at a glance.
--}}
@php
    $statusDotClass = fn (string $s) => match ($s) {
        \App\Models\TsaShift::STATUS_LOGIN      => 'bg-emerald-500',
        \App\Models\TsaShift::STATUS_CALLING    => 'bg-red-500',
        \App\Models\TsaShift::STATUS_WRAP_UP    => 'bg-orange-500',
        \App\Models\TsaShift::STATUS_BREAK      => 'bg-yellow-400',
        \App\Models\TsaShift::STATUS_LUNCH      => 'bg-amber-800',
        \App\Models\TsaShift::STATUS_COACHING   => 'bg-blue-500',
        \App\Models\TsaShift::STATUS_DNA_HUDDLE => 'bg-purple-500',
        \App\Models\TsaShift::STATUS_HUDDLE     => 'bg-sky-400',
        \App\Models\TsaShift::STATUS_OTHERS     => 'bg-slate-500',
        \App\Models\TsaShift::STATUS_LOGOUT     => 'bg-slate-300 dark:bg-slate-600',
        \App\Models\TsaShift::STATUS_LOCKED     => 'bg-red-700',
        default => 'bg-slate-400',
    };
    $statusBadgeClass = fn (string $s) => match ($s) {
        \App\Models\TsaShift::STATUS_LOGIN      => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400',
        \App\Models\TsaShift::STATUS_CALLING    => 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400',
        \App\Models\TsaShift::STATUS_WRAP_UP    => 'bg-orange-100 dark:bg-orange-900/40 text-orange-700 dark:text-orange-400',
        \App\Models\TsaShift::STATUS_BREAK      => 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-400',
        \App\Models\TsaShift::STATUS_LUNCH      => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-400',
        \App\Models\TsaShift::STATUS_COACHING   => 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-400',
        \App\Models\TsaShift::STATUS_DNA_HUDDLE => 'bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-400',
        \App\Models\TsaShift::STATUS_HUDDLE     => 'bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-400',
        \App\Models\TsaShift::STATUS_OTHERS     => 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
        \App\Models\TsaShift::STATUS_LOGOUT     => 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
        \App\Models\TsaShift::STATUS_LOCKED     => 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-400',
        default => 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
    };
    $statusBorderClass = fn (string $s) => match ($s) {
        \App\Models\TsaShift::STATUS_LOGIN      => 'border-t-emerald-500',
        \App\Models\TsaShift::STATUS_CALLING    => 'border-t-red-500',
        \App\Models\TsaShift::STATUS_WRAP_UP    => 'border-t-orange-500',
        \App\Models\TsaShift::STATUS_BREAK      => 'border-t-yellow-400',
        \App\Models\TsaShift::STATUS_LUNCH      => 'border-t-amber-800',
        \App\Models\TsaShift::STATUS_COACHING   => 'border-t-blue-500',
        \App\Models\TsaShift::STATUS_DNA_HUDDLE => 'border-t-purple-500',
        \App\Models\TsaShift::STATUS_HUDDLE     => 'border-t-sky-400',
        \App\Models\TsaShift::STATUS_OTHERS     => 'border-t-slate-500',
        \App\Models\TsaShift::STATUS_LOGOUT     => 'border-t-slate-300',
        \App\Models\TsaShift::STATUS_LOCKED     => 'border-t-red-700',
        default => 'border-t-slate-400',
    };

    // "3 min" below an hour, "20h 2m" at/above — same threshold and shape
    // for current-status-time, every daily-record line, and the total.
    // abs(): Carbon's diffInSeconds() returns a SIGNED value depending on
    // which of the two instants is earlier (confirmed live, 2026-08-20 —
    // an earlier version of this used max(0, ...) here instead, which
    // silently clamped every negative diff to 0 rather than surfacing that
    // the sign was wrong, making every "Current status time" read as a
    // flat 0 min regardless of how long a TSA had actually been in that
    // status).
    $formatSeconds = function (int $totalSeconds) {
        $totalMinutes = intdiv(abs($totalSeconds), 60);
        if ($totalMinutes < 60) {
            return $totalMinutes . ' min';
        }
        return intdiv($totalMinutes, 60) . 'h ' . ($totalMinutes % 60) . 'm';
    };

    // "Daily minute record" reads correctly for the common case (today);
    // once the date picker's actually used (explicit request, 2026-08-20),
    // the label says so instead of silently showing a past/other day's
    // numbers under a heading that still says "Daily".
    $isSingleDay = $dateFrom->isSameDay($dateTo);
    $dailyRecordLabel = $isSingleDay && $dateFrom->isToday()
        ? 'Daily minute record'
        : ($isSingleDay
            ? 'Minute record — ' . $dateFrom->format('M j, Y')
            : 'Minute record — ' . $dateFrom->format('M j') . ' to ' . $dateTo->format('M j, Y'));
@endphp

<div class="mb-6 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-2xl px-5 py-3 text-sm font-mono text-slate-600 dark:text-slate-300">
    <strong class="text-slate-800 dark:text-slate-100">Automatic time tracking:</strong> every status accumulates minutes while active. This is a live view only —
    status changes happen from TSA Management or a TSA's own topbar dropdown, and show up here automatically.
    <strong class="text-slate-800 dark:text-slate-100">Calling</strong> starts the moment a lead's number is clicked, and
    <strong class="text-slate-800 dark:text-slate-100">Wrap Up</strong> starts automatically once that call ends —
    neither is ever set by hand. Default automatic wrap-up duration: <strong class="text-slate-800 dark:text-slate-100">{{ $wrapUpSeconds }} seconds</strong>.
</div>

<div class="flex flex-wrap items-center gap-x-5 gap-y-2 mb-4 text-xs font-mono font-semibold text-slate-600 dark:text-slate-300">
    @foreach(\App\Models\TsaShift::MONITOR_LEGEND_STATUSES as $s)
    <span class="flex items-center gap-1.5">
        <span class="w-2 h-2 rounded-full shrink-0 {{ $statusDotClass($s) }}"></span>
        {{ strtoupper(\App\Models\TsaShift::STATUSES[$s]['label']) }}
    </span>
    @endforeach
</div>

<div class="grid grid-cols-3 sm:grid-cols-5 lg:grid-cols-9 gap-3 mb-6">
    @foreach(\App\Models\TsaShift::MONITOR_LEGEND_STATUSES as $s)
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 px-2 py-3 text-center">
        <p class="flex items-center justify-center gap-1.5 text-[9px] font-bold font-mono uppercase tracking-wide text-slate-400 mb-1 truncate">
            <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $statusDotClass($s) }}"></span>
            {{ \App\Models\TsaShift::STATUSES[$s]['label'] }}
        </p>
        <p class="text-xl font-bold text-slate-800 dark:text-slate-100 font-mono">{{ $statusCounts[$s] ?? 0 }}</p>
    </div>
    @endforeach
</div>

@if($tsas->isEmpty())
<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 py-16 text-center text-sm font-mono text-slate-400">
    No TSAs match this search/filter.
</div>
@else
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
    @foreach($tsas as $tsa)
    @php
        $tsaSeconds = $dailyRecords[$tsa->id] ?? [];
        $statusSecondsElapsed = $tsa->status_changed_at ? now('Asia/Manila')->diffInSeconds($tsa->status_changed_at) : 0;
    @endphp
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 border-t-4 {{ $statusBorderClass($tsa->status) }} shadow-sm p-5">
        <div class="flex items-start justify-between gap-3 mb-4">
            <div class="min-w-0">
                <p class="font-bold text-slate-800 dark:text-slate-100 truncate">{{ $tsa->display_name }}</p>
                <p class="text-xs text-slate-400 font-mono truncate">{{ $tsa->team }}</p>
            </div>
            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide shrink-0 {{ $statusBadgeClass($tsa->status) }}">
                <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $statusDotClass($tsa->status) }}"></span>
                {{ \App\Models\TsaShift::STATUSES[$tsa->status]['label'] ?? $tsa->status }}
            </span>
        </div>

        <div class="mb-4">
            <p class="text-[10px] text-slate-400 font-mono uppercase tracking-wide">Current status time</p>
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono">{{ $formatSeconds($statusSecondsElapsed) }}</p>
        </div>

        @if($tsa->status === \App\Models\TsaShift::STATUS_CALLING)
        <form method="POST" action="{{ route('calls.monitor.end-call', $tsa) }}" class="monitor-end-call-form mb-4">
            @csrf
            <button type="submit" class="w-full flex items-center justify-center gap-1.5 text-xs font-bold font-mono uppercase tracking-wide text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800 rounded-lg px-3 py-2 cursor-pointer hover:bg-red-100 dark:hover:bg-red-900/40">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 4.5v5.25h5.25M19.5 19.5v-5.25h-5.25M4.5 9.75L9 5.25M19.5 14.25L15 18.75"/>
                </svg>
                End Call &rarr; Auto Wrap Up
            </button>
        </form>
        @endif

        <div class="border-t border-slate-100 dark:border-slate-700 pt-3">
            <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">{{ strtoupper($dailyRecordLabel) }}</p>
            <div class="space-y-1.5">
                @foreach(\App\Models\TsaShift::MONITOR_LEGEND_STATUSES as $recStatus)
                <div class="flex items-center justify-between text-xs font-mono">
                    <span class="flex items-center gap-1.5 text-slate-600 dark:text-slate-300">
                        <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $statusDotClass($recStatus) }}"></span>
                        {{ \App\Models\TsaShift::STATUSES[$recStatus]['label'] }}
                    </span>
                    <span class="text-slate-700 dark:text-slate-200 font-semibold">{{ $formatSeconds($tsaSeconds[$recStatus] ?? 0) }}</span>
                </div>
                @endforeach
            </div>
            <div class="flex items-center justify-between text-xs font-mono font-bold border-t border-slate-100 dark:border-slate-700 mt-2 pt-2">
                <span class="text-slate-700 dark:text-slate-200">Total tracked</span>
                <span class="text-slate-800 dark:text-slate-100">{{ $formatSeconds(array_sum($tsaSeconds)) }}</span>
            </div>
        </div>
    </div>
    @endforeach
</div>
@endif
