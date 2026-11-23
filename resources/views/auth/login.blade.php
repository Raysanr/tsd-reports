@extends('layouts.auth')
@section('title', 'Sign In')
@section('brand_heading', 'Welcome back.')
@section('brand_subheading', 'Sign in to see today\'s called leads, dispositions, and TSA performance.')

@section('content')

<h2 class="text-xl font-bold font-mono text-slate-800 dark:text-slate-100 mb-1">Sign in</h2>
<p class="text-sm text-slate-400 font-mono mb-7">Use your work Google account to continue.</p>

@if(session('status'))
<div class="mb-5 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 px-4 py-2.5 text-xs font-mono text-emerald-700 dark:text-emerald-400">
    {{ session('status') }}
</div>
@endif
@error('email')
<div class="mb-5 rounded-lg bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 px-4 py-2.5 text-xs font-mono text-red-700 dark:text-red-400" role="alert">{{ $message }}</div>
@enderror

<a href="{{ route('google.redirect') }}"
   class="w-full flex items-center justify-center gap-2.5 px-4 py-2.5 border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm font-semibold font-mono text-slate-700 dark:text-slate-200 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 hover:border-slate-300 dark:hover:border-slate-600 transition-colors">
    <svg class="w-4.5 h-4.5" viewBox="0 0 24 24" aria-hidden="true">
        <path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47c-.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z"/>
        <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96H1.29v3.09C3.26 21.3 7.31 24 12 24z"/>
        <path fill="#FBBC05" d="M5.27 14.29c-.25-.72-.38-1.49-.38-2.29s.14-1.57.38-2.29V6.62H1.29A11.86 11.86 0 000 12c0 1.94.47 3.76 1.29 5.38l3.98-3.09z"/>
        <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.62l3.98 3.09C6.22 6.86 8.87 4.75 12 4.75z"/>
    </svg>
    Continue with Google
</a>

@endsection
