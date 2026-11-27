{{-- Daily Target bubble (2026-11-27) — floating progress bubble for a signed-in
     TSA on every module; built by resources/js/daily-target.js (own Vite entry,
     see its header for why). Admins never see it, same rule as the TSA status
     button. --}}
@if(auth()->check() && !auth()->user()->isAtLeastAdmin() && auth()->user()->tsa)
    <div id="dailyTargetRoot" hidden
         data-endpoint="{{ route('calls.tsa-status.own-target') }}"
         data-tsa="{{ auth()->user()->tsa_id }}"></div>
    @vite('resources/js/daily-target.js')
@endif
