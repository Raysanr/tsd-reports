@extends('layouts.auth')

@section('title', 'Access Denied')
@section('brand_heading', "You're signed in — just not cleared for this page.")
@section('brand_subheading', 'Config pages (TSA/Product/User Management, Settings) are admin-only. Ask an admin if you need access.')

@section('content')
<div class="text-center sm:text-left">
    <div class="w-14 h-14 rounded-full bg-rose-50 dark:bg-rose-950/40 flex items-center justify-center mx-auto sm:mx-0 mb-5">
        <svg class="w-7 h-7 text-rose-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
        </svg>
    </div>

    <p class="text-xs font-mono font-semibold text-rose-500 uppercase tracking-widest mb-2">Error 403</p>
    <h1 class="text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono mb-3">Access denied</h1>
    <p class="text-sm text-slate-500 dark:text-slate-400 leading-relaxed mb-8">
        Your account doesn't have permission to view this page — this section is restricted to admins.
        If you think this is a mistake, ask an admin to check your role in User Management.
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
