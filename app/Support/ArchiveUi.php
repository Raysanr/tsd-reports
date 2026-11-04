<?php

namespace App\Support;

/** Shared Tailwind class strings for the TSD Archiving System views —
 *  one place to restyle every input/button/card/modal in the module.
 *  Views pull them in with `@php extract(\App\Support\ArchiveUi::classes()); @endphp`. */
class ArchiveUi
{
    public static function classes(): array
    {
        return [
            'input'      => 'block w-full rounded-lg border-0 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-ink dark:text-slate-100 ring-1 ring-inset ring-line dark:ring-slate-700 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-yellow-500 outline-none',
            // Compact dropdown for filter bars — sizes to its content instead of
            // stretching (the shared 'input' is w-full, which beats a w-auto add-on).
            'filter'     => 'block w-auto min-w-40 max-w-64 rounded-lg border-0 bg-white dark:bg-slate-800 py-2 pl-3 pr-8 text-sm text-ink dark:text-slate-100 ring-1 ring-inset ring-line dark:ring-slate-700 focus:ring-2 focus:ring-inset focus:ring-yellow-500 outline-none cursor-pointer',
            'label'      => 'block text-xs font-semibold text-ink-muted dark:text-slate-400 mb-1.5',
            'btnPrimary' => 'inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary cursor-pointer',
            'btnGhost'   => 'inline-flex items-center justify-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium ring-1 ring-inset ring-line dark:ring-slate-700 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer',
            'card'       => 'rounded-2xl bg-white dark:bg-slate-900 ring-1 ring-line dark:ring-slate-800 shadow-panel',
            'modal'      => 'arc-modal m-auto w-full max-w-lg rounded-2xl bg-white dark:bg-slate-900 text-ink dark:text-slate-100 p-0 shadow-2xl ring-1 ring-black/5 dark:ring-white/10',
        ];
    }
}
