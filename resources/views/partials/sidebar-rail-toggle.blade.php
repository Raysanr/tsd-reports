{{-- Collapse/expand button floating on the sidebar's right edge — see
     partials.sidebar-rail-head. Must sit directly inside #sidebar (which is
     md:relative). --}}
<button id="sidebarCollapseToggle" type="button" aria-label="Collapse sidebar" title="Collapse sidebar"
        class="hidden md:flex absolute -right-3.5 top-16 z-10 items-center justify-center w-7 h-7 rounded-full
               bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-md
               text-slate-500 dark:text-slate-300 hover:text-primary hover:border-primary transition-colors cursor-pointer">
    <svg class="w-3.5 h-3.5 transition-transform duration-200" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
    </svg>
</button>
<script>
(() => {
    const root = document.documentElement;
    const btn = document.getElementById('sidebarCollapseToggle');
    const sidebar = btn.closest('#sidebar');

    // Labels are hidden when collapsed, so give each nav link a hover
    // tooltip with its name — only while collapsed, only ones we added.
    function syncTitles(collapsed) {
        sidebar.querySelectorAll('nav a').forEach((a) => {
            if (collapsed && !a.hasAttribute('title')) {
                a.setAttribute('title', a.textContent.replace(/\s+/g, ' ').trim());
                a.dataset.railTitle = '1';
            } else if (!collapsed && a.dataset.railTitle) {
                a.removeAttribute('title');
                delete a.dataset.railTitle;
            }
        });
    }
    function sync(collapsed) {
        const label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
        btn.setAttribute('aria-label', label);
        btn.setAttribute('title', label);
        syncTitles(collapsed);
    }

    document.addEventListener('DOMContentLoaded', () => sync(root.classList.contains('sidebar-collapsed')));
    btn.addEventListener('click', () => {
        const collapsed = root.classList.toggle('sidebar-collapsed');
        try { localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0'); } catch (e) {}
        sync(collapsed);
    });
})();
</script>
