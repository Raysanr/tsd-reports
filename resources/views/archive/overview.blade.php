@extends('layouts.archive')
@section('title', 'Overview')
@section('subtitle', 'Your organization at a glance')

@php
    $card = 'rounded-2xl bg-white dark:bg-slate-900 ring-1 ring-line dark:ring-slate-800 shadow-panel';
    $fmtBytes = function ($b) { foreach (['B','KB','MB','GB'] as $u) { if ($b < 1024 || $u === 'GB') return ($u === 'B' ? $b : number_format($b, 1)) . ' ' . $u; $b /= 1024; } };
    $tiles = [
        ['Teams', $stats['departments'], 'archive.departments', 'from-yellow-500 to-yellow-400', 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M3 3h12m-.75 4.5H21'],
        ['People', $stats['employees'], 'archive.people', 'from-orange-500 to-orange-400', 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0z'],
        ['Documents', $stats['documents'], 'archive.documents', 'from-emerald-500 to-emerald-400', 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z'],
        ['TSAs working now', $tsaSummary['working'], 'archive.live-tsa', 'from-amber-500 to-amber-400', 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z'],
    ];
@endphp

@section('content')
<div class="space-y-6">
    {{-- Hero --}}
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-sidebar via-sidebar to-yellow-950 p-6 md:p-8 text-white shadow-panel">
        <div class="pointer-events-none absolute -right-16 -top-16 h-64 w-64 rounded-full bg-yellow-500/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-20 right-40 h-56 w-56 rounded-full bg-amber-500/15 blur-3xl"></div>
        <div class="relative flex flex-col md:flex-row md:items-end md:justify-between gap-6">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-yellow-300">{{ now()->format('l, F j') }}</p>
                <h2 class="mt-2 text-2xl md:text-3xl font-bold tracking-tight">Welcome back, {{ strtok(auth()->user()->name, ' ') }}</h2>
                <p class="mt-2 max-w-xl text-sm text-slate-300">Manage teams, keep every file in one place, and watch your TSA floor live.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('archive.documents') }}?upload=1" class="inline-flex items-center gap-2 rounded-lg bg-white px-3.5 py-2 text-sm font-semibold text-slate-900 shadow-sm hover:bg-yellow-50">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                    Upload files
                </a>
                <a href="{{ route('archive.org-chart') }}" class="inline-flex items-center gap-2 rounded-lg bg-white/10 px-3.5 py-2 text-sm font-semibold text-white ring-1 ring-inset ring-white/20 hover:bg-white/20">View org chart</a>
            </div>
        </div>
    </div>

    {{-- Stat tiles --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach($tiles as [$label, $value, $route, $gradient, $icon])
        <a href="{{ route($route) }}" class="{{ $card }} group p-5 transition hover:-translate-y-0.5 hover:shadow-panel-lift">
            <div class="flex items-start justify-between">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br {{ $gradient }} flex items-center justify-center shadow-sm">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
                </div>
                <svg class="w-4 h-4 text-slate-300 group-hover:text-yellow-500 transition" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25"/></svg>
            </div>
            <div class="mt-4 text-2xl md:text-3xl font-bold tabular-nums tracking-tight">{{ number_format($value) }}</div>
            <div class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ $label }}</div>
        </a>
        @endforeach
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        {{-- Top TSAs this month (net income from Data Management) --}}
        <section class="{{ $card }} xl:col-span-2 p-5 md:p-6">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h3 class="font-semibold">Top TSAs this month</h3>
                    <p class="text-xs text-ink-muted dark:text-slate-400 mt-0.5">Ranked by net income · {{ now()->format('F') }} 1–{{ now()->format('j') }}</p>
                </div>
                <a href="{{ route('archive.live-tsa') }}" class="text-sm font-medium text-primary dark:text-yellow-400 hover:underline">Open scoreboard →</a>
            </div>
            <ul class="mt-5 divide-y divide-line dark:divide-slate-800">
                @forelse($topTsas as $i => $t)
                <li class="flex items-center gap-3 py-3">
                    <span class="w-6 h-6 shrink-0 rounded-full flex items-center justify-center text-xs font-bold tabular-nums {{ $i === 0 ? 'bg-yellow-400 text-black' : ($i < 3 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-500/15 dark:text-yellow-300' : 'text-ink-muted') }}">{{ $i + 1 }}</span>
                    @include('archive.partials.avatar', ['name' => $t['name'], 'size' => 'sm', 'photo' => $t['photo'] ?? null])
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-sm font-medium truncate">{{ $t['name'] }}</div>
                            <div class="text-sm font-bold tabular-nums {{ $t['month_net'] < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-400' }}">₱{{ number_format($t['month_net'], 2) }}</div>
                        </div>
                        <div class="mt-0.5 flex items-center justify-between gap-3 text-xs text-ink-muted dark:text-slate-400">
                            <span class="truncate">{{ $t['team'] ?? 'No team' }}</span>
                            <span class="tabular-nums">Sales ₱{{ number_format($t['month_sales'], 2) }}</span>
                        </div>
                        <div class="mt-1.5 h-1.5 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                            <div class="h-full rounded-full bg-gradient-to-r from-yellow-500 to-yellow-400" style="width: {{ max(0, round($t['month_net'] / $maxNet * 100)) }}%"></div>
                        </div>
                    </div>
                </li>
                @empty
                <li class="py-6 text-center text-sm text-ink-muted">No active TSAs yet.</li>
                @endforelse
            </ul>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-yellow-50 dark:bg-yellow-500/10 px-4 py-3 text-sm">
                <span class="text-yellow-800 dark:text-yellow-200">Team total this month · {{ $monthTotal['tsas'] }} TSAs · Sales ₱{{ number_format($monthTotal['sales'], 2) }}</span>
                <span class="font-bold tabular-nums text-yellow-900 dark:text-yellow-100">Net ₱{{ number_format($monthTotal['net'], 2) }}</span>
            </div>
        </section>

        {{-- Headcount by department --}}
        <section class="{{ $card }} p-5 md:p-6">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold">Headcount</h3>
                <a href="{{ route('archive.departments') }}" class="text-sm font-medium text-primary dark:text-yellow-400 hover:underline">Manage</a>
            </div>
            <ul class="mt-5 space-y-4">
                @forelse($departments as $d)
                <li>
                    <div class="flex items-center justify-between text-sm">
                        <span class="flex items-center gap-2 font-medium"><span class="h-2.5 w-2.5 rounded-full {{ $d->colorClasses()['dot'] }}"></span>{{ $d->name }}</span>
                        <span class="tabular-nums text-ink-muted dark:text-slate-400">{{ $d->employees_count }}</span>
                    </div>
                    <div class="mt-1.5 h-2 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                        <div class="h-full rounded-full bg-gradient-to-r {{ $d->colorClasses()['bar'] }}" style="width: {{ round($d->employees_count / $maxHeadcount * 100) }}%"></div>
                    </div>
                </li>
                @empty
                <li class="text-sm text-ink-muted">No teams yet. <a class="text-primary hover:underline" href="{{ route('archive.departments') }}">Create one</a>.</li>
                @endforelse
            </ul>
        </section>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <section class="{{ $card }} p-5 md:p-6">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold">Recent uploads</h3>
                <span class="text-xs text-ink-muted dark:text-slate-400">{{ $fmtBytes($stats['storage']) }} stored</span>
            </div>
            <ul class="mt-4 space-y-2">
                @forelse($recentDocuments as $doc)
                @php [$typeLabel, $typeClass] = $doc->typeMeta(); @endphp
                <li>
                    <a href="{{ route('archive.documents.download', $doc) }}" class="flex items-center gap-3 rounded-xl p-2 -mx-2 hover:bg-slate-50 dark:hover:bg-slate-800/60">
                        <span class="w-10 h-10 shrink-0 rounded-lg {{ $typeClass }} flex items-center justify-center text-[10px] font-bold">{{ $typeLabel }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium truncate">{{ $doc->title }}</div>
                            <div class="text-xs text-ink-muted dark:text-slate-400 truncate">{{ \App\Models\ArchiveDocument::CATEGORIES[$doc->category] ?? $doc->category }}@if($doc->department) · {{ $doc->department->name }}@endif · {{ $doc->created_at->diffForHumans() }}</div>
                        </div>
                        <span class="text-xs text-ink-muted tabular-nums">{{ $doc->humanSize() }}</span>
                    </a>
                </li>
                @empty
                <li class="py-6 text-center text-sm text-ink-muted">Nothing uploaded yet.</li>
                @endforelse
            </ul>
        </section>

        <section class="{{ $card }} p-5 md:p-6">
            <h3 class="font-semibold">Expiring within 30 days</h3>
            <ul class="mt-4 space-y-2">
                @forelse($expiring as $doc)
                @php $days = (int) today()->diffInDays($doc->expires_at, false); @endphp
                <li class="flex items-center gap-3 rounded-xl p-2 -mx-2">
                    <span class="w-10 h-10 shrink-0 rounded-lg flex flex-col items-center justify-center {{ $days < 0 ? 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' }}">
                        <span class="text-[9px] font-semibold uppercase leading-none">{{ $doc->expires_at->format('M') }}</span>
                        <span class="text-sm font-bold leading-tight">{{ $doc->expires_at->format('j') }}</span>
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium truncate">{{ $doc->title }}</div>
                        <div class="text-xs text-ink-muted dark:text-slate-400 truncate">{{ $doc->employee?->full_name ?? 'No person linked' }}</div>
                    </div>
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $days < 0 ? 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30' : 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30' }}">
                        {{ $days < 0 ? 'Expired' : ($days === 0 ? 'Today' : "in {$days}d") }}
                    </span>
                </li>
                @empty
                <li class="py-6 text-center text-sm text-ink-muted">No documents expiring soon.</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
@endsection
