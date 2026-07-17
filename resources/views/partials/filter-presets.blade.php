{{--
    Saved filter presets — "Presets" dropdown trigger + empty panel shell for a
    report page's top-level filters (team, date range, product, ...). All the
    actual list rendering (save / apply / delete) is done client-side by the
    shared handlers in resources/js/app.js's "Saved filter presets" section —
    this partial only emits the trigger button (carrying the data attributes
    that JS reads) and an empty panel container for it to populate on open.

    Visual/structural pattern borrowed from partials/date-picker.blade.php:
    icon-only pill trigger + absolutely-positioned panel below it, click-
    outside-to-close.

    Props:
      key      Stable per-page identifier, e.g. 'leads-report' — the
               localStorage key suffix (filterPresets:<key>) and the value
               read back out via data-preset-key. Must be unique per page so
               one page's presets never bleed into another's list.
      baseUrl  This page's own URL (e.g. route('leads-report')) — applying a
               preset navigates to baseUrl + preset.query.
--}}
<div class="relative" data-preset-widget>
    <button type="button" data-preset-trigger data-preset-key="{{ $key }}" data-preset-base-url="{{ $baseUrl }}"
        aria-haspopup="true" aria-expanded="false" title="Saved filter presets" aria-label="Saved filter presets"
        class="relative inline-flex items-center justify-center w-8 h-8 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-full hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer shrink-0">
        <svg class="w-4 h-4 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z"/>
        </svg>
    </button>

    {{-- Populated by JS (renderPresetPanel in app.js) on open and after every
         save/delete — nothing server-rendered here since presets are
         localStorage-only and unknown at request time. --}}
    <div data-preset-panel class="hidden absolute right-0 top-full mt-2 z-50 bg-white dark:bg-slate-900 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 py-1" style="width:220px"></div>
</div>
