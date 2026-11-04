@extends('layouts.data')
@section('title', 'Expected Income (SH E-COM and Partners)')
@section('subtitle', 'SH E-COM and Partners expected performance side by side')

@section('content')
@php($rangeLabel = \Illuminate\Support\Carbon::parse($dateFrom)->format('F j, Y') . ($dateFrom === $dateTo ? '' : ' – ' . \Illuminate\Support\Carbon::parse($dateTo)->format('F j, Y')))
<div class="mb-6 flex items-end justify-between gap-4 flex-wrap">
    <form method="GET" class="flex items-end gap-3 flex-wrap">
        @include('data._date-range-filter', ['fromName' => 'date_from', 'toName' => 'date_to', 'fromValue' => $dateFrom, 'toValue' => $dateTo])
    </form>
    @include('partials.table-actions', ['target' => 'overallIncomeScroller', 'name' => 'sh-ecom-and-partners-expected-income', 'title' => 'SH E-COM and Partners Expected Performance', 'subtitle' => $rangeLabel, 'pngOnly' => true])
</div>

@include('data.overall._partners-warning')

<div class="overflow-x-auto ei-scroller -mx-4 md:-mx-8 px-4 md:px-8 pb-2" id="overallIncomeScroller">
    <div class="flex items-start gap-5 w-max">
        @foreach($cardsHtml as $html)
        {!! $html !!}
        @endforeach
    </div>
</div>
@endsection
