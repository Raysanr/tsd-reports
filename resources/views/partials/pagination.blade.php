{{-- Custom pager, styled to match this app's slate/yellow theme instead of the
     vendor tailwind.blade.php default (gray surfaces, blue focus ring) — used via
     $paginator->links('partials.pagination'). Laravel's default Tailwind pagination
     view DOES ship its own dark: classes, so it isn't visually broken as-is, but its
     palette doesn't follow this app's established dark-mode convention
     (resources/css/app.css) — this partial does, so a paginated table doesn't look
     like a different app bolted onto this one. --}}
@if ($paginator->hasPages())
<nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex flex-col sm:flex-row items-center justify-between gap-3">

    <p class="text-xs font-mono text-slate-400">
        Showing
        <span class="font-semibold text-slate-600 dark:text-slate-300">{{ $paginator->firstItem() ?? 0 }}</span>
        to
        <span class="font-semibold text-slate-600 dark:text-slate-300">{{ $paginator->lastItem() ?? 0 }}</span>
        of
        <span class="font-semibold text-slate-600 dark:text-slate-300">{{ $paginator->total() }}</span>
        results
    </p>

    <div class="flex items-center gap-1">
        {{-- Previous --}}
        @if ($paginator->onFirstPage())
        <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}"
              class="inline-flex items-center px-2.5 py-1.5 text-xs font-mono text-slate-300 dark:text-slate-600 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg cursor-not-allowed">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </span>
        @else
        <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination.previous') }}"
           class="inline-flex items-center px-2.5 py-1.5 text-xs font-mono text-slate-500 dark:text-slate-400 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </a>
        @endif

        {{-- Page numbers --}}
        @foreach ($elements as $element)
            @if (is_string($element))
            <span aria-disabled="true" class="inline-flex items-center px-3 py-1.5 text-xs font-mono text-slate-400">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                    <span aria-current="page"
                          class="inline-flex items-center px-3 py-1.5 text-xs font-mono font-bold text-white bg-yellow-600 rounded-lg">{{ $page }}</span>
                    @else
                    <a href="{{ $url }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}"
                       class="inline-flex items-center px-3 py-1.5 text-xs font-mono text-slate-600 dark:text-slate-300 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination.next') }}"
           class="inline-flex items-center px-2.5 py-1.5 text-xs font-mono text-slate-500 dark:text-slate-400 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </a>
        @else
        <span aria-disabled="true" aria-label="{{ __('pagination.next') }}"
              class="inline-flex items-center px-2.5 py-1.5 text-xs font-mono text-slate-300 dark:text-slate-600 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg cursor-not-allowed">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </span>
        @endif
    </div>
</nav>
@endif
