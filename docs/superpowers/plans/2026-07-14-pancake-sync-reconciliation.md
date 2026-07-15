# Pancake Sync Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automatically detect two failure modes that unit tests can't catch and that currently only surface if someone manually investigates: (1) a sync window silently going missing (like the 2026-07-13 API-key outage, which ran undetected for ~19 hours), and (2) a configured TSA tag keyword drifting out of sync with what Pancake actually calls that tag (typo, rename, etc.).

**Architecture:** A new scheduled command, `pancake:reconcile`, runs two independent checks against Pancake's live API and writes results to the existing `Setting` key/value store — no new tables. `DashboardController` reads those settings the same way it already reads `last_synced`/`sync_interval` for the existing `sync_stale` indicator, and the dashboard view renders a banner in the same style as its existing "DB SCHEMA ERROR" / "API NOT CONNECTED" banners when issues are present.

**Explicitly out of scope for this plan** (do not implement — noted here so a future plan doesn't have to rediscover why):
- **Revenue/order-count reconciliation against `GET /shops/{SHOP_ID}/analytics/sale`.** This app deliberately diverges from Pancake's raw numbers: `pancake_created_at` is backdated to when a TSA's tag was actually added (`SyncTodayOrders::resolveWorkedAt()`), not Pancake's raw `inserted_at`/`updated_at`, and `amount` on an upsell order is only the add-on's price, not the order's `total_price`. Comparing against Pancake's own dashboard-style aggregates would flag a mismatch on every single day, none of them real bugs. The completeness check in this plan instead reuses the *same query sync itself makes* (`GET /orders` with `updateStatus=updated_at` + the day's timestamps), so it's comparing like with like.
- **Product match-keyword drift** (e.g. is "CLEARSIGHT" still a real product name in Pancake). `GET /shops/{SHOP_ID}/orders/tags` — the only tag catalog endpoint confirmed in Pancake's OpenAPI spec — lists order *tags*, not product/variation names. Product keywords are primarily matched against cart item names (`items[].variation_info.name`), a different namespace covered by a different endpoint (`GET /shops/{SHOP_ID}/products/variations`, not yet investigated). Checking product keywords against the tag catalog would produce false "drift" warnings for every legitimate product that's simply never used as an order tag. This plan checks TSA tag keywords only, where the tag catalog is unambiguously the right reference.
- **Seller-keyword drift** against `GET /shops/{SHOP_ID}/users` (employee list) — same reasoning: a real, buildable check, just a distinct one from what's scoped here.
- **Alerting via email/Slack.** `MAIL_MAILER=log` (a no-op) and the Slack notification config in `config/services.php` has no token/channel set in `.env` — neither channel actually delivers anything today. Per user decision, this plan surfaces issues via `Log::warning` + a dashboard banner only.

**Tech Stack:** Laravel 12 Console Command + Scheduler, `Illuminate\Support\Facades\Http` (faked in tests per existing project convention), PHPUnit via `php artisan test`.

---

## File Structure

- **Create:** `app/Console/Commands/PancakeReconcile.php` — the `pancake:reconcile` command; both checks live here as private methods (small enough to stay in one file — no separate service class needed for ~2 checks against 1 API).
- **Modify:** `routes/console.php` — schedule the new command.
- **Modify:** `app/Http/Controllers/DashboardController.php:18-32` (read the reconciliation settings), `:226-231` (pass to view).
- **Modify:** `resources/views/dashboard.blade.php:19-30` (add the banner, next to the existing "API NOT CONNECTED" one).
- **Test:** `tests/Feature/PancakeReconcileTest.php` (new)
- **Test:** `tests/Feature/DashboardReconciliationBannerTest.php` (new)

No migration needed — `reconciliation_last_run` and `reconciliation_issues` are just new keys in the existing `settings` table (`key`/`value`, both `string`/`text`, no schema change).

---

## Task 1: `pancake:reconcile` — completeness check

**Files:**
- Create: `app/Console/Commands/PancakeReconcile.php`
- Test: `tests/Feature/PancakeReconcileTest.php` (new file)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PancakeReconcileTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PancakeReconcileTest extends TestCase
{
    use RefreshDatabase;

    private function fakeEmptyTagCatalog(): void
    {
        // Tag-drift check isn't under test here — return every configured TSA
        // keyword as a real tag so it never contributes an issue in these tests.
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders/tags' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'GEMMA'],
                    ['id' => 2, 'name' => 'MARIEL'],
                    ['id' => 3, 'name' => 'KATH'],
                    ['id' => 4, 'name' => 'JULIE'],
                    ['id' => 5, 'name' => 'JOANA'],
                    ['id' => 6, 'name' => 'MARISOL'],
                ],
            ], 200),
        ]);
    }

    public function test_flags_a_day_where_pancake_reports_far_more_orders_than_are_synced_locally(): void
    {
        Setting::set('pancake_api_key', 'a-working-key');
        Setting::set('shop_id', '30037101');

        $yesterday = Carbon::now('Asia/Manila')->subDay();

        // Only 2 orders synced locally for yesterday...
        Order::factory()->count(2)->create([
            'pancake_created_at' => $yesterday->copy()->setTime(10, 0),
        ]);

        $this->fakeEmptyTagCatalog();
        Http::fake([
            // ...but Pancake reports 50 for the same window — the outage scenario.
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [], 'total_entries' => 50, 'total_pages' => 50,
            ], 200),
        ]);

        Artisan::call('pancake:reconcile');

        $issues = json_decode(Setting::get('reconciliation_issues'), true);
        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('Completeness', $issues[0]);
        $this->assertStringContainsString((string) $yesterday->toDateString(), $issues[0]);
        $this->assertNotEmpty(Setting::get('reconciliation_last_run'));
    }

    public function test_does_not_flag_a_day_where_local_count_is_close_to_pancakes(): void
    {
        Setting::set('pancake_api_key', 'a-working-key');
        Setting::set('shop_id', '30037101');

        $yesterday = Carbon::now('Asia/Manila')->subDay();

        Order::factory()->count(48)->create([
            'pancake_created_at' => $yesterday->copy()->setTime(10, 0),
        ]);

        $this->fakeEmptyTagCatalog();
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [], 'total_entries' => 50, 'total_pages' => 50,
            ], 200),
        ]);

        Artisan::call('pancake:reconcile');

        $issues = json_decode(Setting::get('reconciliation_issues'), true);
        $this->assertSame([], $issues);
    }
}
```

Note the fake registration order in both tests: the more specific `.../orders/tags` pattern is registered via a separate `Http::fake([...])` call from `fakeEmptyTagCatalog()`, called *before* the general `.../orders?*` pattern. Laravel's `Http::fake()` merges fakes and checks them in registration order, so the specific pattern must be registered first or the general `orders*`-style wildcard could shadow it. (If `Order` has no factory yet, Step 1 also needs one — see Step 1a below.)

- [ ] **Step 1a: Confirm (or add) the `Order` model factory**

Run: `test -f database/factories/OrderFactory.php && echo exists || echo missing`

If missing, create `database/factories/OrderFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

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
            'synced_at'           => now(),
        ];
    }
}
```

This matches `app/Models/Order.php`'s `$fillable` exactly (verified against the model and its four incremental migrations: `2026_07_02_131137_add_is_upsell_to_orders_table.php`, `2026_07_03_170000_add_status_code_to_orders_table.php`, `2026_07_08_180000_add_cancelled_upsell_to_orders_table.php`, `2026_07_10_010000_add_returned_upsell_to_orders_table.php`). `cancelled_upsell_amount`/`returned_upsell_amount` are omitted from the factory's `definition()` because both columns default to `0` at the DB level — no need to set them explicitly for a factory row that isn't testing upsell-cancellation logic.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=PancakeReconcileTest`
Expected: **FAIL** — `pancake:reconcile` doesn't exist yet (`Command "pancake:reconcile" is not defined`).

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/PancakeReconcile.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Setting;
use App\Models\TsaShift;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PancakeReconcile extends Command
{
    protected $signature   = 'pancake:reconcile';
    protected $description = "Cross-check yesterday's synced order count and configured TSA tag keywords against Pancake's own data";

    // Below this fraction of Pancake's reported order count for the day, the day is
    // flagged as incomplete. Not 100%: pancake_created_at is deliberately backdated
    // to when a TSA's tag was actually added (SyncTodayOrders::resolveWorkedAt()),
    // which can shift a small number of backlog orders across the day boundary —
    // expected, not a sync gap. A large shortfall is not expected.
    private const COMPLETENESS_THRESHOLD = 0.9;

    public function handle(): int
    {
        $apiKey = Setting::get('pancake_api_key', env('PANCAKE_API_KEY', ''));
        $shopId = Setting::get('shop_id', '');

        if (empty($apiKey) || empty($shopId)) {
            $this->error('API key or shop ID not configured.');
            return self::FAILURE;
        }

        $issues = array_merge(
            $this->checkCompleteness($apiKey, $shopId),
            $this->checkTagDrift($apiKey, $shopId),
        );

        foreach ($issues as $issue) {
            Log::warning('pancake:reconcile: ' . $issue);
        }

        Setting::set('reconciliation_last_run', now()->toIso8601String());
        Setting::set('reconciliation_issues', json_encode($issues));

        if (empty($issues)) {
            $this->info('No issues found.');
        } else {
            $this->warn(count($issues) . ' issue(s) found:');
            foreach ($issues as $issue) {
                $this->line('  - ' . $issue);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Compares Pancake's own order count for yesterday (Asia/Manila) against how
     * many local rows landed for that day — using the exact same updated_at-window
     * query SyncTodayOrders itself makes, so this is apples-to-apples (see the
     * "explicitly out of scope" note in the plan for why analytics/sale isn't used
     * instead).
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

        $localCount = Order::whereBetween('pancake_created_at', [
            $date->copy()->startOfDay(), $date->copy()->endOfDay(),
        ])->count();

        if ($localCount < $pancakeCount * self::COMPLETENESS_THRESHOLD) {
            return ["Completeness: Pancake reports {$pancakeCount} orders touched on {$date->toDateString()}, but only {$localCount} are synced locally — sync may have missed a window that day."];
        }

        return [];
    }

    /**
     * For every configured TSA tag keyword, checks it appears in at least one real
     * tag name from Pancake's own tag catalog. A keyword with zero matches is
     * either a typo or references a tag that was renamed/removed on the Pancake
     * side since it was configured. Product/seller-keyword drift is intentionally
     * not checked here — see the "explicitly out of scope" note in the plan.
     */
    private function checkTagDrift(string $apiKey, string $shopId): array
    {
        $response = Http::withHeaders(['Accept' => 'application/json'])->timeout(30)->get(
            "https://pos.pages.fm/api/v1/shops/{$shopId}/orders/tags",
            ['api_key' => $apiKey]
        );

        if (!$response->successful()) {
            return ['Tag-drift check failed: API error ' . $response->status()];
        }

        $realTagNames = array_map(
            fn($t) => self::normalize($t['name'] ?? ''),
            $response->json()['data'] ?? []
        );

        $issues = [];

        foreach (TsaShift::all() as $shift) {
            foreach ($shift->tag_keywords_array as $keyword) {
                $normalizedKeyword = self::normalize($keyword);
                if ($normalizedKeyword === '') {
                    continue;
                }

                $seen = false;
                foreach ($realTagNames as $tagName) {
                    if (str_contains($tagName, $normalizedKeyword)) {
                        $seen = true;
                        break;
                    }
                }

                if (!$seen) {
                    $issues[] = "Tag drift: TSA \"{$shift->tsa_key}\"'s tag keyword \"{$keyword}\" doesn't match any tag currently in Pancake — check TSA Management for a typo or a renamed tag.";
                }
            }
        }

        return $issues;
    }

    private static function normalize(string $s): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s));
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=PancakeReconcileTest`
Expected: **PASS** — 2 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/PancakeReconcile.php tests/Feature/PancakeReconcileTest.php database/factories/OrderFactory.php
git commit -m "feat: add pancake:reconcile to catch silent sync gaps

Compares Pancake's own order count for yesterday against what's
synced locally, using the same updated_at-window query sync itself
makes. This is the check that would have caught the 2026-07-13 API
key outage automatically instead of ~19 hours later."
```

---

## Task 2: `pancake:reconcile` — tag-drift regression tests

Task 1 already implements tag-drift checking in the command; this task adds the tests that were deferred there (the completeness tests stubbed it out via `fakeEmptyTagCatalog()`). Keeping this as its own task/commit means Task 1's commit is reviewable on the completeness logic alone.

**Files:**
- Test: `tests/Feature/PancakeReconcileTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/PancakeReconcileTest.php`:

```php
    public function test_flags_a_configured_tsa_keyword_that_matches_no_real_pancake_tag(): void
    {
        Setting::set('pancake_api_key', 'a-working-key');
        Setting::set('shop_id', '30037101');

        // Simulate a typo: someone changed Julie's keyword in TSA Management.
        \App\Models\TsaShift::where('tsa_key', 'Julie')->update(['tag_keywords' => 'JULEE']);

        Http::fake([
            // No completeness gap — matches "yesterday" order count exactly.
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [], 'total_entries' => 0, 'total_pages' => 0,
            ], 200),
            'pos.pages.fm/api/v1/shops/*/orders/tags' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'GEMMA'],
                    ['id' => 2, 'name' => 'MARIEL'],
                    ['id' => 3, 'name' => 'KATH'],
                    // 'JULEE' deliberately absent — the real Pancake tag is still 'JULIE'.
                    ['id' => 4, 'name' => 'JOANA'],
                    ['id' => 5, 'name' => 'MARISOL'],
                ],
            ], 200),
        ]);

        Artisan::call('pancake:reconcile');

        $issues = json_decode(Setting::get('reconciliation_issues'), true);
        $matching = array_filter($issues, fn($i) => str_contains($i, 'JULEE'));
        $this->assertNotEmpty($matching, 'Expected an issue mentioning the stale JULEE keyword');
    }

    public function test_does_not_flag_tsa_keywords_that_match_a_real_tag(): void
    {
        Setting::set('pancake_api_key', 'a-working-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [], 'total_entries' => 0, 'total_pages' => 0,
            ], 200),
            'pos.pages.fm/api/v1/shops/*/orders/tags' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'GEMMA'],
                    ['id' => 2, 'name' => 'MARIEL'],
                    ['id' => 3, 'name' => 'KATH'],
                    ['id' => 4, 'name' => 'JULIE'],
                    ['id' => 5, 'name' => 'JOANA'],
                    ['id' => 6, 'name' => 'MARISOL'],
                ],
            ], 200),
        ]);

        Artisan::call('pancake:reconcile');

        $issues = json_decode(Setting::get('reconciliation_issues'), true);
        $this->assertSame([], $issues, 'No TSA keyword should be flagged when every one matches a real tag');
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=PancakeReconcileTest`
Expected: `test_flags_a_configured_tsa_keyword_that_matches_no_real_pancake_tag` should already **PASS** (Task 1's implementation already covers this — this step is verifying that, not driving new code). `test_does_not_flag_tsa_keywords_that_match_a_real_tag` should also already **PASS**. If either fails, the tag-drift implementation from Task 1 has a bug — fix it now before proceeding (do not skip ahead with a known-broken check).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/PancakeReconcileTest.php
git commit -m "test: add regression coverage for pancake:reconcile tag-drift check"
```

---

## Task 3: Schedule the command

**Files:**
- Modify: `routes/console.php`

- [ ] **Step 1: Add the schedule entry**

In `routes/console.php`, add the import and schedule call:

```php
use App\Console\Commands\SyncTodayOrders;
use App\Console\Commands\PancakeReconcile;
use App\Models\Setting;
```

Append after the existing `Schedule::command(SyncTodayOrders::class)->everyFifteenMinutes()->withoutOverlapping();` line:

```php
// Reconciliation: checks yesterday's completeness + TSA tag-keyword drift against
// Pancake's own data. Runs hourly rather than once a day at a fixed time — both
// checks are cheap (one page_size=1 orders call, one tags call, no pagination),
// and Carbon::now('Asia/Manila')->subDay() inside the command means "yesterday" is
// always correct regardless of what timezone the server's cron actually fires in.
Schedule::command(PancakeReconcile::class)->hourly()->withoutOverlapping();
```

- [ ] **Step 2: Verify the schedule registers correctly**

Run: `php artisan schedule:list`
Expected: output includes a line for `pancake:reconcile`, scheduled hourly.

- [ ] **Step 3: Commit**

```bash
git add routes/console.php
git commit -m "chore: schedule pancake:reconcile to run hourly"
```

---

## Task 4: Dashboard banner

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php:18-32`, `:226-231`
- Modify: `resources/views/dashboard.blade.php:19-30`
- Test: `tests/Feature/DashboardReconciliationBannerTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DashboardReconciliationBannerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardReconciliationBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_no_banner_when_there_are_no_reconciliation_issues(): void
    {
        $this->actingAs(User::factory()->create());

        Setting::set('reconciliation_issues', json_encode([]));

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('Tag drift');
        $response->assertDontSee('Completeness');
    }

    public function test_dashboard_shows_a_banner_when_reconciliation_found_issues(): void
    {
        $this->actingAs(User::factory()->create());

        Setting::set('reconciliation_issues', json_encode([
            'Completeness: Pancake reports 50 orders touched on 2026-07-13, but only 2 are synced locally — sync may have missed a window that day.',
        ]));

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Completeness', false);
        $response->assertSee('sync may have missed a window', false);
    }

    public function test_dashboard_shows_no_banner_when_the_setting_was_never_written(): void
    {
        $this->actingAs(User::factory()->create());

        // pancake:reconcile has never run — Setting::get() returns null, not '[]'.

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('Tag drift');
        $response->assertDontSee('Completeness:');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=DashboardReconciliationBannerTest`
Expected: `test_dashboard_shows_a_banner_when_reconciliation_found_issues` **FAILS** (banner doesn't exist yet — `assertSee` fails to find the text). The other two should already pass (there's nothing to show, and nothing does).

- [ ] **Step 3: Read the reconciliation setting in `DashboardController`**

In `app/Http/Controllers/DashboardController.php`, add this alongside the existing `$apiConnected`/`$dbError` declarations (after line 32, `$hasSyncedData = false;`):

```php
        $reconciliationIssues = json_decode(Setting::get('reconciliation_issues', '[]'), true) ?: [];
```

Add `'reconciliationIssues'` to the `compact(...)` call at the `return view('dashboard', ...)` line:

```php
        return view('dashboard', compact(
            'stats', 'recentOrders', 'apiConnected', 'dbError',
            'dateFrom', 'dateTo', 'hasSyncedData', 'syncRuns',
            'tsaLeaderboard', 'topProducts', 'hourlyActivity', 'teamComparison',
            'restockingByTsa', 'restockingByTeam', 'topTsa', 'reconciliationIssues'
        ));
```

- [ ] **Step 4: Add the banner to the view**

In `resources/views/dashboard.blade.php`, add this block right after the existing "API NOT CONNECTED BANNER" block (after line 30, `@endif`):

```blade
{{-- RECONCILIATION ISSUES BANNER --}}
@if(!empty($reconciliationIssues))
<div class="mb-6 flex items-start gap-4 bg-orange-50 border border-orange-200 rounded-xl px-6 py-4">
    <svg class="w-5 h-5 text-orange-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
    </svg>
    <div class="text-sm font-mono text-orange-700 space-y-1">
        <p class="font-bold">Reconciliation found {{ count($reconciliationIssues) }} issue{{ count($reconciliationIssues) === 1 ? '' : 's' }}:</p>
        @foreach($reconciliationIssues as $issue)
        <p>{{ $issue }}</p>
        @endforeach
    </div>
</div>
@endif
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=DashboardReconciliationBannerTest`
Expected: **PASS** — 3 tests.

- [ ] **Step 6: Run the full test suite to check for regressions**

Run: `php artisan test`
Expected: **PASS**, including everything from the two earlier plans in this series (`SettingsControllerTest`, `PancakeReconcileTest`) and all pre-existing tests.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/DashboardController.php resources/views/dashboard.blade.php tests/Feature/DashboardReconciliationBannerTest.php
git commit -m "feat: surface pancake:reconcile findings on the Dashboard

Same banner pattern already used for the DB-schema-error and
API-not-connected states, so reconciliation issues are visible
without anyone having to know to check logs or query Settings."
```

---

## Self-Review

**Spec coverage:** Covers the two checks agreed on in conversation — completeness (catches the outage scenario) and TSA tag-drift — surfaced via log + dashboard banner per the user's explicit choice (not email/Slack, since neither channel is actually wired to deliver anything in this app today). Explicitly excludes analytics/sale revenue reconciliation, product-keyword drift, and seller-keyword drift, each with a stated reason, so a future plan doesn't have to re-derive why they're not here.

**Placeholder scan:** No TBD/TODO. Every step has complete code, exact `php artisan test --filter=...` commands, and stated expected outcomes. Task 3's verification step (`schedule:list`) is a manual check rather than an automated test, since scheduler registration isn't something PHPUnit exercises in this codebase's existing tests either (`routes/console.php` has no test file today).

**Type consistency:** `checkCompleteness()`/`checkTagDrift()` both return `array` (list of issue strings) and are merged with `array_merge()` in `handle()` — consistent shape throughout. `reconciliation_issues` is always written as a JSON-encoded array (never null, never a bare string) — `handle()` calls `Setting::set('reconciliation_issues', json_encode($issues))` unconditionally, even when `$issues` is empty (`json_encode([])` is `'[]'`), so `DashboardController`'s `json_decode(..., true) ?: []` never has to distinguish "never run" (setting is `null`, `json_decode(null, true)` is `null`, `?: []` catches it) from "ran clean" (`'[]'` decodes to `[]`, already falsy-equivalent for the `?:` fallback) — both correctly resolve to an empty array for the view.
