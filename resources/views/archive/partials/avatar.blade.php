{{-- Needs: $name; optional $size (sm|md|lg|xl) and $photo (URL — falls back to initials). --}}
@php
    $sizeClass = ['sm' => 'w-7 h-7 text-[10px]', 'md' => 'w-9 h-9 text-xs', 'lg' => 'w-14 h-14 text-lg', 'xl' => 'w-20 h-20 text-2xl'][$size ?? 'md'];
    $parts = preg_split('/\s+/', trim($name ?? '?')) ?: ['?'];
    $initials = mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
@endphp
@if(! empty($photo))
<img src="{{ $photo }}" alt="{{ $name }}" title="{{ $name }}" loading="lazy" draggable="false" class="{{ $sizeClass }} shrink-0 rounded-full object-cover bg-slate-200 dark:bg-slate-700 ring-2 ring-white dark:ring-slate-900">
@else
<span class="inline-flex {{ $sizeClass }} shrink-0 items-center justify-center rounded-full bg-sidebar dark:bg-slate-700 font-semibold text-white ring-2 ring-white dark:ring-slate-900" title="{{ $name }}">{{ $initials }}</span>
@endif
