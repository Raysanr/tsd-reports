@extends('layouts.data')
@section('title', 'Month locks')
@section('subtitle', 'Close finished months so their numbers can\'t change')

@section('content')
<div class="max-w-3xl space-y-4">
    <p class="text-sm text-slate-600 dark:text-slate-300">
        A locked month is read-only on every Data Management page: Projections, DSPPR, TSA Sales,
        Expected and Actual Income, and both Cost Breakdowns. Unlock it here if something needs fixing.
        Only finished months can be locked. {{ \App\Support\DataSet::isPartners() ? 'These locks apply to Partners Data Management only.' : 'These locks apply to TSD Data Management only.' }}
    </p>

    <div class="bg-white dark:bg-slate-900 rounded-xl border border-yellow-100 dark:border-yellow-900 shadow-sm divide-y divide-slate-100 dark:divide-slate-800">
        @foreach($months as $m)
        @php $lock = $locks->get($m); @endphp
        <div class="flex flex-wrap items-center gap-3 px-5 py-3">
            <div class="flex-1 min-w-40">
                <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ \App\Support\DmMonthLock::label($m) }}</p>
                @if($lock)
                <p class="text-xs text-slate-500 dark:text-slate-400">Locked {{ \Illuminate\Support\Carbon::parse($lock->created_at)->diffForHumans() }}@if($lock->locked_by && isset($users[$lock->locked_by])) by {{ $users[$lock->locked_by] }}@endif</p>
                @else
                <p class="text-xs text-slate-500 dark:text-slate-400">Open — can still be edited</p>
                @endif
            </div>
            @if($lock)
            <span class="inline-flex items-center gap-1 rounded-full bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900 px-2.5 py-1 text-[11px] font-semibold">Locked</span>
            <form method="POST" action="{{ route('data.month-locks.destroy') }}" data-confirm-unlock="{{ \App\Support\DmMonthLock::label($m) }}">
                @csrf @method('DELETE')
                <input type="hidden" name="month" value="{{ $m }}">
                <button type="submit" class="rounded-lg px-3 py-1.5 text-xs font-semibold ring-1 ring-inset ring-slate-300 dark:ring-slate-600 hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer">Unlock</button>
            </form>
            @else
            <form method="POST" action="{{ route('data.month-locks.store') }}">
                @csrf
                <input type="hidden" name="month" value="{{ $m }}">
                <button type="submit" class="rounded-lg px-3 py-1.5 text-xs font-semibold bg-slate-900 text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 cursor-pointer">Lock month</button>
            </form>
            @endif
        </div>
        @endforeach
    </div>
</div>

@push('scripts')
<script>
document.querySelectorAll('[data-confirm-unlock]').forEach((f) => f.addEventListener('submit', (e) => {
    if (!confirm('Unlock ' + f.dataset.confirmUnlock + '? Its numbers can be changed again.')) e.preventDefault();
}));
</script>
@endpush
@endsection
