{{-- Accent + sidebar colors chosen in Settings → Appearance (App\Support\ThemeColor).
     Include right after @vite in every layout so it overrides the @theme defaults. --}}
@php($themeCss = \App\Support\ThemeColor::css())
@if($themeCss)<style id="themeColorVars">{!! $themeCss !!}</style>@endif
