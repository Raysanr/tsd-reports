@extends('layouts.data')
@section('title', 'Overall Projections')
@section('subtitle', 'SH E-COM and Partners — monthly targets')

@section('content')
@php($monthLabel = \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->format('F Y'))
<div class="mb-6 flex items-end justify-between gap-4 flex-wrap">
    @include('data._month-picker', ['name' => 'month', 'value' => $month, 'navigate' => true])
    @include('partials.table-actions', ['target' => 'overallProjectionsScroller', 'name' => 'overall-projections-' . $month, 'title' => 'Telesales Expected Performance', 'subtitle' => $monthLabel, 'pngOnly' => true])
</div>

@include('data.overall._partners-warning')

<div class="overflow-x-auto ei-scroller -mx-4 md:-mx-8 px-4 md:px-8 pb-2" id="overallProjectionsScroller">
    <div class="flex items-start gap-5 w-max">
        @include('data.overall._pnl-card', ['p' => $total, 'label' => 'Telesales Expected Performance', 'headerBg' => 'var(--color-yellow-300)', 'headerText' => '#111827'])
        @foreach($cards as $card)
        @include('data.overall._pnl-card', ['p' => $card['pnl'], 'label' => $card['label'], 'headerBg' => '#d9ead3', 'headerText' => '#111827'])
        @endforeach
    </div>
</div>
@endsection
