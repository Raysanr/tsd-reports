# Time-Based Team Attribution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace "which TSA/product handled it" with "what hour it was created" as the rule for which team (`"Eyecare Team"` = Opening, `"SH Naturals"` = Closing) every synced `Order` counts toward — using the same `pancake_created_at` timestamp every report already buckets by, so team attribution and the hour column a lead appears under always agree. Backfill this for orders from 2026-09-05 onward only; retire the now-meaningless product-keyword team-inference machinery (`ReinferOrderTeams`, the Unmatched Orders page, the sibling-parcel team copy); reconcile `InsightsGenerator`'s own Opening/Closing hour split to the same midnight boundary; and make Leads Report's per-team product tables (plus a follow-up fix to yesterday's TSA Performance change) show every active, non-hidden product instead of just one team's own.

**Architecture:** One new pure function decides Opening vs. Closing from an hour integer, used at the single point `SyncTodayOrders.php` currently computes `team`. A new one-time Artisan command backfills existing Sep-5-onward orders using that same function, reading each order's own already-stored `pancake_created_at`. Every read-side report (`LeadsReportController`, `DashboardController`, `ChartsController`, `ProductPerformance`, `TsaPerformanceController`, `RtsReportController`, `SearchController`) needs zero changes — they already just read whichever of the two literal team strings is stored, and will keep doing so unaffected once the write-side rule changes underneath them.

**Tech Stack:** Laravel 12 (PHP 8.2), Eloquent, PHPUnit feature/unit tests, Blade views.

---

## Definitions locked in for this plan (do not re-derive or second-guess these)

- **Opening** = `"Eyecare Team"` (the `order_team` literal), hours **00:00–14:59** (`hour < 15`).
- **Closing** = `"SH Naturals"` (the `order_team` literal), hours **15:00–23:59** (`hour >= 15`).
- The hour used is `pancake_created_at`'s own hour — the SAME column/value every report already reads for hour-bucketing (this column is actually populated from `resolveWorkedAt()`'s result, not Pancake's literal order-creation timestamp — confirmed during investigation; using it is a deliberate choice so team attribution never disagrees with the hour a lead visibly appears under on any report).
- Backfill scope: orders with `pancake_created_at >= '2026-09-05 00:00:00'` only. Older orders keep whatever `team` they already have from the old "who handled it" logic — never touched.
- `TsaShift.team` (which team a TSA is administratively rostered under) is **completely unaffected** by this plan — it stays a fixed, admin-assigned attribute. Only `Order.team` changes meaning.
- `TsaPerformanceController::showTsa()` (a TSA's own individual page) needs **no structural change** — it already shows all of a TSA's orders across 24 hours and every product (fixed 2026-09-06), unfiltered by team. It only needs the small `is_hidden` follow-up fix in Task 4.

---

## File Structure

- **Modify:** `app/Console/Commands/SyncTodayOrders.php` — replace `extractTsaInfo()`'s `team` computation with a new time-based rule; `name`/`matched_tag` extraction (who worked it) stays completely unchanged.
- **Create:** `app/Support/TeamShiftWindow.php` — the single pure function (`forHour(int $hour): string`) both the sync command and the backfill command call, so the boundary is defined in exactly one place.
- **Create:** `app/Console/Commands/BackfillTimeBasedTeams.php` — one-time command, orders from 2026-09-05 onward only.
- **Create:** `tests/Unit/TeamShiftWindowTest.php` — boundary tests for the new pure function.
- **Create:** `tests/Feature/BackfillTimeBasedTeamsTest.php` — tests for the backfill command.
- **Modify:** `tests/Feature/SyncTodayOrdersProductTeamInferenceTest.php` — rewritten to test the new time-based rule (its old premise, product-keyword inference, no longer exists).
- **Delete:** `app/Console/Commands/ReinferOrderTeams.php`, `app/Http/Controllers/UnmatchedOrdersController.php`, `resources/views/unmatched-orders.blade.php`, `tests/Feature/UnmatchedOrdersTest.php`.
- **Modify:** `app/Http/Controllers/ProductManagementController.php` — remove the 3 `Artisan::call('orders:reinfer-teams')` trigger sites.
- **Modify:** `routes/web.php` — remove the 2 `unmatched-orders` routes and the now-unused `UnmatchedOrdersController` import.
- **Modify:** `resources/views/layouts/app.blade.php` — remove the "Unmatched Orders" sidebar nav link.
- **Modify:** `app/Console/Commands/LinkSeparateParcelOrders.php` — stop copying `team` between siblings (keep copying `tsa_name` only).
- **Modify:** `tests/Feature/LinkSeparateParcelOrdersTest.php` — update the one test asserting the old team-copy behavior.
- **Modify:** `app/Support/InsightsGenerator.php` — change `$isOpeningHour`'s lower bound from `6` to `0` (midnight), matching the new rule everywhere.
- **Modify:** `app/Http/Controllers/LeadsReportController.php` — per-team product tables show every active (non-hidden) product, not just one team's own.
- **Modify:** `app/Http/Controllers/TsaPerformanceController.php` — small follow-up: `showTsa()`'s `$products` query (already "every product" since 2026-09-06) gets an `is_hidden` filter it was missing.

---

## Task 1: The shared time-window function

**Files:**
- Create: `app/Support/TeamShiftWindow.php`
- Create: `tests/Unit/TeamShiftWindowTest.php`

### Background you need

`config/teams.php` defines the two literal `order_team` values this whole app already uses everywhere: `"SH Naturals"` and `"Eyecare Team"`. Per this plan's locked-in definitions above, Eyecare Team = Opening (00:00–14:59), SH Naturals = Closing (15:00–23:59). This function is the ONE place that boundary is defined — both `SyncTodayOrders.php` (new orders going forward) and the backfill command (existing Sep-5-onward orders) call it, so the boundary can never drift between the two.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/TeamShiftWindowTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\TeamShiftWindow;
use Tests\TestCase;

/**
 * Explicit request (2026-09-07): Order.team is switching from "which TSA/
 * product handled it" to "what hour it was created" — Opening (Eyecare
 * Team) = 00:00-14:59, Closing (SH Naturals) = 15:00-23:59. This is the
 * one place that boundary is defined; SyncTodayOrders.php and the
 * backfill command both call it so the boundary can never drift between
 * the two.
 */
class TeamShiftWindowTest extends TestCase
{
    public function test_midnight_is_opening(): void
    {
        $this->assertSame('Eyecare Team', TeamShiftWindow::forHour(0));
    }

    public function test_2pm_is_still_opening(): void
    {
        $this->assertSame('Eyecare Team', TeamShiftWindow::forHour(14));
    }

    public function test_3pm_is_the_first_closing_hour(): void
    {
        $this->assertSame('SH Naturals', TeamShiftWindow::forHour(15));
    }

    public function test_11pm_is_still_closing(): void
    {
        $this->assertSame('SH Naturals', TeamShiftWindow::forHour(23));
    }

    public function test_rejects_an_hour_outside_0_to_23(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TeamShiftWindow::forHour(24);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Unit/TeamShiftWindowTest.php
```
Expected: FAIL — `App\Support\TeamShiftWindow` doesn't exist yet ("Class not found").

- [ ] **Step 3: Write the implementation**

Create `app/Support/TeamShiftWindow.php`:

```php
<?php

namespace App\Support;

/**
 * The single source of truth for "which team does this hour belong to" —
 * explicit request, 2026-09-07: Order.team switched from "which TSA/
 * product handled it" to "what hour it was created," using the same
 * pancake_created_at value every report already buckets by. Both
 * SyncTodayOrders.php (new orders going forward) and
 * BackfillTimeBasedTeams (existing 2026-09-05-onward orders) call
 * forHour() so the boundary is defined in exactly one place and can
 * never drift between the two.
 *
 * Opening = Eyecare Team, 00:00-14:59. Closing = SH Naturals, 15:00-23:59.
 * These literal order_team strings are the same two values
 * config('teams') has always used — this does not introduce a third
 * team or rename either one; App\Support\Teams's own dated
 * display-name resolution (e.g. showing "Team Opening"/"Team Closing"
 * instead of "Eyecare"/"SH Naturals") is completely separate and
 * unaffected by this class.
 */
class TeamShiftWindow
{
    private const OPENING_TEAM = 'Eyecare Team';
    private const CLOSING_TEAM = 'SH Naturals';

    /** $hour: 0-23, the hour-of-day component of pancake_created_at. */
    public static function forHour(int $hour): string
    {
        if ($hour < 0 || $hour > 23) {
            throw new \InvalidArgumentException("Hour must be 0-23, got {$hour}.");
        }

        return $hour < 15 ? self::OPENING_TEAM : self::CLOSING_TEAM;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test tests/Unit/TeamShiftWindowTest.php
```
Expected: PASS, all 5 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Support/TeamShiftWindow.php tests/Unit/TeamShiftWindowTest.php
git commit -m "Add the single time-window rule for team attribution

Opening (Eyecare Team) = 00:00-14:59, Closing (SH Naturals) =
15:00-23:59 — the one place this boundary is defined, so the sync
command and the backfill command can never disagree."
```

---

## Task 2: Switch SyncTodayOrders' team computation to time-based

**Files:**
- Modify: `app/Console/Commands/SyncTodayOrders.php`
- Modify: `tests/Feature/SyncTodayOrdersProductTeamInferenceTest.php`

### Background you need

`extractTsaInfo()` (`app/Console/Commands/SyncTodayOrders.php:777-864`) currently returns `['name' => ?, 'team' => ?, 'matched_tag' => ?]` via a 6-step priority chain (seller match → tag match → 3 product-keyword fallbacks → null). This task changes ONLY what `team` resolves to — `name`/`matched_tag` (who worked the order, used elsewhere for TSA attribution and for `resolveWorkedAt()`'s tag-timestamp lookup) must keep working exactly as they do today. `inferTeamFromProduct()` (lines 866-885) becomes fully unused after this change and should be deleted.

The `team` value is written into the row at line 492 (`'team' => $tsaInfo['team']`), which happens AFTER `$workedAt` is computed at line 450 (`$workedAt = self::resolveWorkedAt(...)`) and used to populate `pancake_created_at` at line 525 (`'pancake_created_at' => $workedAt?->toDateTimeString()`). Since the new rule needs `$workedAt`'s own hour, the team computation must move to AFTER line 450, not stay inside `extractTsaInfo()` (which runs before `$workedAt` exists at all — `extractTsaInfo()` is called at line 415, `resolveWorkedAt()` at line 450).

A `$workedAt` of `null` is possible (see `resolveWorkedAt()`'s own signature — it returns `?Carbon`) — per this method's own doc comment (lines 417-424), that only happens when `$carbonPHT` (the raw insertion time) is itself null, which per lines 279-281 only happens when Pancake's own `inserted_at`/`created_at` fields are both missing from the raw payload — an extremely rare/malformed-data edge case. When `$workedAt` is null, `team` must also be `null` (there's no hour to compute anything from) — same as today's existing "nothing matched" null case, so no report that already handles a null `team` needs any further change.

- [ ] **Step 1: Write the failing test — replace the old product-inference test**

The existing `tests/Feature/SyncTodayOrdersProductTeamInferenceTest.php` tests product-keyword-based team inference, which no longer exists under the new rule. Replace its entire contents:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bug fix (2026-09-07): Order.team switched from "which TSA/product
 * handled it" to "what hour it was created" (TeamShiftWindow::forHour()) —
 * replaces the old product-keyword-inference test this file used to
 * contain, since that mechanism (inferTeamFromProduct()) no longer
 * exists. team is derived from pancake_created_at's own hour, which is
 * itself populated from resolveWorkedAt()'s result (the tag-add time when
 * one exists, otherwise the raw insertion time) — NOT the literal Pancake
 * order-creation timestamp. tsa_name/matched_tag (who worked the order)
 * are completely unaffected by this change — see
 * SyncTodayOrdersAccountBasedTsaAttributionTest for that coverage, none
 * of which needed updating for this fix.
 */
class SyncTodayOrdersProductTeamInferenceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeOnePage(array $order): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::sequence()
                ->push(['data' => [$order]])
                ->push(['data' => []]),
        ]);
    }

    public function test_an_order_worked_at_10am_is_opening_team(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        // No tags at all -> resolveWorkedAt() falls back to inserted_at
        // directly (no tag-add timestamp to prefer) -> pancake_created_at
        // ends up as the raw insertion time itself, 2026-09-05 10:00 PHT.
        $this->fakeOnePage([
            'id' => 9101, 'status' => 0, 'total_price' => 500,
            'inserted_at' => '2026-09-05T02:00:00', // UTC -> 10:00 AM Asia/Manila
            'updated_at'  => '2026-09-05T02:00:00',
            'tags' => [], 'items' => [['variation_info' => ['name' => 'Anything', 'retail_price' => 500], 'quantity' => 1]],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-09-05']);

        $order = Order::where('pancake_order_id', '9101')->first();
        $this->assertNotNull($order);
        $this->assertSame('Eyecare Team', $order->team);
        $this->assertNull($order->tsa_name, 'nobody claimed this lead, so no TSA name should be invented');
    }

    public function test_an_order_worked_at_8pm_is_closing_team(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id' => 9102, 'status' => 0, 'total_price' => 500,
            'inserted_at' => '2026-09-05T12:00:00', // UTC -> 8:00 PM Asia/Manila
            'updated_at'  => '2026-09-05T12:00:00',
            'tags' => [], 'items' => [['variation_info' => ['name' => 'Anything', 'retail_price' => 500], 'quantity' => 1]],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-09-05']);

        $order = Order::where('pancake_order_id', '9102')->first();
        $this->assertNotNull($order);
        $this->assertSame('SH Naturals', $order->team);
    }

    public function test_team_follows_the_workedat_tag_time_not_the_raw_insertion_time(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        // Order inserted at 1:00 AM (would be Opening if raw insertion time
        // were used), but the TSA's own name tag wasn't actually added
        // until 4:00 PM the same day per histories -> resolveWorkedAt()
        // anchors to that tag-add time instead -> Closing, not Opening.
        Setting::set('pancake_api_key', 'test-key');
        $this->fakeOnePage([
            'id' => 9103, 'status' => 0, 'total_price' => 500,
            'inserted_at' => '2026-09-05T17:00:00', // UTC -> 1:00 AM Asia/Manila (next day boundary avoided: use same-day UTC->PHT offset carefully)
            'updated_at'  => '2026-09-05T17:00:00',
            'tags' => [['id' => 1, 'name' => 'GEMMA']],
            'items' => [['variation_info' => ['name' => 'Anything', 'retail_price' => 500], 'quantity' => 1]],
            'histories' => [[
                'tags' => ['old' => [], 'new' => [['id' => 1, 'name' => 'GEMMA']]],
                'updated_at' => '2026-09-05T08:00:00', // UTC -> 4:00 PM Asia/Manila
                'editor_id' => 555,
            ]],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-09-05']);

        $order = Order::where('pancake_order_id', '9103')->first();
        $this->assertNotNull($order);
        $this->assertSame('Gemma', $order->tsa_name);
        $this->assertSame('SH Naturals', $order->team, 'team must follow the tag-add time (4pm, Closing), not the raw insertion time (which this fixture deliberately set to a different hour)');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Feature/SyncTodayOrdersProductTeamInferenceTest.php
```
Expected: FAIL — current code still computes `team` via seller/tag/product-keyword matching, not time-based, so `test_an_order_worked_at_10am_is_opening_team` will get `null` (no seller/tag/product match) instead of `'Eyecare Team'`.

- [ ] **Step 3: Read the current write-site code**

```bash
grep -n "tsaInfo\s*=\|workedAt\s*=\|'team'\s*=>" app/Console/Commands/SyncTodayOrders.php
```
Confirms: line 415 (`$tsaInfo = $this->extractTsaInfo(...)`), line 450 (`$workedAt = self::resolveWorkedAt(...)`), line 492 (`'team' => $tsaInfo['team']`, inside the `$parsed[]` array starting at line 485).

- [ ] **Step 4: Add the time-based team computation after `$workedAt` is known**

In `app/Console/Commands/SyncTodayOrders.php`, find (around line 450):
```php
            $workedAt = self::resolveWorkedAt($raw, $disposition ?? $tsaInfo['matched_tag'], $carbonPHT);
```

Immediately after that line, add:
```php

            // Time-based team attribution (explicit request, 2026-09-07,
            // replacing "which TSA/product handled it" — see
            // TeamShiftWindow's own doc comment for the full reasoning).
            // Uses $workedAt's own hour, the SAME value that becomes this
            // row's pancake_created_at below, so team attribution can
            // never disagree with the hour column every report already
            // buckets this order under. A null $workedAt (only possible
            // when Pancake's raw payload has no inserted_at/created_at at
            // all — see resolveWorkedAt()'s own doc comment) means there's
            // no hour to compute a team from, same as any other
            // unresolvable case.
            $team = $workedAt ? \App\Support\TeamShiftWindow::forHour((int) $workedAt->format('G')) : null;
```

- [ ] **Step 5: Use the new `$team` variable instead of `$tsaInfo['team']`**

Find (around line 492):
```php
                'team'                    => $tsaInfo['team'],
```

Replace with:
```php
                'team'                    => $team,
```

- [ ] **Step 6: Remove `extractTsaInfo()`'s now-unused `team`-returning branches**

`extractTsaInfo()` (lines 777-864) still needs to return `name`/`matched_tag` exactly as before — only its `team` field is now ignored by the caller (Step 5 stopped reading it). Rather than leave dead `team` values being computed and discarded, simplify the method to stop computing `team` at all. Find:
```php
        if ($sellerInfo) {
            return $sellerInfo + ['matched_tag' => $matchedTag];
        }

        // Fallback: no assigning_seller match — e.g. a single-item order (nothing at
        // index 1+ to check at all) or a genuinely new TSA whose seller_keywords
        // hasn't been configured on the TSA Management page yet. Same tag-scan this
        // method always used as its primary signal before the account check above.
        foreach ($tagNames as $tag) {
            $key = strtoupper(trim($tag));
            if (isset($this->tsaMap[$key])) {
                return $this->tsaMap[$key] + ['matched_tag' => $key];
            }
        }

        // Fix #15: last resort — no TSA tag AND no seller match means nobody ever
        // claimed this lead (e.g. a brand-new order swept by the midnight "UNCATERED
        // LEADS" bulk action before any human touched it). It still has a real product
        // in its cart though (captured separately as $productName from
        // extractUpsellProduct()), so match that against each team's product list to
        // recover the TEAM — never a TSA name, since genuinely nobody claimed it — so
        // the lead counts as Excess for the right team instead of vanishing from every
        // report (confirmed via production data: 46 such orders on one date alone,
        // 41 of them clearly SH Naturals products by cart contents).
        if ($team = $this->inferTeamFromProduct($productName)) {
            return ['name' => null, 'team' => $team, 'matched_tag' => null];
        }

        // Cart name alone matched nothing — a combo SKU's generic name only ever
        // names its primary component, so try the full bundle description text
        // (e.g. "1 Ginseng Serum + 5 Scar Cream") before falling through to tags.
        if ($team = $this->inferTeamFromProduct($bundleDescription)) {
            return ['name' => null, 'team' => $team, 'matched_tag' => null];
        }

        // Cart name matched nothing — try the order's tags too (a lead sometimes
        // carries a product tag like "CLEARSIGHT" even when nobody claimed it).
        foreach ($tagNames as $tag) {
            if ($team = $this->inferTeamFromProduct($tag)) {
                return ['name' => null, 'team' => $team, 'matched_tag' => null];
            }
        }

        return ['name' => null, 'team' => null, 'matched_tag' => null];
    }

    private function inferTeamFromProduct(?string $productName): ?string
    {
        if (!$productName) return null;

        // Sourced from the products table (Product Management page) instead of
        // config/teams.php — see docs/superpowers/specs/2026-07-06-product-management-design.md.
        // $this->products is loaded once in handle() (same reasoning as $tsaMap/
        // $sellerMap) so this doesn't re-query on every single order.
        // matchesText (not stripos on one keyword): honors every configured alias
        // and ignores spacing/punctuation, so a cart named "Clear Sight 3.0" maps
        // to CLEARSIGHT's team instead of leaving the lead team-NULL and therefore
        // invisible to every report (122 such leads in the 14 days before this fix).
        foreach ($this->products as $product) {
            if ($product->matchesText($productName)) {
                return $product->team;
            }
        }

        return null;
    }
```

Replace with:
```php
        if ($sellerInfo) {
            return $sellerInfo + ['matched_tag' => $matchedTag];
        }

        // Fallback: no assigning_seller match — e.g. a single-item order (nothing at
        // index 1+ to check at all) or a genuinely new TSA whose seller_keywords
        // hasn't been configured on the TSA Management page yet. Same tag-scan this
        // method always used as its primary signal before the account check above.
        foreach ($tagNames as $tag) {
            $key = strtoupper(trim($tag));
            if (isset($this->tsaMap[$key])) {
                return $this->tsaMap[$key] + ['matched_tag' => $key];
            }
        }

        // Nobody claimed this lead (e.g. a brand-new order swept by the
        // midnight "UNCATERED LEADS" bulk action before any human touched
        // it). No TSA name to attribute — team is computed separately from
        // $workedAt's own hour regardless of whether a TSA is ever found
        // here (see TeamShiftWindow, called from flushOrders() directly).
        return ['name' => null, 'matched_tag' => null];
    }
```

- [ ] **Step 7: Check every remaining reference to `$tsaInfo['team']` or `inferTeamFromProduct`**

```bash
grep -n "tsaInfo\['team'\]\|inferTeamFromProduct" app/Console/Commands/SyncTodayOrders.php
```
Expected: no output — confirms both are fully removed/replaced.

- [ ] **Step 8: Run the new test to verify it passes**

```bash
php artisan test tests/Feature/SyncTodayOrdersProductTeamInferenceTest.php
```
Expected: PASS, all 3 tests.

- [ ] **Step 9: Run every other SyncTodayOrders test to check for regressions**

```bash
php artisan test --filter=SyncTodayOrders
```
Expected: all pass. These test `tsa_name`/`matched_tag`/disposition/amount extraction, none of which this task touched — `SyncTodayOrdersAccountBasedTsaAttributionTest` in particular should be completely unaffected (it never asserts on `team`).

- [ ] **Step 10: Run the full test suite**

```bash
php artisan test
```
Expected: failures only in files this plan's later tasks will fix (`LinkSeparateParcelOrdersTest`, anything depending on `ReinferOrderTeams`/`UnmatchedOrdersController`, which don't exist yet at this point in the plan — those are Task 3). If anything else fails, stop and investigate before continuing — do not proceed to Task 3 with an unexplained failure.

- [ ] **Step 11: Commit**

```bash
git add app/Console/Commands/SyncTodayOrders.php tests/Feature/SyncTodayOrdersProductTeamInferenceTest.php
git commit -m "Make Order.team time-based instead of who-handled-it

Every synced order's team is now decided by TeamShiftWindow::forHour()
against the same pancake_created_at hour every report already buckets
by, instead of TSA tag/seller matching or product keywords. Who
worked the order (tsa_name/matched_tag) is completely unaffected."
```

---

## Task 3: Retire product-keyword team re-inference (ReinferOrderTeams + Unmatched Orders)

**Files:**
- Delete: `app/Console/Commands/ReinferOrderTeams.php`
- Delete: `app/Http/Controllers/UnmatchedOrdersController.php`
- Delete: `resources/views/unmatched-orders.blade.php`
- Delete: `tests/Feature/UnmatchedOrdersTest.php`
- Modify: `app/Http/Controllers/ProductManagementController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`

### Background you need

Under the new rule, every order with a resolvable `$workedAt` always gets a real team — nothing can ever be `team = null` due to "no product keyword matched," since team no longer comes from product keywords at all. `ReinferOrderTeams` (`orders:reinfer-teams`) exists specifically to recover `team` for `WHERE team IS NULL AND tsa_name IS NULL` rows via product-keyword matching — that scenario can no longer meaningfully occur going forward, so the whole command (and everything that calls it) is retired.

- [ ] **Step 1: Confirm every call site before deleting**

```bash
grep -rn "reinfer-teams\|ReinferOrderTeams" app/ routes/ resources/ --include="*.php" --include="*.blade.php"
```
Expected output (confirmed during planning): `app/Http/Controllers/ProductManagementController.php` (3 hits: lines ~81, ~104, ~199), `app/Http/Controllers/UnmatchedOrdersController.php` (2 hits), `app/Console/Commands/ReinferOrderTeams.php` (itself), `resources/views/unmatched-orders.blade.php` (1 comment reference).

- [ ] **Step 2: Delete the command, controller, view, and its test**

```bash
rm app/Console/Commands/ReinferOrderTeams.php
rm app/Http/Controllers/UnmatchedOrdersController.php
rm resources/views/unmatched-orders.blade.php
rm tests/Feature/UnmatchedOrdersTest.php
```

- [ ] **Step 3: Remove the routes**

In `routes/web.php`, find:
```php
use App\Http\Controllers\UnmatchedOrdersController;
```
Delete that line.

Find:
```php
        Route::get('/unmatched-orders',          [UnmatchedOrdersController::class, 'index'])->name('unmatched-orders');
        Route::post('/unmatched-orders/reinfer', [UnmatchedOrdersController::class, 'reinfer'])->name('unmatched-orders.reinfer');
```
Delete both lines.

- [ ] **Step 4: Remove the sidebar nav link**

In `resources/views/layouts/app.blade.php`, find:
```php
        <a href="{{ route('unmatched-orders') }}" title="Unmatched Orders"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('unmatched-orders*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75c0-1.036.84-1.875 1.875-1.875h.75c1.036 0 1.875.84 1.875 1.875v.375c0 .621-.334 1.115-.807 1.454-.548.393-.943.978-.943 1.671v.25"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5h.008v.008H12V16.5z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18z"/>
            </svg>
            <span class="sidebar-label">Unmatched Orders</span>
        </a>
```
Delete the entire `<a>...</a>` block.

- [ ] **Step 5: Remove the 3 trigger sites in ProductManagementController**

In `app/Http/Controllers/ProductManagementController.php`, find (in `store()`):
```php
        // New keywords can claim previously unattributable (team-NULL) leads —
        // re-infer immediately so they appear in reports without waiting for a
        // manual command run. Only scans unclaimed team-NULL rows, so it's cheap.
        \Artisan::call('orders:reinfer-teams');

        $message = "Added \"{$data['display_name']}\".";
```
Replace with:
```php
        $message = "Added \"{$data['display_name']}\".";
```

Find (in `update()`):
```php
        // Same reasoning as store() — an added alias should immediately pull the
        // matching team-NULL leads into this team's reports.
        \Artisan::call('orders:reinfer-teams');

        $message = "Updated \"{$data['display_name']}\".";
```
Replace with:
```php
        $message = "Updated \"{$data['display_name']}\".";
```

Find (in `bulk()`, the `'move'` case):
```php
            case 'move':
                Product::whereIn('id', $data['ids'])->update(['team' => $data['team']]);
                // Same reasoning as store()/update() — a team change can affect which
                // team-NULL leads this product's keywords now claim.
                \Artisan::call('orders:reinfer-teams');
                $teamName = collect($teamsConfig)->firstWhere('order_team', $data['team'])['name'] ?? $data['team'];
                $message = "Moved {$count} {$noun} to {$teamName}.";
                break;
```
Replace with:
```php
            case 'move':
                Product::whereIn('id', $data['ids'])->update(['team' => $data['team']]);
                $teamName = collect($teamsConfig)->firstWhere('order_team', $data['team'])['name'] ?? $data['team'];
                $message = "Moved {$count} {$noun} to {$teamName}.";
                break;
```

- [ ] **Step 6: Run the product management tests**

```bash
php artisan test tests/Feature/ProductManagementControllerTest.php tests/Feature/ProductSoftDeleteTest.php
```
Expected: all pass — none of them assert on the `orders:reinfer-teams` side effect (confirmed by reading these files during planning), so removing the trigger calls doesn't change any assertion.

- [ ] **Step 7: Confirm nothing else references the deleted classes**

```bash
grep -rn "ReinferOrderTeams\|UnmatchedOrdersController\|reinfer-teams\|unmatched-orders" app/ routes/ resources/ tests/ --include="*.php" --include="*.blade.php"
```
Expected: no output at all.

- [ ] **Step 8: Run the full test suite**

```bash
php artisan test
```
Expected: all pass, no new failures.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "Retire product-keyword team re-inference and Unmatched Orders

Every order now gets a real team from TeamShiftWindow regardless of
product keywords, so team can no longer be null for a resolvable
order — nothing left to reinfer, and the Unmatched Orders page (built
around team=NULL rows) always showed 0 results going forward."
```

---

## Task 4: Stop copying team between separate-parcel siblings

**Files:**
- Modify: `app/Console/Commands/LinkSeparateParcelOrders.php`
- Modify: `tests/Feature/LinkSeparateParcelOrdersTest.php`

### Background you need

`LinkSeparateParcelOrders::handle()` (`app/Console/Commands/LinkSeparateParcelOrders.php:71-75`) copies `tsa_name` AND `team` from a tagged sibling order onto its untagged orphan sibling (same customer, same day, "separate parcel" tag group). Under the new rule this is wrong: each order computes its own `team` independently from its own `pancake_created_at` hour — two siblings of the same sale genuinely could straddle the 3pm boundary and correctly have DIFFERENT team values. `tsa_name` copying stays exactly as it is (who worked the sale is still a real thing to inherit; team no longer is).

- [ ] **Step 1: Write the failing test**

In `tests/Feature/LinkSeparateParcelOrdersTest.php`, find:
```php
    public function test_fills_in_a_missing_tsa_and_team_from_a_tagged_sibling_order(): void
    {
        Order::factory()->create([
            'pancake_order_id'   => 'base-1',
            'customer_phone'     => '09171234567',
            'team'               => 'Eyecare Team',
            'tsa_name'           => 'Joana',
            'raw_tags'           => ['JOANA', 'SEPARATE PARCEL'],
            'pancake_created_at' => '2026-08-11 14:00:00',
        ]);

        $orphan = Order::factory()->create([
            'pancake_order_id'   => 'upsell-1',
            'customer_phone'     => '09171234567',
            'team'               => null,
            'tsa_name'           => null,
            'raw_tags'           => [],
            'pancake_created_at' => '2026-08-11 14:05:00',
        ]);

        $this->artisan('pancake:link-parcels')->assertSuccessful();

        $orphan->refresh();
        $this->assertSame('Joana', $orphan->tsa_name);
        $this->assertSame('Eyecare Team', $orphan->team);
    }
```

Replace with:
```php
    /** Bug fix (2026-09-07): team is no longer copied between siblings —
     *  each order computes its own team independently from its own
     *  pancake_created_at hour (TeamShiftWindow), so two siblings of the
     *  same sale can correctly land in DIFFERENT teams if their own
     *  worked-at times straddle the 3pm boundary. Only tsa_name (who
     *  worked the sale) is still inherited. */
    public function test_fills_in_a_missing_tsa_name_but_leaves_team_alone(): void
    {
        Order::factory()->create([
            'pancake_order_id'   => 'base-1',
            'customer_phone'     => '09171234567',
            'team'               => 'Eyecare Team',
            'tsa_name'           => 'Joana',
            'raw_tags'           => ['JOANA', 'SEPARATE PARCEL'],
            'pancake_created_at' => '2026-08-11 14:00:00',
        ]);

        // This sibling's own team ("SH Naturals") was already independently
        // computed from ITS OWN pancake_created_at hour by SyncTodayOrders
        // — must survive untouched even though the base order above is a
        // different team.
        $orphan = Order::factory()->create([
            'pancake_order_id'   => 'upsell-1',
            'customer_phone'     => '09171234567',
            'team'               => 'SH Naturals',
            'tsa_name'           => null,
            'raw_tags'           => [],
            'pancake_created_at' => '2026-08-11 16:05:00',
        ]);

        $this->artisan('pancake:link-parcels')->assertSuccessful();

        $orphan->refresh();
        $this->assertSame('Joana', $orphan->tsa_name);
        $this->assertSame('SH Naturals', $orphan->team, 'team must NOT be copied from the sibling — each order keeps its own independently-computed team');
    }
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Feature/LinkSeparateParcelOrdersTest.php --filter=test_fills_in_a_missing_tsa_name_but_leaves_team_alone
```
Expected: FAIL — current code overwrites `$orphan->team` with `'Eyecare Team'` (copied from the base order), so the assertion `assertSame('SH Naturals', $orphan->team)` fails.

- [ ] **Step 3: Remove the team copy**

In `app/Console/Commands/LinkSeparateParcelOrders.php`, find:
```php
            foreach ($siblings as $sibling) {
                if ($sibling->tsa_name !== null) continue;
                $sibling->update(['tsa_name' => $sourceOrder->tsa_name, 'team' => $sourceOrder->team]);
                $linked++;
            }
```

Replace with:
```php
            foreach ($siblings as $sibling) {
                if ($sibling->tsa_name !== null) continue;
                // team is deliberately NOT copied here (2026-09-07) — each
                // order computes its own team independently from its own
                // pancake_created_at hour (TeamShiftWindow); two siblings
                // of the same sale can correctly land in different teams
                // if their own worked-at times straddle the 3pm boundary.
                $sibling->update(['tsa_name' => $sourceOrder->tsa_name]);
                $linked++;
            }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php artisan test tests/Feature/LinkSeparateParcelOrdersTest.php --filter=test_fills_in_a_missing_tsa_name_but_leaves_team_alone
```
Expected: PASS.

- [ ] **Step 5: Run the full LinkSeparateParcelOrdersTest file for regressions**

```bash
php artisan test tests/Feature/LinkSeparateParcelOrdersTest.php
```
Expected: all pass — the other 8 tests in this file test tag-matching/conflict-detection/misspelling-tolerance, none of which assert on `team` (confirmed by reading the file during planning; only this one test did).

- [ ] **Step 6: Run the full test suite**

```bash
php artisan test
```
Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/LinkSeparateParcelOrders.php tests/Feature/LinkSeparateParcelOrdersTest.php
git commit -m "Stop copying team between separate-parcel sibling orders

Each order now computes its own team independently from its own
pancake_created_at hour — two siblings of the same sale can correctly
land in different teams if their worked-at times straddle 3pm. Only
tsa_name (who worked the sale) is still inherited from a tagged
sibling."
```

---

## Task 5: Reconcile InsightsGenerator's Opening/Closing hour boundary

**Files:**
- Modify: `app/Support/InsightsGenerator.php`

### Background you need

`InsightsGenerator.php:1176` has its own, already-shipped Opening/Closing split for the EOD report's Lead Capacity & Distribution card: `$isOpeningHour = fn ($h) => $h >= 6 && $h < 15;` — Opening currently means 6am-3pm here, not the new rule's midnight-3pm. Per the locked-in decision, this must change to match everywhere: `$h < 15` (no lower bound — midnight is hour 0, already `< 15`, so the lower-bound check is simply removed). Note: line 1160 (`$isOpeningShift`, based on a TSA's own `shift_start` time) is a DIFFERENT, unrelated concept — it decides whether a TSA's shift itself starts before 3pm, not which hour an order was created in. Do NOT change line 1160.

- [ ] **Step 1: Write the failing test**

First check if a test already covers this exact line:
```bash
grep -rln "isOpeningHour\|openingLeads\|closingLeads" tests/
```

If a test file is found, read it fully before writing a new one (to match its existing conventions and avoid duplicating coverage). If no test file references this line at all, create `tests/Feature/InsightsGeneratorOpeningClosingHourTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Support\InsightsGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Bug fix (2026-09-07): InsightsGenerator's own Opening/Closing lead
 *  split used 6am-3pm, disagreeing with the new Order.team rule
 *  (midnight-3pm) used everywhere else. Reconciled to the same
 *  boundary so there's only one definition of "Opening"/"Closing"
 *  hours in the whole app. */
class InsightsGeneratorOpeningClosingHourTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_lead_created_at_1am_counts_as_opening_not_excluded(): void
    {
        $product = Product::where('team', 'Eyecare Team')->first();
        $shift = TsaShift::where('team', 'Eyecare Team')->first();

        Order::factory()->create([
            'team' => 'Eyecare Team', 'tsa_name' => $shift->tsa_key,
            'product' => $product->display_name, 'status_code' => 1,
            'pancake_created_at' => '2026-09-07 01:00:00',
        ]);

        $report = InsightsGenerator::generate('2026-09-07', '2026-09-07', 'eyecare');

        // Under the OLD 6am-3pm rule, a 1am lead fell into neither bucket
        // (openingLeads AND closingLeads both undercounted it). Under the
        // new midnight-3pm rule it must count as Opening.
        $this->assertGreaterThan(0, $report['openingLeads'] ?? $report['opening_leads'] ?? null);
    }
}
```

**IMPORTANT:** Before finalizing this test, run:
```bash
grep -n "function generate\|'openingLeads'\|'opening_leads'" app/Support/InsightsGenerator.php
```
to confirm the exact public method signature and the exact array key `$openingLeads` is returned under (the plan's investigation read the internal variable name `$openingLeads` at line 1177, but did not confirm the exact public return-array key or `generate()`'s full parameter list) — adjust the test's method call and assertion key to match what you find. Do not guess; read the actual `generate()` signature and its return statement before running this step.

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Feature/InsightsGeneratorOpeningClosingHourTest.php
```
Expected: FAIL under the old 6am boundary (a 1am order isn't counted as Opening).

- [ ] **Step 3: Fix the boundary**

In `app/Support/InsightsGenerator.php`, find:
```php
        $isOpeningHour = fn ($h) => $h >= 6 && $h < 15;
```

Replace with:
```php
        // Midnight-3pm (2026-09-07, reconciled to match TeamShiftWindow's
        // own Order.team boundary everywhere else in the app — this used
        // to be 6am-3pm, its own separate definition, which would have
        // disagreed with the new Order.team attribution rule).
        $isOpeningHour = fn ($h) => $h < 15;
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php artisan test tests/Feature/InsightsGeneratorOpeningClosingHourTest.php
```
Expected: PASS.

- [ ] **Step 5: Run every InsightsGenerator test for regressions**

```bash
php artisan test --filter=Insights
```
Expected: all pass. If any existing test hardcodes a lead between 6am and midnight expecting it to be EXCLUDED from both buckets under the old rule, it will need updating — read any failure's assertion carefully before changing it, since this boundary genuinely changed the correct answer for hours 0-5.

- [ ] **Step 6: Run the full test suite**

```bash
php artisan test
```
Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add app/Support/InsightsGenerator.php tests/Feature/InsightsGeneratorOpeningClosingHourTest.php
git commit -m "Reconcile InsightsGenerator's Opening/Closing hours to midnight-3pm

Was 6am-3pm, its own separate definition that disagreed with the new
Order.team time-based attribution rule (midnight-3pm) — now there's
only one definition of Opening/Closing hours in the app."
```

---

## Task 6: One-time backfill for orders since 2026-09-05

**Files:**
- Create: `app/Console/Commands/BackfillTimeBasedTeams.php`
- Create: `tests/Feature/BackfillTimeBasedTeamsTest.php`

### Background you need

Per the locked-in scope: recompute `team` for every order with `pancake_created_at >= '2026-09-05 00:00:00'`, using `TeamShiftWindow::forHour()` against each order's own already-stored `pancake_created_at` hour. Orders before that date are never touched. This is a one-time manual command (not scheduled), run once after this plan's code is deployed.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BackfillTimeBasedTeamsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** One-time backfill (2026-09-07): recomputes team for orders from
 *  2026-09-05 onward using the new time-based rule. Orders before that
 *  date keep whatever team they already have from the old "who handled
 *  it" rule — never touched. */
class BackfillTimeBasedTeamsTest extends TestCase
{
    use RefreshDatabase;

    public function test_recomputes_team_for_an_order_on_or_after_sep_5(): void
    {
        $order = Order::factory()->create([
            'pancake_order_id'   => 'in-scope-1',
            'team'               => 'SH Naturals', // wrong under the old rule for a 10am order
            'pancake_created_at' => '2026-09-05 10:00:00',
        ]);

        $this->artisan('orders:backfill-time-based-teams')->assertSuccessful();

        $this->assertSame('Eyecare Team', $order->fresh()->team);
    }

    public function test_leaves_an_order_before_sep_5_untouched(): void
    {
        $order = Order::factory()->create([
            'pancake_order_id'   => 'out-of-scope-1',
            'team'               => 'SH Naturals', // would be "Eyecare Team" under the new rule, but this order predates the backfill's scope
            'pancake_created_at' => '2026-09-04 10:00:00',
        ]);

        $this->artisan('orders:backfill-time-based-teams')->assertSuccessful();

        $this->assertSame('SH Naturals', $order->fresh()->team, 'orders before 2026-09-05 must never be touched by this backfill');
    }

    public function test_dry_run_reports_without_writing_anything(): void
    {
        $order = Order::factory()->create([
            'pancake_order_id'   => 'in-scope-2',
            'team'               => 'SH Naturals',
            'pancake_created_at' => '2026-09-05 10:00:00',
        ]);

        $this->artisan('orders:backfill-time-based-teams --dry-run')->assertSuccessful();

        $this->assertSame('SH Naturals', $order->fresh()->team, 'dry-run must not write anything');
    }

    public function test_an_order_with_no_pancake_created_at_is_skipped_not_errored(): void
    {
        $order = Order::factory()->create([
            'pancake_order_id'   => 'null-date-1',
            'team'               => null,
            'pancake_created_at' => null,
        ]);

        $this->artisan('orders:backfill-time-based-teams')->assertSuccessful();

        $this->assertNull($order->fresh()->team);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Feature/BackfillTimeBasedTeamsTest.php
```
Expected: FAIL — `orders:backfill-time-based-teams` command doesn't exist yet.

- [ ] **Step 3: Write the command**

Create `app/Console/Commands/BackfillTimeBasedTeams.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Support\TeamShiftWindow;
use Illuminate\Console\Command;

/**
 * One-time command (2026-09-07) — recomputes `team` for every order from
 * 2026-09-05 onward using the new time-based rule (TeamShiftWindow),
 * since SyncTodayOrders.php only applies the new rule to NEWLY synced
 * orders going forward; an order already stored under the old "who
 * handled it" rule needs this explicit backfill to match. Orders before
 * 2026-09-05 are deliberately never touched — explicit scope decision,
 * not a bug: only orders from the date the team-transition actually
 * happened get recomputed, older history keeps its original attribution.
 *
 * Safe to re-run (idempotent) — recomputing the same order twice always
 * produces the same team from the same stored pancake_created_at.
 */
class BackfillTimeBasedTeams extends Command
{
    protected $signature   = 'orders:backfill-time-based-teams {--dry-run : Report what would change without writing}';
    protected $description = 'One-time backfill: recompute team from pancake_created_at hour for orders from 2026-09-05 onward';

    private const SCOPE_START = '2026-09-05 00:00:00';

    public function handle(): int
    {
        $dryRun  = (bool) $this->option('dry-run');
        $updated = 0;
        $byTeam  = [];

        Order::where('pancake_created_at', '>=', self::SCOPE_START)
            ->whereNotNull('pancake_created_at')
            ->chunkById(500, function ($orders) use ($dryRun, &$updated, &$byTeam) {
                foreach ($orders as $order) {
                    $team = TeamShiftWindow::forHour((int) $order->pancake_created_at->format('G'));

                    if ($order->team === $team) continue;

                    if (!$dryRun) {
                        $order->update(['team' => $team]);
                    }
                    $updated++;
                    $byTeam[$team] = ($byTeam[$team] ?? 0) + 1;
                }
            });

        $verb = $dryRun ? 'would update' : 'updated';
        $this->info("Backfill complete: {$verb} {$updated} order(s) from " . self::SCOPE_START . ' onward.');
        foreach ($byTeam as $team => $count) {
            $this->line("  {$team}: {$count}");
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php artisan test tests/Feature/BackfillTimeBasedTeamsTest.php
```
Expected: PASS, all 4 tests.

- [ ] **Step 5: Run the full test suite**

```bash
php artisan test
```
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/BackfillTimeBasedTeams.php tests/Feature/BackfillTimeBasedTeamsTest.php
git commit -m "Add one-time backfill for time-based team on orders since Sep 5

Recomputes team from each order's own stored pancake_created_at hour
for orders from 2026-09-05 onward only - older history keeps its
original who-handled-it attribution untouched. Run once against
production after this deploys: php artisan orders:backfill-time-based-teams"
```

**Note for whoever executes this plan:** after this task's commit is deployed to production, someone must manually run `php artisan orders:backfill-time-based-teams` once against the production database (via `railway ssh -- php artisan orders:backfill-time-based-teams`, following the same connection steps used earlier this session, or from a Railway console). This is NOT automatic — the command existing in the codebase does nothing until it's actually invoked once.

---

## Task 7: Show every active product on Leads Report's per-team tables

**Files:**
- Modify: `app/Http/Controllers/LeadsReportController.php`
- Modify: `app/Http/Controllers/TsaPerformanceController.php` (small follow-up: add the same `is_hidden` filter to yesterday's `showTsa()` fix)

### Background you need

This task is independent of Tasks 1-6 (it's a display-scope fix, not a `team` attribution change) but was requested in the same conversation. `LeadsReportController` builds a `$teamTables` structure (used by `resources/views/leads-report.blade.php`'s TEAM OPENING/TEAM CLOSING tabs) where each team's table only lists that team's own products (`Product::where('team', $orderTeam)`, confirmed at `LeadsReportController.php:194` during investigation). Per this session's now-established pattern (yesterday's identical fix to `TsaPerformanceController::showTsa()`), every product should appear on both tabs, since any TSA on either team can now sell any product — a cross-team sale currently has no row to appear in on this page either.

Additionally: yesterday's `TsaPerformanceController::showTsa()` fix (`$products = Product::orderBy('sort_order')->get();`) has no `is_hidden` filter — per this session's confirmed decision, both this task's Leads Report fix AND that existing fix must exclude hidden products.

- [ ] **Step 1: Read the current LeadsReportController code around the product-team query**

```bash
grep -n "Product::where\|teamTables\|\\\$orderTeam\b" app/Http/Controllers/LeadsReportController.php
```

Read the surrounding ~30 lines of context around each hit before making changes — the investigation found multiple related lines (194, 206, 226, 477, 534, 586) and this step must confirm their exact current state and relationships before editing, since file line numbers may have shifted since the investigation was performed. Do not blindly trust the line numbers below without first re-confirming them against the actual current file.

- [ ] **Step 2: Write the failing test**

First check for an existing test file:
```bash
find tests -iname "*LeadsReport*"
```

Read whatever is found in full. Then add a new test (in the most relevant existing file, or create `tests/Feature/LeadsReportCrossTeamProductVisibilityTest.php` if none of the existing files are a good fit) with this shape — adjust route name/params to match what you find in the existing test file's own conventions:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Bug fix (2026-09-07): Leads Report's per-team product tables only
 *  listed each team's own products, so a cross-team sale (any TSA can
 *  now sell any product) had no row to appear in — same root cause as
 *  TsaPerformanceController::showTsa()'s 2026-09-06 fix. Every active,
 *  non-hidden product now appears on both TEAM OPENING and TEAM CLOSING
 *  tables. */
class LeadsReportCrossTeamProductVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_an_eyecare_product_appears_on_the_sh_naturals_team_table(): void
    {
        $eyecareProduct = Product::where('team', 'Eyecare Team')->where('display_name', 'PTERYGIUM')->first();

        $response = $this->get(route('leads-report', ['team' => 'sh-naturals']));

        $response->assertOk();
        $response->assertSee($eyecareProduct->display_name);
    }

    public function test_a_hidden_product_does_not_appear_on_either_team_table(): void
    {
        $product = Product::where('team', 'SH Naturals')->first();
        $product->update(['is_hidden' => true]);

        $response = $this->get(route('leads-report', ['team' => 'sh-naturals']));

        $response->assertOk();
        $response->assertDontSee($product->display_name);
    }
}
```

**Before finalizing this test**, run:
```bash
grep -n "Route::get.*leads-report" routes/web.php
```
to confirm the exact route name and whether `team` is a query param or route-model-bound path segment — adjust the `route()` call to match.

- [ ] **Step 3: Run test to verify it fails**

```bash
php artisan test tests/Feature/LeadsReportCrossTeamProductVisibilityTest.php
```
Expected: FAIL on the first test (PTERYGIUM, an Eyecare product, doesn't appear on the SH Naturals table today).

- [ ] **Step 4: Fix the product query**

Using the line numbers confirmed in Step 1, find the line matching:
```php
        $products = Product::where('team', $orderTeam)->orderBy('sort_order')->get();
```
(or whatever the exact current variable/condition is — re-confirm against Step 1's findings, the investigation's line 194 reference may not be exact after any drift).

Replace the team-filtering condition with an `is_hidden` filter instead, e.g.:
```php
        // Every active product, not just this team's own (bug fix,
        // 2026-09-07 — every TSA now handles every product, so a
        // cross-team sale had no row to appear in on this team's own
        // table; same root cause as TsaPerformanceController::showTsa()'s
        // 2026-09-06 fix). Hidden products are still excluded, same as
        // every other product-listing query in this app.
        $products = Product::where('is_hidden', false)->orderBy('sort_order')->get();
```

If the surrounding code uses this `$products` variable for anything that genuinely still needs to stay team-scoped (e.g. a filter dropdown elsewhere on the same page, distinct from the per-team table), do NOT blindly replace every occurrence — re-read each of the other flagged lines (206, 226, 477, 534, 586 per the investigation, re-confirmed in Step 1) individually and judge whether that specific usage is "which products show as columns/rows in this team's table" (fix it) versus something else entirely (leave it). This step requires actual judgment against the real code, not a mechanical find-and-replace across the whole file.

- [ ] **Step 5: Run the test to verify it passes**

```bash
php artisan test tests/Feature/LeadsReportCrossTeamProductVisibilityTest.php
```
Expected: PASS, both tests.

- [ ] **Step 6: Fix the same is_hidden gap in TsaPerformanceController::showTsa()**

In `app/Http/Controllers/TsaPerformanceController.php`, find:
```php
        $products = Product::orderBy('sort_order')->get();
```

Replace with:
```php
        $products = Product::where('is_hidden', false)->orderBy('sort_order')->get();
```

- [ ] **Step 7: Write a regression test for the hidden-product gap in showTsa()**

In `tests/Feature/TsaPerformanceCrossTeamUpsellVisibilityTest.php` (created yesterday), add:

```php
    public function test_a_hidden_product_does_not_get_a_column_on_the_tsas_individual_page(): void
    {
        $hidden = Product::where('team', 'SH Naturals')->first();
        $hidden->update(['is_hidden' => true]);

        $team = collect(config('teams'))->search(fn ($t) => $t['order_team'] === 'SH Naturals');
        $joana = \App\Models\TsaShift::where('tsa_key', 'Joana')->first();
        $joana->update(['team' => 'SH Naturals']);

        $response = $this->get(route('tsa-performance.individual', [
            'team' => $team, 'tsaKey' => 'Joana',
            'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertViewHas('products', fn ($products) => !$products->contains('id', $hidden->id));
    }
```

- [ ] **Step 8: Run both test files**

```bash
php artisan test tests/Feature/LeadsReportCrossTeamProductVisibilityTest.php tests/Feature/TsaPerformanceCrossTeamUpsellVisibilityTest.php
```
Expected: all pass.

- [ ] **Step 9: Run the full test suite**

```bash
php artisan test
```
Expected: all pass, no regressions on any other Leads Report or TSA Performance test.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/LeadsReportController.php app/Http/Controllers/TsaPerformanceController.php tests/Feature/LeadsReportCrossTeamProductVisibilityTest.php tests/Feature/TsaPerformanceCrossTeamUpsellVisibilityTest.php
git commit -m "Show every active product on Leads Report's per-team tables

Same fix as TsaPerformanceController::showTsa()'s 2026-09-06 change —
every TSA now handles every product, so a cross-team sale had no row
to appear in on either team's own table. Also closes a gap in
yesterday's showTsa() fix: hidden products are now excluded from its
product grid, matching every other product-listing query in the app."
```

---

## Self-Review Notes (for whoever executes this plan)

- **Task order matters for Tasks 1-6** (each depends on the previous existing) but **Task 7 is independent** and can be done first, last, or in parallel by someone else — it touches entirely different files (`LeadsReportController`, not `SyncTodayOrders`/`TeamShiftWindow`/backfill).
- **The backfill command (Task 6) does nothing until manually run once against production** — this is explicitly called out in that task's own commit message note. Do not consider this plan "done" until that command has actually been executed against the live database, not just merged.
- **Read-side files need zero code changes** — `LeadsReportController`, `DashboardController`, `ChartsController`, `ProductPerformance`, `TsaPerformanceController` (aside from Task 7's narrow `is_hidden` fix), `RtsReportController`, `SearchController` — they already just read whichever of the two literal team strings (`"SH Naturals"`/`"Eyecare Team"`) is stored on an order, with no code assuming HOW that value was computed. Do not add unnecessary changes to these files under this plan.
- **`ProductPerformance::matchingOrders()`'s team-gate** (`$o->team !== $product->team`) is UNCHANGED by this plan — it still compares an order's team against a PRODUCT's own team column (a completely different, still-team-per-product concept that this plan does not touch). This gate now compares a time-based order value against a product's still-fixed team value, which may produce different match results than before for some edge cases — this was explicitly out of scope per this session's decision to keep the investigation narrow; if a mismatch surfaces after deploying this plan, it is a SEPARATE follow-up issue, not a bug in this plan's own implementation.
- **`TsaShift.team`** (which team a TSA is administratively rostered under — Kathleen is "SH Naturals," full stop) is completely untouched by every task in this plan. Do not add any task that changes `TsaShift.team`'s meaning or how it's assigned — that was explicitly confirmed out of scope.
- **Type/name consistency check performed**: `TeamShiftWindow::forHour()` is the exact same method name/signature used in Task 1 (definition), Task 2 (`SyncTodayOrders.php` call site), and Task 6 (`BackfillTimeBasedTeams.php` call site) — verified consistent across all three tasks.
