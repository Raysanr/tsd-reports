# Reconciliation Completeness Check Fix — Design

## Goal

Fix a false-positive bug in `pancake:reconcile`'s completeness check: it currently compares Pancake's `updated_at`-windowed order count against a local count keyed to a different, business-adjusted timestamp, producing misleading "sync may have missed a window" alerts even when sync is healthy.

## Background — confirmed root cause

`PancakeReconcile::checkCompleteness()` queries Pancake for `total_entries` in an `updateStatus=updated_at` window for "yesterday," then compares it against `Order::whereBetween('pancake_created_at', [...])->count()`. These two numbers were assumed to measure the same thing (per the method's doc comment: "using the exact same updated_at-window query SyncTodayOrders itself makes, so this is apples-to-apples") — they don't.

`pancake_created_at` is not raw Pancake data. It's set in `SyncTodayOrders::flushOrders()` (`app/Console/Commands/SyncTodayOrders.php:332`) to `$workedAt`, the result of `resolveWorkedAt()` — the timestamp of when a TSA's own tag was actually added (from Pancake's `histories` log), falling back to insertion time when no tag-history match exists. This is a deliberate design choice (Fix #13, see the surrounding comments in that file) so the rest of the app's reporting reflects "when this was worked," not raw Pancake metadata.

Confirmed empirically against live data for 2026-07-13:
- Pancake's `updated_at`-windowed count: **494**
- Pancake's `inserted_at`-windowed count: **364**
- Local count by `pancake_created_at`: **366**

Local tracks almost exactly with `inserted_at` (which makes sense — most orders fall back to insertion time), not `updated_at`. The ~128-order "gap" the completeness check flagged is mostly orders touched that day for reasons unrelated to a TSA working them (status changes, payment reconciliation, etc.) landing under a different `pancake_created_at` date — not missing sync data.

## Fix

Add a new column, `orders.pancake_updated_at`, that stores Pancake's raw `updated_at` value untouched — no business-logic adjustment, no fallback. Populate it during sync from a value `SyncTodayOrders` already computes (`$updatedPHT`, currently used only transiently for the day-boundary pagination cutoff, never persisted). Change the completeness check's local query to filter on this new column instead of `pancake_created_at`.

This makes the comparison genuinely apples-to-apples: both sides now measure "orders Pancake reports as touched in this window," with `pancake_updated_at` as the honest local mirror of Pancake's own field. Every existing report (Dashboard, Leads Report, TSA Performance, TSA-level rates) keeps reading `pancake_created_at` exactly as before — this fix adds a second, parallel column used only by the reconciliation completeness check. No existing report's behavior changes.

**No backfill.** Existing rows get `pancake_updated_at = NULL`. The completeness check only ever evaluates "yesterday" relative to when it runs, so any day it checks going forward will have been synced (and therefore populated) after this ships.

## Changes

### 1. Migration — `orders.pancake_updated_at`

New nullable `timestamp` column, indexed (mirrors the existing `pancake_created_at` index — `database/migrations/2026_07_01_..._create_orders_table.php` already indexes that column, and the new one is queried the same way via `whereBetween`).

### 2. `Order` model

Add `pancake_updated_at` to `$fillable` and cast it to `datetime`, matching the existing `pancake_created_at` entry exactly.

### 3. `SyncTodayOrders::flushOrders()`

The row array built at `app/Console/Commands/SyncTodayOrders.php:314-334` gets one new key: `'pancake_updated_at' => $updatedPHT?->toDateTimeString()`, using the `$updatedPHT` variable already computed at line 228 for the pagination cutoff check — no new parsing logic needed. Add `'pancake_updated_at'` to the `Order::upsert()` update-columns list (currently at lines 393-396).

### 4. `PancakeReconcile::checkCompleteness()`

Change `Order::whereBetween('pancake_created_at', [...])` to `Order::whereBetween('pancake_updated_at', [...])`. Update the method's doc comment (it currently claims apples-to-apples comparison that wasn't actually true) and the `COMPLETENESS_THRESHOLD` comment (currently attributes the need for a <100% threshold to "a small number of backlog orders" shifting across the day boundary — that reasoning was describing symptoms of this bug, not a real minor edge case; the corrected comment should instead note the threshold covers in-flight delta-sync lag near the day boundary, a much smaller and genuinely expected gap).

## Testing

- `OrderFactory::definition()` gets `pancake_updated_at` added, defaulting to `now()` (same pattern as `pancake_created_at`).
- The two existing completeness tests in `tests/Feature/PancakeReconcileTest.php` (`test_flags_a_day_where_pancake_reports_far_more_orders_than_are_synced_locally`, `test_does_not_flag_a_day_where_local_count_is_close_to_pancakes`) need `pancake_updated_at` added alongside `pancake_created_at` in their `Order::factory()->create([...])` calls — without this, both would start failing once the completeness check reads the new column (the factory-created orders would have `pancake_updated_at = null`, never falling inside any date window).
- New test: an order factory-created with `pancake_created_at` on one day and `pancake_updated_at` on a *different* day (simulating a backlog lead worked today but touched again yesterday, or vice versa) confirms the completeness check now follows `pancake_updated_at`, not `pancake_created_at` — this is the regression test that would have caught the original bug.
- `SyncTodayOrders` already has test coverage (`tests/Feature/SyncTodayOrdersBacklogTest.php`, `SyncTodayOrdersProductTeamInferenceTest.php`) exercising `flushOrders()` — no new test file needed there, but confirm existing tests still pass with the new column present (they should, since it's purely additive and no existing assertion checks the full column list of an upsert).

## Out of Scope

- No backfill of the 147,945 existing `orders` rows — confirmed with the user.
- No change to `pancake_created_at`'s meaning, computation, or any of its existing consumers (Dashboard, Leads Report, TSA Performance, TSA-level rate calculations) — this fix is additive only.
- No change to the tag-drift check (`checkTagDrift()`) — unaffected by this bug, already verified working correctly against live data.
- No change to `COMPLETENESS_THRESHOLD`'s numeric value (stays `0.9`) — only its justifying comment changes, since the corrected comparison should already land very close to 100% in the normal case.
