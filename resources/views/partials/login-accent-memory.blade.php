{{-- Remembers this person's Settings → Appearance accent in the browser so
     the login page (which runs before anyone is signed in) can show the same
     color next time (2026-11-24). No custom accent = forget it, so the login
     falls back to its own gold. --}}
@php($loginAccent = strtoupper((string) auth()->user()?->theme_accent))
<script>try{@if(\App\Support\ThemeColor::isValid($loginAccent))localStorage.setItem('tsdLoginAccent','{{ $loginAccent }}');@else localStorage.removeItem('tsdLoginAccent');@endif}catch(e){}</script>
