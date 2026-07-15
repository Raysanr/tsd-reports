# TSA Rest Day Calendar — Design

## Goal

Add a rest-day calendar to the TSA Management page so ops staff can see and set which TSAs are off on which dates, and surface that context on TSA Performance so a zero-activity day reads as "day off" rather than "no sales."

## Background

TSA Management (`resources/views/tsa-management.blade.php`, `app/Http/Controllers/TsaManagementController.php`) currently manages the TSA roster and per-agent call shifts (`shift_start`/`shift_end` on `tsa_shifts`) but has no concept of a day off. There are 6 TSAs across 2 teams (SH Naturals: Gemma, Mariel, Kathleen; Eyecare: Julie, Joana, Marisol).

Two kinds of "off" need to be represented:
- **Recurring** — a TSA's usual weekly day off (e.g. Julie is always off Sundays).
- **One-off** — a specific date that deviates from the recurring pattern, in either direction: an *extra* day off (e.g. Marisol also off July 20), or an *override* where the TSA works through what would normally be their day off (e.g. Julie working this particular Sunday).

This is purely a scheduling/display feature. It does not change any existing calculation (upselling rate, pick-up rate, totals, etc.) anywhere in the app.

## Data Model

### `tsa_shifts` — one new column

```php
$table->string('rest_day_of_week')->nullable()->after('seller_keywords');
```

Lowercase full day name (`"sunday"`, `"monday"`, … `"saturday"`), or `null` for "no recurring day off." Chosen over a numeric day-of-week for the same reason the rest of this table favors readable strings over codes (`team` stores `"SH Naturals"`, not an ID).

### New table: `tsa_rest_days`

```php
Schema::create('tsa_rest_days', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tsa_shift_id')->constrained('tsa_shifts')->cascadeOnDelete();
    $table->date('date');
    $table->boolean('is_off');
    $table->timestamps();

    $table->unique(['tsa_shift_id', 'date']);
    $table->index('date');
});
```

This table holds **exceptions only** — a row exists for a `(tsa_shift_id, date)` pair only when that date's actual status differs from what the recurring rule alone would produce:
- `is_off = true` — an extra day off not implied by the recurring pattern.
- `is_off = false` — an explicit override: the TSA is working despite the date matching their recurring rest day.

If a date's actual status matches the recurring rule, no row exists for it. This keeps the table representing genuine deviations, and means changing a TSA's recurring day later doesn't leave stale, misleading rows behind for dates that were never actually exceptions.

### Resolution rule

"Is TSA X off on date Y?" resolves as:

1. Look for a row in `tsa_rest_days` for `(tsa_shift_id, date)`. If found, use its `is_off` value — this is authoritative regardless of the recurring rule.
2. Otherwise, fall back to whether `date`'s weekday (lowercase, e.g. `Carbon::parse($date)->format('l')` lowercased) matches `rest_day_of_week`.

Implemented as `TsaShift::isOffOn(Carbon $date): bool`, plus a `TsaShift::restDays()` `hasMany(TsaRestDay::class)` relationship. New `TsaRestDay` model (`belongsTo(TsaShift::class)`), fillable `tsa_shift_id`, `date`, `is_off`, with `date` cast to `date` and `is_off` cast to `boolean`.

## TSA Management Page

### Layout

The page container widens from the current single centered column (`max-w-3xl`) to a two-column layout: existing team roster on the left (~2/3 width), new calendar card on the right (~1/3 width) — stacking to full-width below the roster on small screens (mobile-first responsive, no horizontal scroll).

### Calendar

- Standard month grid, Sunday–Saturday columns, one row per week.
- Month navigation via `?month=YYYY-MM` query param on the same `tsa-management` route (defaults to the current month, mirroring how Dashboard already does date-range navigation via URL params).
- Each date cell shows the date number and 2-letter initials (matching the existing avatar style already used in the roster rows, e.g. `substr($shift->display_name, 0, 2)`) for every TSA who is off that day, per the resolution rule above.
- Clicking a date cell opens a modal — reusing the existing Add/Edit TSA modal *mechanism* (a shared hidden `<div>` toggled via JS, not the same modal instance) — showing one checkbox per TSA, each pre-checked according to that date's current effective status.

### Saving a date's rest days

New route:

```php
Route::post('/tsa-management/rest-days/{date}', [TsaManagementController::class, 'saveRestDays'])
    ->name('tsa-management.rest-days');
```

`{date}` is `Y-m-d`. `saveRestDays(Request $request, string $date)`:

1. Validate `$date` is a real date; validate the request body (`tsas` — array of checked `tsa_key` values).
2. For every TSA in the roster: determine what the recurring rule alone would say for this date (`rest_day_of_week` match), and compare against whether that TSA's key is present in the submitted `tsas` list.
   - If they differ from the recurring default → upsert a `tsa_rest_days` row with the appropriate `is_off`.
   - If they match the recurring default → delete any existing override row for that `(tsa_shift_id, date)` (no exception needed).
3. Redirect back to the calendar (same `?month=` the user was viewing).

This is a full page reload on save (standard POST + redirect), consistent with how the rest of this page already works (the bulk "Save Schedules" form, the Add/Edit TSA modal) — no new AJAX pattern introduced.

## TSA Edit Modal — recurring day off

One new field in the *existing* Add/Edit TSA modal (`resources/views/tsa-management.blade.php`'s `#tsaModal`): a "Rest day" `<select>` (`None`, `Sunday`…`Saturday`), submitted alongside the existing `display_name`/`team`/`extra_keywords` fields on the same form. `TsaManagementController::store()`/`update()` and `validateTsa()` extend to accept and persist `rest_day_of_week`.

## TSA Performance — "Day Off" banner

In `TsaPerformanceController::showTsa(string $team, string $tsaKey)`: after resolving the requested date and the `TsaShift` for `$tsaKey`, compute `$isRestDay = $tsaShift?->isOffOn($date) ?? false` and pass it to the view. `resources/views/tsa-performance-individual.blade.php` shows a small informational banner near the top when true — no change to any computed metric on the page (upselling rate, pick-up rate, totals, etc. are all untouched; the banner is purely additive context).

## Error Handling

- `saveRestDays()`: an unknown `tsa_key` in the submitted list is silently ignored (defensive — the checkbox list is server-rendered from the current roster, so this should only happen from a stale page after a TSA was deleted mid-edit).
- `isOffOn()` on a `TsaShift` with no `rest_day_of_week` and no matching `tsa_rest_days` row simply returns `false` (not off) — the common case, no special handling needed.
- Calendar month navigation: an invalid `?month=` value falls back to the current month rather than erroring (same defensive pattern as `SyncTodayOrders`' `--date` option parsing elsewhere in this app).

## Testing

- `TsaShift::isOffOn()` — recurring-only match, explicit addition (no recurring rule), explicit override (working despite recurring rest day), and the case where neither applies.
- Calendar view (`GET /tsa-management` with `?month=`) — renders the correct set of off-TSA initials per date across a month, including month navigation.
- `saveRestDays()` — verifies the diff-against-recurring logic: checking/unchecking a box that matches the recurring default doesn't create a row (or deletes an existing one); checking/unchecking one that differs creates/updates a row with the correct `is_off`.
- TSA Edit modal — `store()`/`update()` persist `rest_day_of_week` correctly, including clearing it back to `null`.
- TSA Performance — banner appears when the viewed date+TSA resolves to off, and does not appear otherwise; confirms no existing metric changes when the banner is shown.

## Out of Scope

- Any change to existing calculated metrics (upselling rate, pick-up rate, "days worked," leaderboard, etc.) — explicitly rejected during design in favor of the banner-only approach, to keep this change low-risk and self-contained. A future spec can revisit excluding rest days from those calculations if needed.
- A "Day Off" indicator on Dashboard, Leads Report, or any page other than TSA Management (the calendar) and TSA Performance (the banner) — not requested.
- Bulk/recurring one-off entry (e.g. "mark every Sunday in August as off for everyone") — one date + one TSA at a time via the calendar, consistent with how granular the rest of this page's editing already is.
