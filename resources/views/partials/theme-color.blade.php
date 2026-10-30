{{-- Accent + sidebar colors chosen in Settings → Appearance (App\Support\ThemeColor).
     Include right after @vite in every layout so it overrides the @theme defaults.
     A light sidebar flips the menu to dark text (html.sidebar-light). --}}
@php($themeCss = \App\Support\ThemeColor::css())
@if($themeCss)<style id="themeColorVars">{!! $themeCss !!}</style>@endif
@if(\App\Support\ThemeColor::textRulesOn())<script>document.documentElement.classList.add('theme-text');</script>@endif
@if(\App\Support\ThemeColor::sidebarIsLight())<script>document.documentElement.classList.add('sidebar-light');</script>@endif
