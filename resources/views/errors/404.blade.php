@extends('layouts.auth')

@section('title', 'Page Not Found')
@section('brand_heading', "That page doesn't exist.")
@section('brand_subheading', 'Double-check the link, or head back to the Dashboard — everything else is one click away in the sidebar.')

@section('content')
<div class="text-center sm:text-left">
    <div class="w-14 h-14 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center mx-auto sm:mx-0 mb-5">
        <svg class="w-7 h-7 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"/>
        </svg>
    </div>

    <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-widest mb-2">Error 404</p>
    <h1 class="text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono mb-3">Page not found</h1>
    <p class="text-sm text-slate-500 dark:text-slate-400 leading-relaxed mb-8">
        The page you're looking for doesn't exist, moved, or the link might be mistyped.
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
