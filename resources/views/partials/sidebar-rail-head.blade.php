{{-- Collapsible sidebar (icon-only rail) for the Call Tracker, Data
     Management and Archiving layouts — same behaviour and same localStorage
     key ('sidebarCollapsed') as TSD Reports' own layouts/app.blade.php, so
     collapsing in one module carries over to every other. Include inside
     <head>; pair with partials.sidebar-rail-toggle as the first child of
     <aside id="sidebar" data-rail>. Desktop-only (md+), the mobile drawer
     always shows full labels.

     Markup-agnostic on purpose: nav labels in these layouts are bare text
     nodes, so they're hidden with font-size:0 instead of wrapping each one.
     Only the header/footer need hook classes: rail-header, rail-brand-text,
     rail-footer-row, rail-user-info. --}}
<script>
    if (localStorage.getItem('sidebarCollapsed') === '1') {
        document.documentElement.classList.add('sidebar-collapsed');
    }
</script>
<style>
    #sidebar[data-rail] { transition: transform 200ms ease-out, width 200ms ease-out; }
    #sidebar[data-rail] .rail-brand-text,
    #sidebar[data-rail] .rail-user-info {
        max-width: 14rem;
        opacity: 1;
        overflow: hidden;
        white-space: nowrap;
        transition: max-width 200ms ease-out, opacity 150ms ease-out;
    }
    @media (min-width: 768px) {
        html.sidebar-collapsed #sidebar[data-rail] { width: 5rem; }
        html.sidebar-collapsed #sidebar[data-rail] .rail-header { padding-left: 0.75rem; padding-right: 0.75rem; }
        html.sidebar-collapsed #sidebar[data-rail] .rail-header > div { justify-content: center; }
        html.sidebar-collapsed #sidebar[data-rail] .rail-brand-text,
        html.sidebar-collapsed #sidebar[data-rail] .rail-user-info { max-width: 0; opacity: 0; }
        html.sidebar-collapsed #sidebar[data-rail] .rail-header .gap-3 { gap: 0; }
        html.sidebar-collapsed #sidebar[data-rail] .rail-footer-row { flex-direction: column; justify-content: center; gap: 0.5rem; }

        /* Nav: hide section headings, shrink bare-text labels to nothing,
           centre the icons. */
        html.sidebar-collapsed #sidebar[data-rail] nav p { display: none; }
        html.sidebar-collapsed #sidebar[data-rail] nav .nav-item,
        html.sidebar-collapsed #sidebar[data-rail] nav .nav-item a,
        html.sidebar-collapsed #sidebar[data-rail] nav .nav-item span {
            font-size: 0;
            gap: 0;
            justify-content: center;
        }
        html.sidebar-collapsed #sidebar[data-rail] nav .nav-item { position: relative; }
        html.sidebar-collapsed #sidebar[data-rail] nav .nav-item a { padding-left: 0; padding-right: 0; }

        /* Count badges (Call Tracker) become small corner badges on the icon. */
        html.sidebar-collapsed #sidebar[data-rail] nav .nav-item span[id^="badge-"] {
            position: absolute;
            top: 2px;
            right: 4px;
            font-size: 9px;
            line-height: 1;
            padding: 2px 4px;
            min-width: 0;
        }

        /* Call Tracker's Leads submenu and Data Management's groups: no
           room for their expand/collapse controls, so show every item. */
        html.sidebar-collapsed #sidebar[data-rail] nav .nav-item > button,
        html.sidebar-collapsed #sidebar[data-rail] nav .nav-group-toggle { display: none; }
        html.sidebar-collapsed #sidebar[data-rail] #leadsNavSubmenu,
        html.sidebar-collapsed #sidebar[data-rail] .nav-group-body { grid-template-rows: 1fr !important; opacity: 1 !important; }
        html.sidebar-collapsed #sidebar[data-rail] #leadsNavSubmenuInner { opacity: 1 !important; margin-left: 0; padding-left: 0; border-left: 0; }
        html.sidebar-collapsed #sidebar[data-rail] .nav-group + .nav-group { margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid rgb(255 255 255 / 0.1); }

        html.sidebar-collapsed #sidebarCollapseToggle svg { transform: rotate(180deg); }
    }
</style>
