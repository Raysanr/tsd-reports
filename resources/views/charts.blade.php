@extends('layouts.app')
@section('title', 'Charts')
@section('subtitle', 'Hourly Line Chart · Bar Chart · Group Sales')

@section('content')

<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">

    {{-- HOURLY LINE CHART --}}
    <div class="md:col-span-2 bg-white rounded-xl border border-slate-200 shadow-sm p-6">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h2 class="text-sm font-bold text-slate-700 font-mono">Hourly Line Chart</h2>
                <p class="text-xs text-slate-400 font-mono mt-0.5">Leads vs Accepted — Today</p>
            </div>
            <div class="flex items-center gap-4 text-xs font-mono">
                <span class="flex items-center gap-1.5">
                    <span class="w-3 h-0.5 bg-primary inline-block rounded"></span> Leads
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="w-3 h-0.5 bg-[#22c55e] inline-block rounded"></span> Accepted
                </span>
            </div>
        </div>
        <canvas id="lineChart" height="100"></canvas>
    </div>

    {{-- BAR CHART --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
        <div class="mb-5">
            <h2 class="text-sm font-bold text-slate-700 font-mono">Agent Sales — Bar Chart</h2>
            <p class="text-xs text-slate-400 font-mono mt-0.5">Total sales per agent today</p>
        </div>
        <canvas id="barChart" height="200"></canvas>
    </div>

    {{-- GROUP SALES DONUT --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
        <div class="mb-5">
            <h2 class="text-sm font-bold text-slate-700 font-mono">Group Sales</h2>
            <p class="text-xs text-slate-400 font-mono mt-0.5">SH Naturals vs Eyecare</p>
        </div>
        <div class="flex items-center gap-6">
            <canvas id="donutChart" width="180" height="180" class="shrink-0"></canvas>
            <div class="space-y-3">
                @php $groupColors = ['#0891B2', '#059669']; // cyan/emerald — matches the donut + Dashboard's Team Comparison @endphp
                @foreach($groupLabels as $i => $label)
                <div class="flex items-center gap-3">
                    <span class="w-3 h-3 rounded-sm shrink-0" style="background:{{ $groupColors[$i % count($groupColors)] }}"></span>
                    <div>
                        <p class="text-xs font-semibold text-slate-700 font-mono">{{ $label }}</p>
                        <p class="text-sm font-bold font-mono" style="color:{{ $groupColors[$i % count($groupColors)] }}">
                            ₱{{ number_format($groupSales[$i]) }}
                        </p>
                    </div>
                </div>
                @endforeach
                <div class="pt-2 border-t border-slate-100">
                    <p class="text-[10px] font-mono text-slate-400">Total</p>
                    <p class="text-base font-bold font-mono text-slate-800">
                        ₱{{ number_format(array_sum($groupSales)) }}
                    </p>
                </div>
            </div>
        </div>
    </div>

</div>

@endsection

@push('topbar-right')
<div class="flex items-center gap-4">

@if($selectedDate === now('Asia/Manila')->format('Y-m-d'))
@include('partials.live-indicator')
@endif

<form method="GET" action="{{ route('charts') }}" class="flex items-center gap-3">
    @include('partials.date-picker', ['mode' => 'single', 'id' => 'drp', 'date' => $selectedDate, 'submit' => 'form', 'dateField' => 'date'])
    <button type="submit"
            class="px-4 py-1.5 bg-primary text-white text-xs font-semibold rounded-lg
                   hover:bg-yellow-800 transition-colors cursor-pointer">
        Load
    </button>
</form>

</div>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script>
const labels  = @json($hourlyLabels);
const leads   = @json($hourlyLeads);
const accepted= @json($hourlyAccepted);
const agNames = @json($agentNames);
const agSales = @json($agentSales);
const grpLbls = @json($groupLabels);
const grpSals = @json($groupSales);

Chart.defaults.font.family = "'Fira Code', monospace";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#94a3b8';

/* Hourly Line */
new Chart(document.getElementById('lineChart'), {
    type: 'line',
    data: {
        labels,
        datasets: [
            {
                label: 'Leads',
                data: leads,
                borderColor: '#CA8A04',
                backgroundColor: 'rgba(202,138,4,0.08)',
                tension: 0.4, fill: true, pointRadius: 4,
                pointBackgroundColor: '#CA8A04', borderWidth: 2,
            },
            {
                label: 'Accepted',
                data: accepted,
                borderColor: '#22c55e',
                backgroundColor: 'rgba(34,197,94,0.06)',
                tension: 0.4, fill: true, pointRadius: 4,
                pointBackgroundColor: '#22c55e', borderWidth: 2,
            },
        ],
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
        scales: {
            x: { grid: { color: '#f1f5f9' } },
            y: { grid: { color: '#f1f5f9' }, beginAtZero: true },
        },
    },
});

/* Bar Chart */
new Chart(document.getElementById('barChart'), {
    type: 'bar',
    data: {
        labels: agNames,
        datasets: [{
            label: 'Sales (₱)',
            data: agSales,
            backgroundColor: ['#CA8A04','#EAB308','#FDE047','#854D0E','#A16207'],
            borderRadius: 5,
            borderSkipped: false,
        }],
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false } },
            y: { grid: { color: '#f1f5f9' }, beginAtZero: true,
                 ticks: { callback: v => '₱' + v.toLocaleString() } },
        },
    },
});

/* Donut */
new Chart(document.getElementById('donutChart'), {
    type: 'doughnut',
    data: {
        labels: grpLbls,
        datasets: [{
            data: grpSals,
            backgroundColor: ['#0891B2','#059669'], // cyan/emerald — same team colors as the Dashboard's Team Comparison cards
            borderWidth: 0,
            hoverOffset: 6,
        }],
    },
    options: {
        cutout: '68%',
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ' ₱' + ctx.raw.toLocaleString() } },
        },
    },
});
</script>
@endpush
