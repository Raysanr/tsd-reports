# Reconciliation Completeness Check Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix a false-positive bug in `pancake:reconcile`'s completeness check — it compares Pancake's `updated_at`-windowed order count against a local count keyed to `pancake_created_at` (a business-adjusted "worked at" timestamp, not raw Pancake data), producing misleading "sync may have missed a window" alerts on healthy days.

**Architecture:** Add `orders.pancake_updated_at` — a new column storing Pancake's raw `updated_at` untouched, populated from a value `SyncTodayOrders` already computes but currently never persists. Point the completeness check at this new column instead of `pancake_created_at`. Every existing report keeps reading `pancake_created_at` exactly as before; this is a purely additive fix.

**Tech Stack:** Laravel 12, PHPUnit via `php artisan test`, `Illuminate\Support\Facades\Http` fakes (existing project convention).

Full design context: `docs/superpowers/specs/2026-07-14-completeness-check-fix-design.md`.

---

## File Structure

- **Create:** `database/migrations/2026_07_14_180000_add_pancake_updated_at_to_orders_table.php`
- **Modify:** `app/Models/Order.php` — add `pancake_updated_at` to `$fillable`/`$casts`
- **Modify:** `database/factories/OrderFactory.php` — add `pancake_updated_at` to `definition()`
- **Modify:** `app/Console/Commands/SyncTodayOrders.php` — persist `pancake_updated_at` during sync
- **Modify:** `app/Console/Commands/PancakeReconcile.php` — read `pancake_updated_at` instead of `pancake_created_at`
- **Modify:** `tests/Feature/PancakeReconcileTest.php` — update 2 existing tests, add 1 regression test
- **Create:** `tests/Feature/SyncTodayOrdersPancakeUpdatedAtTest.php`

---

## Task 1: Add `pancake_updated_at` column and model support

**Files:**
- Create: `database/migrations/2026_07_14_180000_add_pancake_updated_at_to_orders_table.php`
- Modify: `app/Models/Order.php`
- Modify: `database/factories/OrderFactory.php`

- [ ] **Step 1: Confirm the starting state**

This task is schema/model plumbing with no behavior of its own to write a PHPUnit test against yet — the real behavioral tests come in Tasks 2 and 3, which depend on this column existing. Confirm the column doesn't exist yet instead, so the next step's effect is verifiable:

Run: `php artisan tinker --execute="echo Schema::hasColumn('orders', 'pancake_updated_at') ? 'yes' : 'no';"`
Expected: `no`

- [ ] **Step 2: Create the migration**

Create `database/migrations/2026_07_14_180000_add_pancake_updated_at_to_orders_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Pancake's raw `updated_at`, stored untouched — unlike pancake_created_at
            // (which is business-adjusted to "when a TSA actually worked this," see
            // SyncTodayOrders::resolveWorkedAt()), this column exists specifically so
            // pancake:reconcile's completeness check can compare against Pancake's own
            // updated_at-windowed counts without a semantic mismatch. No other report
            // in this app should read this column — they all intentionally use
            // pancake_created_at instead.
            $table->timestamp('pancake_updated_at')->nullable()->after('pancake_created_at');
            $table->index('pancake_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('pancake_updated_at');
        });
    }
};
```

- [ ] **Step 3: Run migrations and verify the column exists**

Run: `php artisan migrate` (in your local dev environment against your dev database — note this plan's execution environment likely uses a fresh test database per-run via `RefreshDatabase`, so this manual step is for your own local sanity-check, not a test-suite step)
Run: `php artisan tinker --execute="echo Schema::hasColumn('orders', 'pancake_updated_at') ? 'yes' : 'no';"`
Expected: `yes`

- [ ] **Step 4: Add `pancake_updated_at` to the `Order` model**

In `app/Models/Order.php`, add `'pancake_updated_at',` to the `$fillable` array immediately after `'pancake_created_at',` (currently line 28):

```php
    protected $fillable = [
        'pancake_order_id',
        'team',
        'tsa_name',
        'disposition',
        'product',
        'amount',
        'raw_tags',
        'is_upsell',
        'is_cancelled_upsell',
        'cancelled_upsell_amount',
        'is_returned_upsell',
        'returned_upsell_amount',
        'status_code',
        'pancake_created_at',
        'pancake_updated_at',
        'synced_at',
    ];
```

Add `'pancake_updated_at' => 'datetime',` to the `$casts` array immediately after `'pancake_created_at' => 'datetime',`:

```php
    protected $casts = [
        'raw_tags'                => 'array',
        'is_upsell'               => 'boolean',
        'is_cancelled_upsell'     => 'boolean',
        'is_returned_upsell'      => 'boolean',
        'amount'                  => 'decimal:2',
        'cancelled_upsell_amount' => 'decimal:2',
        'returned_upsell_amount'  => 'decimal:2',
        'pancake_created_at'      => 'datetime',
        'pancake_updated_at'      => 'datetime',
```

(Read the actual current file first — there are more lines after this in `$casts` that aren't shown here; only add the one new line, don't remove or reorder anything else.)

- [ ] **Step 5: Add `pancake_updated_at` to `OrderFactory`**

In `database/factories/OrderFactory.php`, add `'pancake_updated_at' => now(),` immediately after `'pancake_created_at' => now(),`:

```php
    public function definition(): array
    {
        return [
            'pancake_order_id'   => (string) $this->faker->unique()->randomNumber(8),
            'team'                => null,
            'tsa_name'            => null,
            'disposition'         => null,
            'product'             => null,
            'amount'              => 0,
            'raw_tags'            => [],
            'is_upsell'           => false,
            'is_cancelled_upsell' => false,
            'is_returned_upsell'  => false,
            'status_code'         => 3,
            'pancake_created_at'  => now(),
            'pancake_updated_at'  => now(),
            'synced_at'           => now(),
        ];
    }
```

- [ ] **Step 6: Run the full test suite to check for regressions**

Run: `php artisan test`
Expected: **PASS**, no regressions (this task is purely additive — no existing test reads or writes `pancake_updated_at`, so nothing should change).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_14_180000_add_pancake_updated_at_to_orders_table.php app/Models/Order.php database/factories/OrderFactory.php
git commit -m "feat: add orders.pancake_updated_at column

Stores Pancake's raw updated_at untouched, alongside the existing
business-adjusted pancake_created_at. Not yet populated by sync or
read by anything — the next two tasks wire it up. Exists specifically
so pancake:reconcile's completeness check can compare against
Pancake's own updated_at-windowed counts without a semantic mismatch."
```

---

## Task 2: Populate `pancake_updated_at` during sync

**Files:**
- Modify: `app/Console/Commands/SyncTodayOrders.php`
- Test: `tests/Feature/SyncTodayOrdersPancakeUpdatedAtTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SyncTodayOrdersPancakeUpdatedAtTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncTodayOrdersPancakeUpdatedAtTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Deliberately constructs an order with NO tag-history match (so
     * pancake_created_at/workedAt falls back to insertion time — 2026-07-10) and a
     * raw `updated_at` on a completely different day (2026-07-14), so the two
     * columns can only match this test's assertions if pancake_updated_at is
     * genuinely storing the raw, unadjusted Pancake value rather than accidentally
     * mirroring pancake_created_at.
     */
    public function test_pancake_updated_at_stores_the_raw_value_independent_of_pancake_created_at(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::sequence()
                ->push([
                    'data' => [[
                        'id' => 1400001,
                        'status' => 3,
                        'inserted_at' => '2026-07-10T01:00:00.000000',
                        'updated_at' => '2026-07-14T05:30:00.000000',
                        'tags' => [],
                        'items' => [],
                        'histories' => [],
                    ]],
                ])
                ->push(['data' => []]),
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-07-14']);

        $order = Order::where('pancake_order_id', '1400001')->first();

        $this->assertNotNull($order, 'Order should have been synced');
        $this->assertSame('2026-07-10', $order->pancake_created_at->setTimezone('Asia/Manila')->toDateString());
        $this->assertSame('2026-07-14', $order->pancake_updated_at->setTimezone('Asia/Manila')->toDateString());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=SyncTodayOrdersPancakeUpdatedAtTest`
Expected: **FAIL** — `$order->pancake_updated_at` is `null` (column exists from Task 1 but nothing populates it yet), so `->setTimezone(...)` on `null` throws an error.

- [ ] **Step 3: Persist `pancake_updated_at` in `flushOrders()`**

In `app/Console/Commands/SyncTodayOrders.php`, find the `$parsed[] = [...]` array (currently lines 314-334) and add one new key immediately after `'pancake_created_at' => $workedAt?->toDateTimeString(),`:

```php
            $parsed[] = [
                'pancake_order_id'        => (string)$raw['id'],
                'team'                    => $tsaInfo['team'],
                'tsa_name'                => $tsaInfo['name'],
                'disposition'             => $disposition,
                'product'                 => $productName,
                'amount'                  => $amount,
                'raw_tags'                => $tagNames,
                'is_upsell'               => $isUpsell,
                'is_cancelled_upsell'     => $isCancelledUpsell,
                // null = never captured (this sync never saw the order as a live
                // upsell before Pancake's data showed it cancelled) — distinct from a
                // confirmed ₱0. See the carry-forward logic below, which is the only
                // place this ever gets a real value.
                'cancelled_upsell_amount' => null,
                'is_returned_upsell'      => $isReturnedUpsell,
                'returned_upsell_amount'  => $returnedUpsellAmount,
                'status_code'             => $statusCode,
                'pancake_created_at'      => $workedAt?->toDateTimeString(),
                'pancake_updated_at'      => $updatedPHT?->toDateTimeString(),
                'synced_at'               => now()->toDateTimeString(),
            ];
```

(`$updatedPHT` is already computed earlier in this same method — at the top of the loop, around line 228 — for the day-boundary pagination cutoff check. This reuses that same value; no new parsing logic.)

Then find the `Order::upsert()` call (currently lines 388-398) and add `'pancake_updated_at'` to the update-columns list, immediately after `'pancake_created_at'`:

```php
        foreach (array_chunk($rows, 200) as $chunk) {
            Order::upsert(
                $chunk,
                ['pancake_order_id'],
                [
                    'team', 'tsa_name', 'disposition', 'product', 'amount', 'raw_tags',
                    'is_upsell', 'is_cancelled_upsell', 'cancelled_upsell_amount',
                    'is_returned_upsell', 'returned_upsell_amount',
                    'status_code', 'pancake_created_at', 'pancake_updated_at', 'synced_at',
                ]
            );
        }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=SyncTodayOrdersPancakeUpdatedAtTest`
Expected: **PASS** — 1 test.

- [ ] **Step 5: Run the full test suite to check for regressions**

Run: `php artisan test`
Expected: **PASS**, no regressions. Pay particular attention to `tests/Feature/SyncTodayOrdersBacklogTest.php` and `tests/Feature/SyncTodayOrdersProductTeamInferenceTest.php` — both exercise `flushOrders()` and should be unaffected (this change only adds a column to the upsert, it doesn't change any existing field's value or any matching/attribution logic).

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/SyncTodayOrders.php tests/Feature/SyncTodayOrdersPancakeUpdatedAtTest.php
git commit -m "feat: persist pancake_updated_at during sync

Reuses \$updatedPHT, already computed in flushOrders() for the
day-boundary pagination cutoff, now also persisted as the order's raw
(unadjusted) Pancake update timestamp."
```

---

## Task 3: Fix the completeness check to compare like-for-like

**Files:**
- Modify: `app/Console/Commands/PancakeReconcile.php`
- Modify: `tests/Feature/PancakeReconcileTest.php`

- [ ] **Step 1: Update the two existing completeness tests**

In `tests/Feature/PancakeReconcileTest.php`, both `test_flags_a_day_where_pancake_reports_far_more_orders_than_are_synced_locally` and `test_does_not_flag_a_day_where_local_count_is_close_to_pancakes` currently create orders with only `pancake_created_at` set. Once Task 3's Step 3 changes the completeness check to read `pancake_updated_at` instead, these factory-created orders (which get `pancake_updated_at = now()` from Task 1's factory default — today, not "yesterday") would no longer fall inside the checked window, breaking both tests. Fix this now, before making the production change, so the tests fail for the right reason in Step 2.

Change the first test's factory call (currently lines 49-51) from:

```php
        Order::factory()->count(2)->create([
            'pancake_created_at' => $yesterday->copy()->setTime(10, 0),
        ]);
```

to:

```php
        Order::factory()->count(2)->create([
            'pancake_created_at' => $yesterday->copy()->setTime(10, 0),
            'pancake_updated_at' => $yesterday->copy()->setTime(10, 0),
        ]);
```

Change the second test's factory call (currently lines 77-79) the same way:

```php
        Order::factory()->count(48)->create([
            'pancake_created_at' => $yesterday->copy()->setTime(10, 0),
            'pancake_updated_at' => $yesterday->copy()->setTime(10, 0),
        ]);
```

- [ ] **Step 2: Write the new regression test**

Append this test to `tests/Feature/PancakeReconcileTest.php` (inside the existing class, alongside the other completeness tests):

```php
    public function test_completeness_check_follows_pancake_updated_at_not_pancake_created_at(): void
    {
        Setting::set('pancake_api_key', 'a-working-key');
        Setting::set('shop_id', '30037101');

        $yesterday  = Carbon::now('Asia/Manila')->subDay();
        $twoDaysAgo = $yesterday->copy()->subDay();

        // A backlog-lead-like order: worked (pancake_created_at) two days ago, but
        // Pancake's own updated_at (pancake_updated_at) says it was touched
        // yesterday. Before this fix, the completeness check read
        // pancake_created_at and would NOT have counted this order toward
        // "yesterday" at all — after the fix, it must.
        Order::factory()->count(50)->create([
            'pancake_created_at' => $twoDaysAgo->copy()->setTime(10, 0),
            'pancake_updated_at' => $yesterday->copy()->setTime(10, 0),
        ]);

        $this->fakeEmptyTagCatalog();
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [], 'total_entries' => 50, 'total_pages' => 50,
            ], 200),
        ]);

        Artisan::call('pancake:reconcile');

        $issues = json_decode(Setting::get('reconciliation_issues'), true);
        $this->assertSame([], $issues, 'Orders whose pancake_updated_at falls yesterday should count toward yesterday, even if pancake_created_at does not');
    }
```

- [ ] **Step 3: Run the tests to verify they fail for the right reason**

Run: `php artisan test --filter=PancakeReconcileTest`
Expected: the two updated tests **PASS** (they set both columns, and the completeness check still reads `pancake_created_at` at this point, so nothing's actually broken yet — Step 1 was just future-proofing). The new `test_completeness_check_follows_pancake_updated_at_not_pancake_created_at` **FAILS**: the 50 orders' `pancake_created_at` falls two days ago, not yesterday, so with the current (unfixed) `pancake_created_at`-based check, the local count for yesterday is 0, and the check flags a false completeness issue.

- [ ] **Step 4: Fix `checkCompleteness()`**

In `app/Console/Commands/PancakeReconcile.php`, replace the `checkCompleteness()` method's doc comment and body:

```php
    /**
     * Compares Pancake's own order count for yesterday (Asia/Manila) against how
     * many local rows have a pancake_updated_at in that same window — pancake_updated_at
     * stores Pancake's raw update timestamp untouched (see the orders migration's
     * comment), so this is a genuine apples-to-apples comparison against Pancake's
     * updateStatus=updated_at count. Deliberately does NOT use pancake_created_at,
     * which is business-adjusted to "when a TSA actually worked this" (see
     * SyncTodayOrders::resolveWorkedAt()) and can land on a different calendar day
     * than the same order's raw updated_at — comparing against that field was this
     * check's original bug (confirmed empirically: for 2026-07-13, Pancake reported
     * 494 orders touched by updated_at, but only 366 local rows had a matching
     * pancake_created_at — pancake_created_at tracked close to Pancake's own
     * inserted_at count of 364 instead, an entirely different, unrelated number).
     */
    private function checkCompleteness(string $apiKey, string $shopId): array
    {
        $date         = Carbon::now('Asia/Manila')->subDay();
        $startOfDayTs = $date->copy()->startOfDay()->timestamp;
        $endOfDayTs   = $date->copy()->endOfDay()->timestamp;

        $response = Http::withHeaders(['Accept' => 'application/json'])->timeout(30)->get(
            "https://pos.pages.fm/api/v1/shops/{$shopId}/orders",
            [
                'api_key'       => $apiKey,
                'page_size'     => 1,
                'page_number'   => 1,
                'updateStatus'  => 'updated_at',
                'startDateTime' => $startOfDayTs,
                'endDateTime'   => $endOfDayTs,
            ]
        );

        if (!$response->successful()) {
            return ["Completeness check for {$date->toDateString()} failed: API error " . $response->status()];
        }

        $pancakeCount = (int) ($response->json()['total_entries'] ?? 0);
        if ($pancakeCount === 0) {
            return [];
        }

        $localCount = Order::whereBetween('pancake_updated_at', [
            $date->copy()->startOfDay(), $date->copy()->endOfDay(),
        ])->count();

        if ($localCount < $pancakeCount * self::COMPLETENESS_THRESHOLD) {
            return ["Completeness: Pancake reports {$pancakeCount} orders touched on {$date->toDateString()}, but only {$localCount} are synced locally — sync may have missed a window that day."];
        }

        return [];
    }
```

Also update the `COMPLETENESS_THRESHOLD` constant's comment (currently directly above the constant declaration near the top of the class):

```php
    // Below this fraction of Pancake's reported order count for the day, the day is
    // flagged as incomplete. Not 100%: a small number of orders touched right at the
    // day boundary may not have synced yet by the time this check runs (delta syncs
    // run every 1-15 minutes, not instantly) — expected, not a sync gap. A large
    // shortfall is not expected.
    private const COMPLETENESS_THRESHOLD = 0.9;
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=PancakeReconcileTest`
Expected: **PASS** — all tests in the file, including the new regression test.

- [ ] **Step 6: Run the full test suite to check for regressions**

Run: `php artisan test`
Expected: **PASS**, no regressions.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/PancakeReconcile.php tests/Feature/PancakeReconcileTest.php
git commit -m "fix: compare completeness against pancake_updated_at, not pancake_created_at

pancake_created_at is business-adjusted (when a TSA worked the order,
per SyncTodayOrders::resolveWorkedAt()), not Pancake's raw update
timestamp — comparing it against Pancake's own updated_at-windowed
count produced false 'sync may have missed a window' alerts on
healthy days. Confirmed against live data for 2026-07-13: Pancake
reported 494 orders touched by updated_at; local pancake_created_at
matched only 366, tracking closer to Pancake's unrelated inserted_at
count (364) instead. pancake_updated_at (added in a prior commit)
stores the raw value untouched, making this comparison correct."
```

---

## Self-Review

**Spec coverage:** Every section of `docs/superpowers/specs/2026-07-14-completeness-check-fix-design.md` maps to a task — the new column → Task 1, populating it during sync → Task 2, fixing the check itself (plus both comment corrections called out in the spec) → Task 3. The spec's "Out of Scope" items are respected: no backfill task exists, `pancake_created_at`'s own meaning/consumers are untouched by every task, `checkTagDrift()` is never modified, and `COMPLETENESS_THRESHOLD`'s numeric value stays `0.9` (only its comment changes, per Task 3 Step 4).

**Placeholder scan:** No TBD/TODO. Every step has complete code and exact `php artisan test --filter=...` / `php artisan tinker --execute=...` commands with stated expected outcomes.

**Type consistency:** `pancake_updated_at` is a `timestamp` column (Task 1) cast to `datetime` on the model (Task 1), populated as `$updatedPHT?->toDateTimeString()` (Task 2, same `?->toDateTimeString()` pattern already used for `pancake_created_at` two lines above it — no drift in how nullable Carbon values get serialized for the upsert), and read via `whereBetween('pancake_updated_at', [Carbon, Carbon])` (Task 3) — matching exactly how `pancake_created_at` was queried before this fix, just swapping the column name. `OrderFactory`'s `pancake_updated_at` default (`now()`, Task 1) is deliberately overridden with an explicit value in every test that needs a specific date (Task 3's Step 1 and Step 2), consistent with how `pancake_created_at` was already being overridden in those same tests.
