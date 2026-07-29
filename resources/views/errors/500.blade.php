@extends('layouts.auth')

@section('title', 'Something Went Wrong')
@section('brand_heading', 'Something went wrong on our end.')
@section('brand_subheading', "This wasn't caused by anything you did — try again in a moment, or check Sync Health if the issue seems data-related.")

@section('content')
<div class="text-center sm:text-left">
    <div class="w-14 h-14 rounded-full bg-amber-50 dark:bg-amber-950/40 flex items-center justify-center mx-auto sm:mx-0 mb-5">
        <svg class="w-7 h-7 text-amber-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
        </svg>
    </div>

    <p class="text-xs font-mono font-semibold text-amber-500 uppercase tracking-widest mb-2">Error 500</p>
    <h1 class="text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono mb-3">Something went wrong</h1>
    <p class="text-sm text-slate-500 dark:text-slate-400 leading-relaxed mb-8">
        An unexpected error occurred while loading this page. This wasn't caused by anything you did —
        try refreshing, and if it keeps happening, let an admin know.
    </p>

    <a href="{{ route('dashboard') }}"
       class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary hover:bg-primary-dark text-white text-sm font-semibold rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
        </svg>
        Back to Dashboard
    </a>
</div>
@endsection
