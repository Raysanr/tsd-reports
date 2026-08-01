@extends('layouts.auth')

@section('title', 'Too Many Attempts')
@section('brand_heading', "Slow down a moment.")
@section('brand_subheading', 'Too many sign-in attempts in a short time. This is a security limit, not an account lock — it clears itself after a minute.')

@section('content')
<div class="text-center sm:text-left">
    <div class="w-14 h-14 rounded-full bg-amber-50 dark:bg-amber-950/40 flex items-center justify-center mx-auto sm:mx-0 mb-5">
        <svg class="w-7 h-7 text-amber-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
    </div>

    <p class="text-xs font-mono font-semibold text-amber-500 uppercase tracking-widest mb-2">Error 429</p>
    <h1 class="text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono mb-3">Too many attempts</h1>
    <p class="text-sm text-slate-500 dark:text-slate-400 leading-relaxed mb-8">
        Too many sign-in attempts too quickly — wait about a minute, then try again.
    </p>

    <a href="{{ route('login') }}"
       class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary hover:bg-primary-dark text-white text-sm font-semibold rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
        </svg>
        Back to Sign In
    </a>
</div>
@endsection
