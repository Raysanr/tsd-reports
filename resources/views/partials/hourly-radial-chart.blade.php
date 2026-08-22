{{--
    24-hour radial chart (one wedge per hour, like a clock face) rather than a
    linear bar chart — hour-of-day genuinely wraps around (23 → 0), so a clock
    layout reads more naturally for "when did this happen" than a straight
    line does, and makes shift-timing gaps visible as a gap in the ring.

    Extracted (2026-08-22, explicit request: "make this hourly leads" — a
    second chart alongside the existing Hourly Activity one) from Dashboard's
    own original inline block, which was Hourly Activity only — parameterized
    so a second instance (or any future third) never has to hand-copy this
    ~140-line SVG geometry block again.

    Required: $id (unique per instance — becomes the tooltip element's own id,
    since two instances can't share one), $title, $subtitle, $data (a 24-
    length Collection, index 0-23 = hour of day), $unit (singular noun for the
    tooltip, e.g. 'call' or 'lead' — pluralized via Str::plural).
--}}
@if($data->sum() > 0)
@php
    $hourMax    = max(1, $data->max());
    $peakHour   = $data->search($data->max());
    $size       = 600;
    $cx         = $size / 2;
    $cy         = $size / 2;
    $maxRadius  = 210;
    $innerHole  = 14;   // small center hole so every hour renders as a visible wedge, even at 0
    $labelR     = $maxRadius + 30;
    $slices     = collect(range(0, 23))->map(function ($hour) use ($data, $hourMax, $cx, $cy, $maxRadius, $innerHole, $labelR, $peakHour) {
        $count      = $data[$hour];
        $outerR     = $innerHole + ($count / $hourMax) * ($maxRadius - $innerHole);
        $centerDeg  = -90 + $hour * 15;      // hour 0 at 12 o'clock, clockwise
        $halfWidth  = 6.5;                    // slice spans 13° of its 15° slot, leaving a thin gap
        $a1 = deg2rad($centerDeg - $halfWidth);
        $a2 = deg2rad($centerDeg + $halfWidth);
        $labelAngle = deg2rad($centerDeg);

        return [
            'hour'   => $hour,
            'count'  => $count,
            'isPeak' => $hour === $peakHour,
            'x1'     => $cx + $innerHole * cos($a1), 'y1' => $cy + $innerHole * sin($a1),
            'x2'     => $cx + $outerR * cos($a1),    'y2' => $cy + $outerR * sin($a1),
            'x3'     => $cx + $outerR * cos($a2),    'y3' => $cy + $outerR * sin($a2),
            'x4'     => $cx + $innerHole * cos($a2), 'y4' => $cy + $innerHole * sin($a2),
            'lx'     => $cx + $labelR * cos($labelAngle),
            'ly'     => $cy + $labelR * sin($labelAngle),
            'anchor' => cos($labelAngle) > 0.3 ? 'start' : (cos($labelAngle) < -0.3 ? 'end' : 'middle'),
        ];
    });
@endphp
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm px-5 py-4 flex flex-col">
    <div class="flex items-center justify-between mb-3">
        <div>
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">{{ $title }}</h2>
            <p class="text-xs font-mono text-slate-400 mt-0.5">{{ $subtitle }}</p>
        </div>
        <div class="text-right">
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none">{{ $data->sum() }}</p>
            <p class="text-xs font-mono text-slate-400 mt-1">peak {{ \App\Support\HourFormatter::label($peakHour) }}</p>
        </div>
    </div>

    <div class="relative flex-1 flex items-center justify-center">
    {{-- Custom hover tooltip — the native SVG <title> tooltip (kept below for
         screen readers) takes ~1s to appear and can't be styled; this shows
         instantly and matches the app's look. --}}
    <div id="hourlyTooltip-{{ $id }}" class="hidden absolute z-10 pointer-events-none px-2.5 py-1.5 rounded-lg bg-slate-800 text-white text-xs font-mono shadow-lg whitespace-nowrap"
         style="transform: translate(-50%, -120%)">
    </div>

    <svg viewBox="0 0 {{ $size }} {{ $size }}" class="mx-auto block" style="max-width:{{ $size }}px; width:100%; height:auto">
        {{-- Recessive reference rings, at even fractions of the busiest hour --}}
        @foreach([0.33, 0.66, 1] as $frac)
        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $innerHole + $frac * ($maxRadius - $innerHole) }}"
                fill="none" stroke="var(--chart-grid)" stroke-width="1" />
        @endforeach

        {{-- Clock-face bezel + hour ticks — drawn BEFORE the data wedges so this
             unmistakably reads as a clock face first, with the data as an overlay
             on top of it, not a generic radial/rose chart. A bolder ring right at
             the data boundary stands in for the clock's rim; short ticks at each
             hour (longer/bolder at the 12/3/6/9 o'clock positions, same as a real
             analog clock's quarter marks) reinforce it further. --}}
        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $maxRadius }}"
                fill="none" stroke="var(--chart-grid)" stroke-width="2" />
        @foreach($slices as $s)
        @php
            $tickAngle = deg2rad(-90 + $s['hour'] * 15);
            $isMajorTick = $s['hour'] % 6 === 0; // 12/3/6/9 o'clock positions
            $tickLen     = $isMajorTick ? 10 : 5;
            $tx1 = $cx + $maxRadius * cos($tickAngle);
            $ty1 = $cy + $maxRadius * sin($tickAngle);
            $tx2 = $cx + ($maxRadius + $tickLen) * cos($tickAngle);
            $ty2 = $cy + ($maxRadius + $tickLen) * sin($tickAngle);
        @endphp
        <line x1="{{ $tx1 }}" y1="{{ $ty1 }}" x2="{{ $tx2 }}" y2="{{ $ty2 }}"
              stroke="var(--chart-grid)" stroke-width="{{ $isMajorTick ? 2 : 1 }}" />
        @endforeach

        @foreach($slices as $s)
        <path class="hourly-wedge-{{ $id }}" data-hour="{{ \App\Support\HourFormatter::label($s['hour']) }}" data-count="{{ $s['count'] }}"
              style="cursor:pointer; transition: opacity 150ms ease"
              d="M {{ $s['x1'] }},{{ $s['y1'] }}
                 L {{ $s['x2'] }},{{ $s['y2'] }}
                 A {{ $maxRadius }},{{ $maxRadius }} 0 0,1 {{ $s['x3'] }},{{ $s['y3'] }}
                 L {{ $s['x4'] }},{{ $s['y4'] }}
                 A {{ $innerHole }},{{ $innerHole }} 0 0,0 {{ $s['x1'] }},{{ $s['y1'] }} Z"
              fill="{{ $s['isPeak'] ? 'var(--chart-wedge-peak)' : 'var(--chart-wedge)' }}">
            <title>{{ \App\Support\HourFormatter::label($s['hour']) }} — {{ $s['count'] }} {{ \Illuminate\Support\Str::plural($unit, $s['count']) }}</title>
        </path>
        @endforeach

        {{-- Center hub — the clock's "center pin", closing off the inner hole
             the wedges leave open. --}}
        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="5" fill="var(--chart-grid)" />

        {{-- Hour labels — 12-hour clock format, around the outside of the ring --}}
        @foreach($slices as $s)
        <text x="{{ $s['lx'] }}" y="{{ $s['ly'] }}" text-anchor="{{ $s['anchor'] }}"
              dominant-baseline="middle" font-size="12" font-family="monospace"
              fill="{{ $s['isPeak'] ? 'var(--chart-label-peak)' : 'var(--chart-label)' }}" font-weight="{{ $s['isPeak'] ? 'bold' : 'normal' }}">
            {{ \App\Support\HourFormatter::label($s['hour']) }}
        </text>
        @endforeach
    </svg>
    </div>
</div>
<script>
(function () {
    const wrap    = document.querySelector('#hourlyTooltip-{{ $id }}')?.parentElement;
    const tooltip = document.getElementById('hourlyTooltip-{{ $id }}');
    if (!wrap || !tooltip) return;

    wrap.querySelectorAll('.hourly-wedge-{{ $id }}').forEach(wedge => {
        wedge.addEventListener('mouseenter', () => {
            const hour  = wedge.dataset.hour;
            const count = wedge.dataset.count;
            tooltip.innerHTML = `<span class="font-semibold">${hour}</span> — ${count} ${count == 1 ? '{{ $unit }}' : '{{ \Illuminate\Support\Str::plural($unit) }}'}`;
            tooltip.classList.remove('hidden');
            wedge.style.opacity = '0.75';
        });
        wedge.addEventListener('mousemove', (e) => {
            const box = wrap.getBoundingClientRect();
            tooltip.style.left = (e.clientX - box.left) + 'px';
            tooltip.style.top  = (e.clientY - box.top) + 'px';
        });
        wedge.addEventListener('mouseleave', () => {
            tooltip.classList.add('hidden');
            wedge.style.opacity = '1';
        });
    });
})();
</script>
@endif
