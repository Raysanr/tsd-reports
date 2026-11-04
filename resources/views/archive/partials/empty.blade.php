<div class="flex flex-col items-center justify-center text-center px-6 py-14">
    <div class="w-14 h-14 rounded-2xl bg-yellow-50 dark:bg-yellow-500/10 flex items-center justify-center mb-4 ring-1 ring-yellow-100 dark:ring-yellow-500/20">
        <svg class="w-7 h-7 text-yellow-500" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon ?? 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z' }}"/></svg>
    </div>
    <h3 class="font-semibold">{{ $title }}</h3>
    @isset($text)<p class="mt-1 max-w-sm text-sm text-ink-muted dark:text-slate-400">{{ $text }}</p>@endisset
    {{ $slot ?? '' }}
</div>
