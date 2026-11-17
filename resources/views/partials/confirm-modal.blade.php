{{-- Shared confirm modal — window.showConfirm(message, opts) in app.js.
     Included by layouts.app and layouts.hub-manage so both stay identical. --}}
<div id="confirmModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/40 px-4">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="px-6 py-5">
            <h3 id="confirmModalTitle" class="text-sm font-bold text-slate-800 dark:text-slate-100">Are you sure?</h3>
            <p id="confirmModalMessage" class="text-sm text-slate-600 dark:text-slate-400 mt-2"></p>
        </div>
        <div class="px-6 pb-5 flex items-center justify-end gap-2">
            <button type="button" id="confirmModalCancel"
                    class="px-3 py-2 text-xs font-mono text-slate-600 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-100 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                Cancel
            </button>
            <button type="button" id="confirmModalConfirm"
                    class="px-4 py-2 text-xs font-semibold text-white rounded-lg transition-colors cursor-pointer">
                Confirm
            </button>
        </div>
    </div>
</div>
