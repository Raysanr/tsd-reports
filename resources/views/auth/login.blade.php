@extends('layouts.auth')
@section('title', 'Sign In')
@section('brand_heading', 'Welcome back.')
@section('brand_subheading', 'Sign in to see today\'s called leads, dispositions, and TSA performance.')

@section('content')

<h2 class="text-xl font-bold font-mono text-slate-800 mb-1">Sign in</h2>
<p class="text-sm text-slate-400 font-mono mb-7">Enter your credentials to continue.</p>

@if(session('status'))
<div class="mb-5 rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-2.5 text-xs font-mono text-emerald-700">
    {{ session('status') }}
</div>
@endif

<form method="POST" action="{{ route('login') }}" class="space-y-5" id="loginForm" novalidate>
    @csrf

    <div>
        <label for="email" class="block text-xs font-mono font-semibold text-slate-600 mb-1.5">Email address</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}"
               autocomplete="email" required
               @if(!$errors->has('email')) autofocus @endif
               class="w-full rounded-lg border {{ $errors->has('email') ? 'border-red-300 focus:border-red-400 focus:ring-red-100' : 'border-slate-200 focus:border-primary focus:ring-yellow-100' }} px-3.5 py-2.5 text-sm font-mono text-slate-800 focus:outline-none focus:ring-4 transition-colors">
        @error('email')
        <p class="mt-1.5 text-xs font-mono text-red-600" role="alert">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <div class="flex items-center justify-between mb-1.5">
            <label for="password" class="block text-xs font-mono font-semibold text-slate-600">Password</label>
        </div>
        <div class="relative">
            <input type="password" id="password" name="password"
                   autocomplete="current-password" required
                   @if($errors->has('password') && !$errors->has('email')) autofocus @endif
                   class="w-full rounded-lg border {{ $errors->has('password') ? 'border-red-300 focus:border-red-400 focus:ring-red-100' : 'border-slate-200 focus:border-primary focus:ring-yellow-100' }} px-3.5 py-2.5 pr-11 text-sm font-mono text-slate-800 focus:outline-none focus:ring-4 transition-colors">
            <button type="button" data-toggle-password="password"
                    aria-label="Show password"
                    class="absolute right-0 top-0 h-full w-11 flex items-center justify-center text-slate-400 hover:text-slate-600 cursor-pointer">
                <svg class="w-4.5 h-4.5" data-icon-show fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639l4.43-6.573a1 1 0 011.66 0l4.43 6.573a1.012 1.012 0 010 .639l-4.43 6.573a1 1 0 01-1.66 0l-4.43-6.573z" style="display:none"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <svg class="w-4.5 h-4.5 hidden" data-icon-hide fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/>
                </svg>
            </button>
        </div>
        @error('password')
        <p class="mt-1.5 text-xs font-mono text-red-600" role="alert">{{ $message }}</p>
        @enderror
    </div>

    <label class="flex items-center gap-2 text-xs font-mono text-slate-500 cursor-pointer select-none">
        <input type="checkbox" name="remember" value="1"
               class="rounded border-slate-300 text-primary focus:ring-yellow-400 cursor-pointer">
        Keep me signed in
    </label>

    <button type="submit" id="loginSubmit"
            class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-accent text-white text-sm font-semibold font-mono rounded-lg hover:bg-yellow-800 transition-colors cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed">
        <svg id="loginSpinner" class="hidden w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        <span id="loginSubmitLabel">Sign in</span>
    </button>
</form>

<p class="mt-7 text-center text-xs font-mono text-slate-400">
    Don't have an account?
    <a href="{{ route('register') }}" class="text-accent font-semibold hover:underline">Sign up</a>
</p>

@endsection

@push('scripts')
<script>
(function () {
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById(btn.getAttribute('data-toggle-password'));
            const showIcon = btn.querySelector('[data-icon-show]');
            const hideIcon = btn.querySelector('[data-icon-hide]');
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            showIcon.classList.toggle('hidden', isHidden);
            hideIcon.classList.toggle('hidden', !isHidden);
            btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    });

    // Submit feedback: disable + spinner immediately, so a slow request never
    // reads as an unresponsive button (Forms & Feedback: Submit Feedback).
    const form = document.getElementById('loginForm');
    form?.addEventListener('submit', function () {
        document.getElementById('loginSubmit').disabled = true;
        document.getElementById('loginSpinner').classList.remove('hidden');
        document.getElementById('loginSubmitLabel').textContent = 'Signing in…';
    });
})();
</script>
@endpush
