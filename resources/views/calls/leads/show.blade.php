@extends('layouts.calls')
@section('title', $lead->customer_name ?: 'Lead #' . $lead->pancake_order_id)
@section('subtitle', 'Order #' . $lead->pancake_order_id)

@section('content')

<a href="{{ url()->previous() }}" class="inline-flex items-center gap-1.5 text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-100 mb-4">
    ← Back
</a>

{{-- Same content the modal version (openLeadModal(), see calls.js) shows —
     this full-page route stays for direct links/bookmarks, see
     LeadController::show()'s own comment. --}}
<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    @include('calls.leads._detail')
</div>

@include('calls.partials.modals')

@endsection
