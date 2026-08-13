{{-- Renders every canvas in $chartsData (array of {id, chart: {labels, data,
     colors}}) as a hand-drawn 3D pie — a tilted ellipse with an extruded rim,
     bold value numbers on each slice, and external leader-line labels with
     percentages, matching the source sheet's own 3D pie chart style
     (explicit request, 2026-08-13: "make it look like an identical graph").
     Chart.js's pie type only draws a flat 2D circle with no tilt/extrusion,
     so this draws directly on the canvas instead — no charting library
     needed. Included (not duplicated by hand) from both leads-report.blade.php
     and leads-report-all.blade.php, which each compute their own identically-
     shaped $chartsData. --}}
@if(!empty($chartsData))
@push('scripts')
{{-- data-rerun: app.js's softRefresh re-executes this after swapping <main> in
     place (see charts.blade.php for the full explanation) — the canvases
     this targets live inside the swapped region, and re-running picks up
     fresh @json data on every filter change. --}}
<script data-rerun>
(function () {
    const charts = @json($chartsData);
    if (charts.length === 0) return;

    const rootStyles = getComputedStyle(document.documentElement);
    const labelColor = (rootStyles.getPropertyValue('--chart-label') || '').trim() || '#94a3b8';

    // Darkens a '#rrggbb' color by a flat amount per channel — used for the
    // extruded rim's shaded side, same "front face lighter, side darker"
    // convention every real 3D pie chart uses to sell the illusion of depth.
    const darken = (hex, amount) => {
        const n = parseInt(hex.slice(1), 16);
        const r = Math.max(0, (n >> 16) - amount);
        const g = Math.max(0, ((n >> 8) & 0xff) - amount);
        const b = Math.max(0, (n & 0xff) - amount);
        return `rgb(${r},${g},${b})`;
    };

    function renderPie3D(canvas, labels, data, colors) {
        const total = data.reduce((a, b) => a + b, 0);
        const dpr = window.devicePixelRatio || 1;

        // Explicit request (2026-08-13): "maximize the space, make it
        // bigger" — the canvas has no fixed pixel size (see
        // pie-chart-panel.blade.php), it fills its wrapper via CSS. Measure
        // the ACTUAL rendered box here rather than trusting width/height
        // attributes, so the pie genuinely fills whatever room this
        // specific panel got (a short table's panel vs. a 24-hour
        // breakdown's).
        const rect = canvas.getBoundingClientRect();
        const w = Math.max(1, Math.round(rect.width));
        const h = Math.max(1, Math.round(rect.height));
        canvas.style.width = w + 'px';
        canvas.style.height = h + 'px';
        canvas.width = w * dpr;
        canvas.height = h * dpr;

        const ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);
        ctx.clearRect(0, 0, w, h);
        if (total <= 0) return;

        // Ellipse geometry (explicit request, 2026-08-13: "maximize the
        // space... look at the sides, make it bigger" — min(w,h)*0.19
        // was accidentally using HEIGHT as the cap on this panel (h=520 <
        // w=668), even though width is the real constraint — every slice's
        // external "Label NN.N%" leader-line (fixed +22/+20px offsets, see
        // below) needs room on both sides, not top/bottom. Worked backward
        // from the longest real label ("Repeat Order w/ Upsell 100.0%",
        // ~29 chars at 9px Fira Code, ~160px wide): at this panel's 668px
        // width that leaves safe room up to rx≈130 before text would clip
        // off the canvas edge, vs. the 98.8 the old height-capped formula
        // produced. w*0.19 lands at ~127 — bigger, with a small cushion
        // still in hand. h*0.42 stays as a floor-case safety net only, for
        // an unusually short-but-wide panel.
        const rx = Math.min(w * 0.19, h * 0.42);
        // Every fixed-pixel constant below (fonts, leader-line offsets) was
        // tuned against a 668px-wide canvas — the panel's now-responsive
        // width (pie-chart-panel.blade.php) means the canvas can be much
        // narrower on a smaller viewport, and those absolute pixel values
        // don't shrink with it on their own. SCALE keeps them exactly as
        // proven-good at/above 668px, and shrinks them proportionally below
        // it instead of leaving them oversized relative to a smaller pie.
        const SCALE = Math.min(1, w / 668);
        // ry: was a flat 0.55*rx (a "flattened, tilted disc" look), which
        // left a lot of dead space top/bottom in a 520px-tall box — checked
        // there's ~120-150px of vertical clearance to spare even at this
        // taller ratio before a leader line would clip. Bumped to 0.85*rx —
        // still visibly an ellipse (the 3D tilt), just filling far more of
        // the box's actual height. RY_RATIO also drives the label
        // leader-line's own vertical reach further down, so the connector
        // lines stay visually anchored to the now-taller slice edges.
        const RY_RATIO = 0.85;
        const ry = rx * RY_RATIO;
        const depth = rx * 0.24;
        const cx = w / 2;
        const cy = (h - depth) / 2;

        const point = (angle, radiusX, radiusY) => [cx + Math.cos(angle) * radiusX, cy + Math.sin(angle) * radiusY];

        const slices = [];
        let angle = -Math.PI / 2;
        labels.forEach((label, i) => {
            const value = data[i];
            if (!value) return;
            const sweep = (value / total) * Math.PI * 2;
            slices.push({ label, value, color: colors[i], start: angle, end: angle + sweep, pct: (value / total) * 100 });
            angle += sweep;
        });

        // 1. Extruded rim (side walls) — drawn first so the top face covers
        // their upper edge. Only the front-facing (bottom-half) arc casts a
        // visible wall; the back half is hidden behind the top face itself,
        // same as a real 3D disc.
        slices.forEach((s) => {
            const steps = Math.max(2, Math.ceil((s.end - s.start) / 0.05));
            for (let i = 0; i < steps; i++) {
                const a0 = s.start + (s.end - s.start) * (i / steps);
                const a1 = s.start + (s.end - s.start) * ((i + 1) / steps);
                if (Math.sin(a0) < 0 && Math.sin(a1) < 0) continue;
                const [x0, y0] = point(a0, rx, ry);
                const [x1, y1] = point(a1, rx, ry);
                ctx.beginPath();
                ctx.moveTo(x0, y0);
                ctx.lineTo(x1, y1);
                ctx.lineTo(x1, y1 + depth);
                ctx.lineTo(x0, y0 + depth);
                ctx.closePath();
                ctx.fillStyle = darken(s.color, 55);
                ctx.fill();
            }
        });

        // 2. Top face — the actual tilted-ellipse pie, white-bordered slices.
        slices.forEach((s) => {
            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.ellipse(cx, cy, rx, ry, 0, s.start, s.end);
            ctx.closePath();
            ctx.fillStyle = s.color;
            ctx.fill();
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 1.5;
            ctx.stroke();
        });

        // 3. Bold value number on each slice big enough to hold one —
        // skipped on very thin slices where it would never fit cleanly.
        slices.forEach((s) => {
            if (s.pct < 3) return;
            const mid = (s.start + s.end) / 2;
            const [lx, ly] = point(mid, rx * 0.58, ry * 0.58);
            ctx.fillStyle = '#ffffff';
            ctx.font = `bold ${Math.max(7, Math.min(15, (9 + s.pct / 6) * SCALE))}px 'Fira Code', monospace`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(String(s.value), lx, ly + depth * 0.4);
        });

        // 4. External leader line + "Label NN.N%" — every non-zero slice gets
        // one, same as the reference chart having no separate legend at all.
        // Kept as leader-lines at EVERY width including real phones
        // (explicit request, 2026-08-13: "i want to make it has line still
        // ... i want to make it the text in graph is responsive too" — a
        // legend-list fallback was tried for narrow widths and explicitly
        // rejected in favor of keeping this design everywhere).
        //
        // Text fit is now driven by ACTUAL measured width (ctx.measureText),
        // not an estimate. The previous approach scaled a fixed font size by
        // a flat width ratio (SCALE, a character-count-based guess) — that
        // still clipped, because an estimate isn't the same as the real
        // rendered width, and its hard 7px floor couldn't shrink further
        // even as the available space kept shrinking below what 7px needs
        // (confirmed via screenshot: text ran past the canvas edge on both a
        // real ~390px phone and a narrow desktop window). fitText() below
        // measures the real width and shrinks the font to whatever space is
        // ACTUALLY available on that side of the canvas; if even the
        // legibility floor doesn't fit on one line, it wraps onto two
        // (name, then percentage) instead of ever letting text run past the
        // edge — this is what makes it "responsive" rather than just
        // "smaller everywhere".
        const bendReach = rx + Math.max(10, rx * 0.16);
        const nubReach = Math.max(8, rx * 0.12);
        const LABEL_MAX_PX = 10;
        const LABEL_MIN_PX = 6.5;
        const LINE_HEIGHT = 1.15;

        const fitText = (text, maxWidth) => {
            let size = LABEL_MAX_PX;
            ctx.font = `bold ${size}px 'Fira Code', monospace`;
            let width = ctx.measureText(text).width;
            if (width > maxWidth) {
                size = Math.max(LABEL_MIN_PX, size * (maxWidth / width));
                ctx.font = `bold ${size}px 'Fira Code', monospace`;
                width = ctx.measureText(text).width;
            }
            return { size, fits: width <= maxWidth };
        };

        // bendReach/nubReach are the same magnitude for every label on a
        // given side, so the available text budget only needs computing
        // once per side rather than per label.
        const maxWidthRight = Math.max(24, w - (cx + bendReach + nubReach + 4) - 6);
        const maxWidthLeft = Math.max(24, (cx - bendReach - nubReach - 4) - 6);

        const labelPlans = slices.map((s) => {
            const mid = (s.start + s.end) / 2;
            const [sx, sy] = point(mid, rx + 2, ry + 2);
            const [mx, my] = point(mid, bendReach, bendReach * RY_RATIO);
            const onRight = Math.cos(mid) >= 0;
            const maxWidth = onRight ? maxWidthRight : maxWidthLeft;

            const pctText = `${s.pct.toFixed(1)}%`;
            const fullText = `${s.label} ${pctText}`;
            const fit = fitText(fullText, maxWidth);

            let lines, fontSize;
            if (fit.fits) {
                lines = [fullText];
                fontSize = fit.size;
            } else {
                // Doesn't fit on one line even at the floor size — wrap the
                // disposition name and its percentage onto separate lines
                // rather than clip either of them.
                const nameFit = fitText(s.label, maxWidth);
                const pctFit = fitText(pctText, maxWidth);
                lines = [s.label, pctText];
                fontSize = Math.min(nameFit.size, pctFit.size);
            }

            // Final safety net: fitText() always returns SOME size (it
            // clamps to LABEL_MIN_PX, it doesn't guarantee that size
            // actually fits) — for an unusually long name in an unusually
            // narrow space, even the 2-line wrap above can still overflow
            // at the floor size (confirmed via screenshot: "Upsell w/
            // Confirmation" ran past the edge on a real ~322px-wide mobile
            // canvas, where the budget was only ~72px — nowhere near
            // enough for 22 characters even at 6.5px). Truncate with an
            // ellipsis as the last resort, so text can shrink-to-fit but
            // never silently overflow.
            ctx.font = `bold ${fontSize}px 'Fira Code', monospace`;
            lines = lines.map((line) => {
                if (ctx.measureText(line).width <= maxWidth) return line;
                let t = line;
                while (t.length > 1 && ctx.measureText(t + '…').width > maxWidth) {
                    t = t.slice(0, -1);
                }
                return t + '…';
            });

            const rowHeight = fontSize * LINE_HEIGHT;
            return { sx, sy, naturalY: my, y: my, onRight, lines, fontSize, blockHeight: rowHeight * lines.length };
        });

        // Several thin adjacent slices can land at nearly the same angle,
        // which would stack their labels on top of each other if drawn at
        // the raw geometric position (confirmed: the ALL view's
        // ~12-category chart produced an unreadable jumble in the
        // bottom-right cluster). Same greedy-spacing fix as before, but the
        // minimum gap between two labels now comes from their OWN fitted
        // heights (a 2-line-wrapped label needs more room than a 1-line
        // one) instead of a flat constant.
        ['left', 'right'].forEach((side) => {
            const group = labelPlans.filter((p) => (side === 'right') === p.onRight).sort((a, b) => a.naturalY - b.naturalY);
            for (let i = 1; i < group.length; i++) {
                const minGap = (group[i - 1].blockHeight + group[i].blockHeight) / 2 + 4;
                if (group[i].y - group[i - 1].y < minGap) {
                    group[i].y = group[i - 1].y + minGap;
                }
            }
        });

        labelPlans.forEach((p) => {
            const bendX = cx + bendReach * (p.onRight ? 1 : -1);
            const ex = bendX + nubReach * (p.onRight ? 1 : -1);

            ctx.strokeStyle = labelColor;
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(p.sx, p.sy);
            ctx.lineTo(bendX, p.y);
            ctx.lineTo(ex, p.y);
            ctx.stroke();

            ctx.fillStyle = labelColor;
            ctx.font = `bold ${p.fontSize}px 'Fira Code', monospace`;
            ctx.textAlign = p.onRight ? 'left' : 'right';
            ctx.textBaseline = 'middle';

            const rowHeight = p.fontSize * LINE_HEIGHT;
            const startY = p.y - (rowHeight * (p.lines.length - 1)) / 2;
            p.lines.forEach((line, i) => {
                ctx.fillText(line, ex + (p.onRight ? 4 : -4), startY + i * rowHeight);
            });
        });
    }

    charts.forEach(({ id, chart }) => {
        const el = document.getElementById(id);
        if (!el || chart.data.length === 0) return;
        renderPie3D(el, chart.labels, chart.data, chart.colors);
    });
})();
</script>
@endpush
@endif
