# Toast notifications + Sync feedback — design

Date: 2026-07-16
Status: Approved

## Problem

Feedback in the app today is inconsistent:

- Product Management, TSA Management, User Management, and Settings each render their own inline `session('success')` banner at the top of the content area — four separate copies of the same markup.
- The Dashboard's Sync button (`DashboardController::sync`) gives no visible result at all: it spins, POSTs to `/sync`, soft-refreshes the page, and stops. The user has to infer success from numbers possibly changing.
- There's no `error`/`info` variant anywhere — only ad hoc `session('success')` and Laravel's default `$errors` bag for validation.

This is the first of several planned usability improvements (see the "Findability & navigation" / "Data interaction" / "Feedback & status" / "Accessibility & comfort" / "Admin quality-of-life" list); the other pieces are separate sub-projects with their own specs.

## Goals

- One reusable toast component, styled to match the app's existing look (not a generic default).
- Replace the four inline `session('success')` banners with it — no visual regression, less duplication.
- Give the Dashboard Sync button real, specific feedback: what was synced, or what failed.
- Support `success` / `error` / `info` variants so future features (not built here) have somewhere to put transient feedback.

## Non-goals

- Field-level validation errors (`$errors->any()`) stay inline next to the form — they are not transient status and don't belong in a toast.
- No new "error" flash calls are being added to controllers that don't already fail visibly today (e.g. Product/TSA/User Management delete paths) — only Sync gets new failure reporting, since that's the one currently silent.
- No queueing/persistence of toasts across page navigations beyond the existing session-flash mechanism.

## Design

### Toast container & rendering

A single toast container is added once, in `resources/views/layouts/app.blade.php`:

```html
<div id="toastContainer" class="fixed top-4 right-4 z-[70] flex flex-col gap-2 w-full max-w-sm pointer-events-none"></div>
```

- `z-[70]` — above the sidebar (`z-50`) and its mobile backdrop (`z-40`), so toasts are always visible, including on mobile with the drawer open.
- `pointer-events-none` on the container, `pointer-events-auto` on each toast card, so the empty space around toasts doesn't block clicks on the page underneath.

On page load, the layout checks `session('success')`, `session('error')`, `session('info')` (in that order — only one is ever set per request in practice) and, if present, emits a small inline `<script>` that calls `window.showToast(message, variant)` once the container exists. This replaces today's per-page `@if(session('success'))` blocks — pages no longer render their own banner markup.

### `window.showToast(message, variant)`

Defined in `resources/js/app.js`, alongside the existing `softRefresh`/export helpers (same file, same pattern — small delegated/global helpers, not a separate bundle).

```js
window.showToast = function (message, variant = 'success') { ... }
```

Responsibilities:
- Build the toast card DOM (see Anatomy below), append to `#toastContainer`.
- Animate in: `opacity 0 → 1`, `translateX(1rem) → 0`, 200ms ease-out. Skips the translate under `prefers-reduced-motion: reduce` (fade only).
- Start a 4s auto-dismiss timer. Hovering the toast pauses the timer; leaving resumes it.
- Manual close via an × button, same animation reversed (150ms ease-in) before removal from the DOM.
- Multiple toasts stack vertically (flex column, 8px gap via the container's `gap-2`), newest appended at the bottom of the stack (closest to where a new one would naturally arrive visually below existing ones) — no cap; in practice this app never fires more than one or two at a time.

### Anatomy & variants

Reuses the exact card styling already used for the four existing banners, just floated instead of inline:

```html
<div class="pointer-events-auto flex items-center gap-3 bg-{color}-50 border border-{color}-200 rounded-xl px-4 py-3 shadow-lg">
  <svg class="w-4 h-4 text-{color}-500 shrink-0">...</svg>
  <p class="text-sm font-mono text-{color}-700 flex-1">{message}</p>
  <button class="text-{color}-400 hover:text-{color}-600 shrink-0" aria-label="Dismiss">×</button>
</div>
```

| Variant | Color | Icon |
|---|---|---|
| success | green (`green-50`/`green-200`/`green-500`/`green-700`) | checkmark (existing) |
| error | red (`red-50`/`red-200`/`red-500`/`red-700`) | x-circle |
| info | blue (`blue-50`/`blue-200`/`blue-500`/`blue-700`) — deliberately not the brand yellow, so a toast never reads as the date-picker's "custom filter active" affordance | info circle |

Accessibility: container carries `aria-live="polite" aria-atomic="false"` so screen readers announce new toasts without stealing focus; the icon is decorative (`aria-hidden`), the message text is what's read.

### Sync feedback

`DashboardController::sync()` currently:

```php
while ($from->lte($to)) {
    Artisan::call('pancake:sync-today', ['--date' => $from->toDateString()]);
    $from->addDay();
}
return response()->json(['success' => true, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
```

`SyncTodayOrders::recordRun()` already writes one `SyncRun` row per `Artisan::call` (`new_orders`, `upsell_count`, `upsell_sales`, `success`, `error_message`). Change `sync()` to capture those rows — record the max `SyncRun` id before the loop, then query every row with a greater id after it — and fold them into the response:

```php
[
  'success'      => bool,               // false if any day's run failed
  'new_orders'   => int,                // summed across every day synced
  'upsell_count' => int,
  'upsell_sales' => float,
  'error_message'=> string|null,        // first failure's message, if any
]
```

The Sync button's existing JS (`resources/views/dashboard.blade.php`) calls `window.showToast(...)` with the result before calling `softRefresh()`:

- Success: `"Synced — {new_orders} new orders, {upsell_count} upsells (₱{upsell_sales})"`. If `new_orders === 0`, `"Synced — no new orders."` instead (avoids a confusing "0 new orders" reading as a possible failure).
- Failure: `"Sync failed: {error_message}"` (error variant).

### Migrating the four existing pages

Delete the inline block from each of:
- `resources/views/product-management.blade.php`
- `resources/views/tsa-management.blade.php`
- `resources/views/user-management.blade.php`
- `resources/views/settings.blade.php`

No controller changes needed there — they already flash `session('success')`, which the layout now picks up automatically. The `$errors->any()` block in each stays untouched.

## Testing

- Manual: trigger each of the four management pages' success flows (add/update/delete) and confirm the toast appears, matches the old banner's message, auto-dismisses, and is closable.
- Manual: click Sync on the Dashboard with a date range that has new orders, confirm the toast shows the right counts; simulate a failure (e.g. temporarily break the Pancake API key) and confirm the error toast + message.
- Manual: resize to mobile width, open the sidebar drawer, trigger a toast — confirm it's still visible above the drawer/backdrop.
- Manual: enable `prefers-reduced-motion` in devtools, confirm toasts fade only (no slide).
- `php artisan test` — no existing tests cover these controllers/views today; not adding new automated tests for this UI-only change (consistent with the rest of the app's test coverage).
