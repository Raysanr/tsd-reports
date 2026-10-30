{{-- Accent + sidebar colors chosen in Settings → Appearance (App\Support\ThemeColor).
     Include right after @vite in every layout so it overrides the @theme defaults.
     A light sidebar flips the menu to dark text (html.sidebar-light). --}}
@php($themeCss = \App\Support\ThemeColor::css())
@if($themeCss)<style id="themeColorVars">{!! $themeCss !!}</style>@endif
@if(\App\Support\ThemeColor::textRulesOn())<script>document.documentElement.classList.add('theme-text');</script>@endif
@if(\App\Support\ThemeColor::sidebarIsLight())<script>document.documentElement.classList.add('sidebar-light');</script>@endif
<script>
{{-- Charts draw on <canvas>, which can't read CSS variables — themeColor()
     hands Chart.js the person's accent as a plain color (optionally with
     alpha). --color-primary/--color-secondary are always hex (app.css @theme
     or the override above). --}}
window.themeColor = function (name, alpha) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim() || '#CA8A04';
    if (alpha == null || v.charAt(0) !== '#') return v;
    var n = parseInt(v.slice(1), 16);
    return 'rgba(' + (n >> 16 & 255) + ',' + (n >> 8 & 255) + ',' + (n & 255) + ',' + alpha + ')';
};
</script>
