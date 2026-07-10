@extends('layouts.auth')
@section('title', 'Sign Up')
@section('brand_heading', 'Join the team.')
@section('brand_subheading', 'Create an account to access called-leads reporting, TSA performance, and shift schedules.')

@section('content')

<h2 class="text-xl font-bold font-mono text-slate-800 mb-1">Create an account</h2>
<p class="text-sm text-slate-400 font-mono mb-7">A few details and you're in.</p>

<form method="POST" action="{{ route('register') }}" class="space-y-5" id="registerForm" novalidate>
    @csrf

    <div>
        <label for="name" class="block text-xs font-mono font-semibold text-slate-600 mb-1.5">Full name</label>
        <input type="text" id="name" name="name" value="{{ old('name') }}"
               autocomplete="name" required
               @if(!$errors->has('name')) autofocus @endif
               class="w-full rounded-lg border {{ $errors->has('name') ? 'border-red-300 focus:border-red-400 focus:ring-red-100' : 'border-slate-200 focus:border-primary focus:ring-yellow-100' }} px-3.5 py-2.5 text-sm font-mono text-slate-800 focus:outline-none focus:ring-4 transition-colors">
        @error('name')
        <p class="mt-1.5 text-xs font-mono text-red-600" role="alert">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="email" class="block text-xs font-mono font-semibold text-slate-600 mb-1.5">Email address</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}"
               autocomplete="email" required
               @if($errors->has('email') && !$errors->has('name')) autofocus @endif
               class="w-full rounded-lg border {{ $errors->has('email') ? 'border-red-300 focus:border-red-400 focus:ring-red-100' : 'border-slate-200 focus:border-primary focus:ring-yellow-100' }} px-3.5 py-2.5 text-sm font-mono text-slate-800 focus:outline-none focus:ring-4 transition-colors">
        @error('email')
        <p class="mt-1.5 text-xs font-mono text-red-600" role="alert">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="password" class="block text-xs font-mono font-semibold text-slate-600 mb-1.5">Password</label>
        <div class="relative">
            <input type="password" id="password" name="password"
                   autocomplete="new-password" required
                   class="w-full rounded-lg border {{ $errors->has('password') ? 'border-red-300 focus:border-red-400 focus:ring-red-100' : 'border-slate-200 focus:border-primary focus:ring-yellow-100' }} px-3.5 py-2.5 pr-11 text-sm font-mono text-slate-800 focus:outline-none focus:ring-4 transition-colors">
            <button type="button" data-toggle-password="password"
                    aria-label="Show password"
                    class="absolute right-0 top-0 h-full w-11 flex items-center justify-center text-slate-400 hover:text-slate-600 cursor-pointer">
                <svg class="w-4.5 h-4.5" data-icon-show fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <svg class="w-4.5 h-4.5 hidden" data-icon-hide fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/>
                </svg>
            </button>
        </div>
        <p class="mt-1.5 text-xs font-mono text-slate-400">At least 8 characters.</p>
        @error('password')
        <p class="mt-1.5 text-xs font-mono text-red-600" role="alert">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="password_confirmation" class="block text-xs font-mono font-semibold text-slate-600 mb-1.5">Confirm password</label>
        <input type="password" id="password_confirmation" name="password_confirmation"
               autocomplete="new-password" required
               class="w-full rounded-lg border border-slate-200 focus:border-primary px-3.5 py-2.5 text-sm font-mono text-slate-800 focus:outline-none focus:ring-4 focus:ring-yellow-100 transition-colors">
    </div>

    <button type="submit" id="registerSubmit"
            class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-accent text-white text-sm font-semibold font-mono rounded-lg hover:bg-yellow-800 transition-colors cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed">
        <svg id="registerSpinner" class="hidden w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        <span id="registerSubmitLabel">Create account</span>
    </button>
</form>

<div class="my-6 flex items-center gap-3">
    <div class="h-px flex-1 bg-slate-200"></div>
    <span class="text-[11px] font-mono uppercase tracking-wider text-slate-400">or</span>
    <div class="h-px flex-1 bg-slate-200"></div>
</div>

<a href="{{ route('google.redirect') }}"
   class="w-full flex items-center justify-center gap-2.5 px-4 py-2.5 border border-slate-200 bg-white text-sm font-semibold font-mono text-slate-700 rounded-lg hover:bg-slate-50 hover:border-slate-300 transition-colors">
    <svg class="w-4.5 h-4.5" viewBox="0 0 24 24" aria-hidden="true">
        <path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47c-.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z"/>
        <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96H1.29v3.09C3.26 21.3 7.31 24 12 24z"/>
        <path fill="#FBBC05" d="M5.27 14.29c-.25-.72-.38-1.49-.38-2.29s.14-1.57.38-2.29V6.62H1.29A11.86 11.86 0 000 12c0 1.94.47 3.76 1.29 5.38l3.98-3.09z"/>
        <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.62l3.98 3.09C6.22 6.86 8.87 4.75 12 4.75z"/>
    </svg>
    Continue with Google
</a>

<p class="mt-7 text-center text-xs font-mono text-slate-400">
    Already have an account?
    <a href="{{ route('login') }}" class="text-accent font-semibold hover:underline">Sign in</a>
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

    const form = document.getElementById('registerForm');
    form?.addEventListener('submit', function () {
        document.getElementById('registerSubmit').disabled = true;
        document.getElementById('registerSpinner').classList.remove('hidden');
        document.getElementById('registerSubmitLabel').textContent = 'Creating account…';
    });
})();
</script>
@endpush
