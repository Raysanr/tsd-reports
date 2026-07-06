# "ALL" Per-Product Summary Report

## Problem

The TSA Performance page (`/tsa-performance`) currently only shows one report shape: an hourly, per-TSA breakdown, scoped to a single team (SH Naturals or Eyecare) selected via a team-button toggle. The user's reference spreadsheet ("Daily Sales per Product Report") shows a different, complementary view they also need: one row per **product** (across both teams combined), with Total/Catered/Excess Leads and three rate columns (Pick-up Rate, Conversion Rate, Upselling Rate), plus a Grand Total row.

Goal: add a third option — "ALL" — to the existing team-button toggle. Selecting it swaps the page's content to this new per-product summary table instead of the hourly per-TSA grid.

## Confirmed formulas (official reference — "TSD Updated Formula Base")

- **Answered Called Leads** = Confirmed via Call + Upsell w/ Confirmation + Call Back + Call Dropped + Repeat Order w/ Upsell + Rude Customer + Relatives Confirmation (the same 7 columns already grouped `'answered'` in `TsaPerformanceController::METRIC_COLUMNS`).
- **Unanswered Leads** = DFR + Double Order + FSD Uncleared + Not Answering + Unattended + Invalid Number (the same 6 columns already grouped `'unanswered'`).
- **Total Called Leads** = Answered + Unanswered (this does **not** include Excess/Uncatered — a narrower figure than the report's "Total Leads" column, which includes everything).
- **Pick-up Rate** = Answered ÷ Total Called Leads (i.e. Answered ÷ (Answered + Unanswered))
- **Conversion Rate** = Upsell w/ Confirmation ÷ **Answered** (NOT Answered + Unanswered — confirmed directly against the reference infographic's exact wording, "Total Answered Called Leads")
- **Upselling Rate** = Upsell w/ Confirmation ÷ (Upsell w/ Confirmation + Confirmed via Call)

All three rates are `null` (rendered as `—`) when their denominator is 0, consistent with the existing `conversionRate()` helper's null-when-no-leads convention.

## Scope decisions (confirmed with user)

- Selecting "ALL" **replaces** the hourly per-TSA grid entirely — it is a different report shape, not an addition to the existing one.
- The existing per-TSA hourly report's "Upselling Rate" column is **also being fixed** as part of this work: it currently computes `upsell ÷ total`, which matches neither official formula. It gets corrected to the official Upselling Rate formula (`upsell ÷ (upsell + confirmed_via_call)`) and its backing method/variable names are renamed from `conversionRate()`/`conversion_rate` to `upsellingRate()`/`upselling_rate` for clarity, since it's a different metric than "Conversion Rate" in this new report.
- The new report respects the existing single-date picker — no date-range support needed.

## Data flow

In `TsaPerformanceController::index()`, immediately after resolving `$selectedTeam` from the request:

```php
$selectedTeam = request('team', 'sh-naturals');
if ($selectedTeam === 'all') {
    return $this->indexAll($date, $showEmpty); // showEmpty unused today but passed for symmetry
}
```

This must be checked **before** the existing `if (!array_key_exists($selectedTeam, $teamsConfig))` fallback line, since `'all'` is not a key in `config('teams')` and would otherwise incorrectly reset to `'sh-naturals'`.

A new private method, `indexAll(Carbon $date): \Illuminate\Http\Response`, does NOT reuse `buildRow()` (which is TSA/hour-shaped) — it introduces a new private method, `buildProductRow(Product $product, Collection $orders): array`, that computes one row per product:

```php
private function buildProductRow(Product $product, Collection $orders): array
{
    $matching = $orders->filter(fn($o) => $o->product && stripos($o->product, $product->effective_keyword) !== false);

    $row = [
        'display_name'           => $product->display_name,
        'team'                   => $product->team,
        'total'                  => $matching->count(),
        'confirmed_via_call'     => $this->count($matching, 'confirmed via call'),
        'upsell_confirmation'    => $matching->where('is_upsell', true)->count(),
        'call_back'              => $this->count($matching, 'call back'),
        'call_dropped'           => $this->count($matching, 'call dropped'),
        'repeat_order_upsell'    => $this->count($matching, 'repeat order'),
        'rude_customer'          => $this->count($matching, 'rude customer'),
        'relatives_confirmation' => $this->count($matching, 'relatives'),
        'dfr'                    => $this->count($matching, 'dfr'),
        'double_order'           => $this->count($matching, 'double order'),
        'fsd_uncleared'          => $this->count($matching, 'fsd'),
        'not_answering'          => $this->count($matching, 'not answering'),
        'unattended'             => $this->count($matching, 'unattended'),
        'invalid_number'         => $this->count($matching, 'invalid number'),
    ];

    $row['excess']  = $matching->filter(fn($o) => $o->disposition === null || $o->disposition === 'UNCATERED LEADS')->count();
    $row['catered'] = $row['total'] - $row['excess'];

    $answered   = $row['confirmed_via_call'] + $row['upsell_confirmation'] + $row['call_back'] + $row['call_dropped']
                + $row['repeat_order_upsell'] + $row['rude_customer'] + $row['relatives_confirmation'];
    $unanswered = $row['dfr'] + $row['double_order'] + $row['fsd_uncleared'] + $row['not_answering']
                + $row['unattended'] + $row['invalid_number'];
    $totalCalled = $answered + $unanswered;

    $row['pick_up_rate']    = $totalCalled > 0 ? round($answered / $totalCalled * 100, 1) : null;
    // Denominator is Answered only, NOT Answered + Unanswered — confirmed against
    // the reference infographic's exact wording ("Total Answered Called Leads").
    $row['conversion_rate'] = $answered > 0 ? round($row['upsell_confirmation'] / $answered * 100, 1) : null;
    $row['upselling_rate']  = $this->upsellingRate($row);

    return $row;
}
```

`indexAll()` loads `Product::orderBy('team')->orderBy('sort_order')->get()`, the full day's orders across **both** teams' `order_team` values (reusing the same `whereBetween('pancake_created_at', ...)` window as the existing query, but with `whereIn('team', [...both order_team values...])` instead of the roster-based `whereIn('tsa_name', ...)` — no TSA roster involved at all in this view), builds one `buildProductRow()` per product, and sums a Grand Total row the same way the existing `$totals` accumulator works today (loop `self::COLUMNS`-equivalent keys, add each row's values).

Note this filters purely by the `team` column, so it automatically includes orders with no `tsa_name` at all (the ones the hourly view surfaces as a separate "Unassigned" row) — no equivalent special-casing is needed here, since this view was never grouped by TSA to begin with.

Returns a new view, `tsa-performance-all` (see below), with `productRows`, `grandTotal`, `selectedDate`, `teams` (for the button group), `showEmpty` — deliberately NOT `hourBlocks`/`metricCols`/`availableProducts`/`selectedProduct`, since those are hourly-view-only concepts that don't apply here.

## UI

- **Team button group** (in `tsa-performance.blade.php`'s `@push('topbar-right')` section): add an "ALL" button alongside the existing per-team buttons, submitting `team=all`. Active-state styling matches the existing buttons' pattern.
- **Product filter dropdown**: hidden when `$selectedTeam === 'all'` (it's a per-team, per-product filter that doesn't make sense once you're already viewing all products at once).
- **New view file**: `resources/views/tsa-performance-all.blade.php` (separate file, not a conditional block inside the existing view — the two table shapes are different enough that a separate file is clearer than a large `@if/@else` splitting one file in two). Extends the same `layouts.app`, reuses the existing page's color/typography conventions (font-mono, the same green/red/rose/yellow color coding for Answered/Unanswered/Excess/Upselling groups established in the current report).
- Table columns, left to right: TSA'S column is replaced by **Product** (with a small team label/badge under the product name, since products from both teams are listed together); Total Leads; Catered Leads; the same 13 disposition columns grouped Answered/Unanswered (identical styling to today); Excess Leads; Pick-up Rate; Conversion Rate; Upselling Rate. One row per product (grouped visually by team — SH Naturals products first, then Eyecare, matching `Product::orderBy('team')->orderBy('sort_order')`), then a Grand Total row styled like the existing hourly view's grand total row.
- Empty state: if there are zero orders for the selected date across both teams, reuse the existing "No data for {date}" empty-state block already in `tsa-performance.blade.php`.

## Fix to the existing per-TSA report

In `TsaPerformanceController.php`:
- Rename `conversionRate(array $columns): ?float` → `upsellingRate(array $columns): ?float`.
- Change its formula from `$columns['upsell_confirmation'] / $columns['total'] * 100` to `$columns['upsell_confirmation'] / ($columns['upsell_confirmation'] + $columns['confirmed_via_call']) * 100`, guarding the new denominator being 0 (returns `null`) instead of the old `total <= 0` guard.
- Update all 3 call sites (`buildRow()`, per-hour `$block['conversion_rate']`, and `$totalConversionRate`) to use the new method name, and rename the `conversion_rate` key to `upselling_rate` in the row/block arrays and the `$totalConversionRate` variable to `$totalUpsellingRate`.
- In `tsa-performance.blade.php`, update the 3 places referencing `conversion_rate`/`totalConversionRate` to `upselling_rate`/`totalUpsellingRate`. The column header text itself ("Upselling Rate") does not change — only the backing variable/method names, since the header was already correctly labeled; only the formula and internal naming were wrong.

## What does NOT change

- The existing per-TSA hourly report's structure, the Unassigned-row logic, the Excess/Catered definition, and the Product Management CRUD page are untouched.
- No new database tables or migrations — this is a pure read/aggregation feature over existing `Order` and `Product` data.

## Testing

- Feature test: `buildProductRow()` produces correct Total/Catered/Excess and all three rates for a product with known fixture orders, including a zero-orders product showing `null` rates (not a division-by-zero error).
- Feature test: `GET /tsa-performance?team=all` renders the new per-product view (assert `productRows`/`grandTotal` view data, assert the hourly-view-only view data like `hourBlocks` is absent); `GET /tsa-performance?team=sh-naturals` still renders the existing hourly view unchanged (regression check).
- Feature test: the corrected `upsellingRate()` formula, verified against a fixture with known Upsell/Confirmed-via-call counts (e.g. upsell=3, confirmed=2 → 60.0, not the old total-based figure).
- Manual verification: load `team=all` for 2026-07-05 and confirm the Grand Total roughly matches the sum of the two teams' already-verified totals (SH Naturals 131/79/52 + Eyecare 150/104/46 → Grand Total 281 Total / 183 Catered / 98 Excess).
