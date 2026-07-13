# Hide Product

## Problem

Product Management has no way to retire a discontinued/seasonal product without permanently deleting it. Deleting loses the row entirely (and its `match_keyword` config); leaving it active clutters every product-scoped list going forward (Leads Report's per-product tables, the Charts product-comparison row, TSA Performance's product filter dropdown) even once the product stops getting real orders.

Goal: a lightweight "hidden" flag that removes a product from those forward-looking lists once it has nothing to show, without touching matching behavior or historical reports.

## Data model

Add one column via migration:

| Column | Type | Notes |
|---|---|---|
| `is_hidden` | boolean, default `false` | Add to existing `products` table. |

No change to `Product`'s `fillable`, matching methods (`matchesText`, `getKeywordsArrayAttribute`, etc.) — hiding never affects order matching. A hidden product keeps claiming incoming orders exactly as before; this is a display-only flag.

## Behavior — where hidden products disappear vs. stay visible

**Always visible** (unaffected by this feature):
- Product Management page itself — a hidden product still lists under its team group, visually marked "Hidden" (dimmed row + small badge), so it can be found and un-hidden.

**Excluded only when there's nothing to show for the current view:**
- **Leads Report per-product tables** (`LeadsReportController::index()`'s `$productTables`, and the ALL view's `$productRows` in `indexAll()`): a hidden product is skipped *unless* `ProductPerformance::buildRow()`'s `total` for it is non-zero in the selected date range. A report for a month when the product was still active keeps showing its breakdown; today's report (after it's gone quiet) does not.
- **Charts product-comparison row** (`ChartsController::index()`'s `$productRows`): same non-zero-total rule.

**Always excluded** (no historical carve-out — this is a filter shortcut, not a data view):
- **TSA Performance's product filter dropdown** (`$availableProducts` in `TsaPerformanceController::index()`): hidden products never appear as an option. Selecting "All Products" (the default) still includes their data correctly regardless.

## UI

New eye / eye-slash icon button on each Product Management row, between the existing edit and delete icons — single click toggles `is_hidden`, no modal. Hidden rows get reduced opacity and a small "Hidden" text badge next to the display name.

## Controller / route changes

New route + controller method, mirroring the existing `destroy` action's simplicity:

```text
PATCH /product-management/{product}/toggle-hidden   ProductManagementController::toggleHidden
```

`toggleHidden()` flips `is_hidden` and redirects back with a flash message ("Hidden \"X\"." / "Unhidden \"X\".") — no request body needed, just a plain form-submit button like the existing delete button.

## Call-site changes

1. `LeadsReportController::index()` — after building `$productTables`, filter out entries where `$product->is_hidden && $table['total']['total'] === 0`. Same rule applied to `indexAll()`'s `$productRows`.
2. `ChartsController::index()` — same non-zero-total filter applied to its `$productRows` before sorting by upselling rate.
3. `TsaPerformanceController::index()` — `$availableProducts` query adds `->where('is_hidden', false)`.

## What does NOT change

- Order matching, team inference, sync behavior — completely untouched. Hiding is invisible to `SyncTodayOrders`.
- Delete stays as-is: a separate, fully destructive action. Hide is additive, not a replacement.
- `Product`'s matching methods (`matchesText`, `getKeywordsArrayAttribute`, etc.) are untouched. `is_hidden` doesn't need to be added to `$fillable` either — `toggleHidden()` sets it directly rather than through mass assignment.

## Testing

- Feature test: toggling `is_hidden` on a product with zero orders in the current Leads Report date range removes its table from the response; a product with the same flag but non-zero orders in range still shows its table.
- Feature test: `TsaPerformanceController`'s product dropdown excludes hidden products regardless of date range.
- Manual verification: hide a product with historical data, confirm today's Leads Report drops its table while a date range from when it was active still shows it.
