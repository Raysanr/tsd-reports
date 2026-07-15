# Settings API Key Verification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop `SettingsController::save()` from silently persisting a Pancake API key that doesn't actually work, so a bad/placeholder key can never again shadow a working one and break every scheduled sync.

**Architecture:** `SettingsController` already has a private `detectShop(string $apiKey): array` helper that calls Pancake's `GET /shops` endpoint and reports whether a key is valid — it's used today only by the AJAX `/settings/detect` "Detect Shop" button, purely for the *client-side* UX. `save()` (the actual persistence path, hit on form submit) never calls it — it trusts whatever `api_key`/`shop_id` values arrive in the POST body, which are just hidden form fields the browser can submit unchanged even if "Detect Shop" was never clicked (or the JS failed, or the endpoint was hit directly). This plan makes `save()` call the same verifier server-side before writing anything to the database, and rejects the save with a visible form error if the key doesn't check out or doesn't match the shop being saved.

Along the way, `detectShop()` is rewritten from a raw streaming `\GuzzleHttp\Client` call with regex-scraped JSON to a normal `Http::get(...)` call parsed with `->json()`. Two independent reasons this is in scope, not a drive-by change: (1) raw `\GuzzleHttp\Client` calls are invisible to Laravel's `Http::fake()`, so there is no way to unit-test `save()`'s new verification step without this change; (2) the existing regex parsing (`preg_match('/"id"\s*:\s*(\d+)/', ...)` against a truncated 2KB stream) is reading the wrong response key entirely — confirmed against Pancake's published OpenAPI spec, `GET /shops` returns `{"shops": [...]}`, not `{"data": [...]}` as the old code's comments assumed.

**Tech Stack:** Laravel 12, PHPUnit (via `php artisan test`), `Illuminate\Support\Facades\Http` fakes (existing project convention — see `tests/Feature/SyncTodayOrdersBacklogTest.php`).

**Real-world incident this prevents:** on 2026-07-13 18:24:14, the DB `pancake_api_key` setting was overwritten with the literal string `"test-key"` (8 characters — passes today's `min:8` validation trivially). Every scheduled sync from that moment until the key was manually restored ~19 hours later failed with `403 api_key is invalid`, and nothing in the app surfaced this beyond the Dashboard's sync-run history. This plan's Task 2 tests reproduce that exact scenario as a regression test.

---

## File Structure

- **Modify:** `app/Http/Controllers/SettingsController.php` — rewrite `detectShop()` to use `Http::get()`; make `save()` call it before persisting.
- **Create:** `tests/Feature/SettingsControllerTest.php` — covers `detect()` (existing endpoint, currently untested) and the new verification behavior in `save()`.

No view changes: `resources/views/settings.blade.php` already renders `$errors->all()` inside the save form (`resources/views/settings.blade.php:117-121`), so a validation error added via `withErrors()` in `save()` displays automatically with no template edits.

No migration/model changes: `Setting` is a generic key/value store and needs no schema change.

---

## Task 1: Rewrite `detectShop()` on top of the `Http` facade

**Files:**
- Modify: `app/Http/Controllers/SettingsController.php:1-9` (imports), `:77-124` (`detectShop()`)
- Test: `tests/Feature/SettingsControllerTest.php` (new file)

This task only touches `detectShop()` and the `/settings/detect` AJAX endpoint that already calls it (`detect()`, unchanged). It does not touch `save()` yet — that's Task 2. Splitting it this way means Task 1 is safe to land on its own: it only changes *how* the existing "Detect Shop" button verifies a key, not what triggers verification.

- [ ] **Step 1: Write the failing tests for `detect()`**

Create `tests/Feature/SettingsControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_detect_reports_success_for_a_working_key(): void
    {
        $this->actingAs(User::factory()->create());

        Http::fake([
            'pos.pages.fm/api/v1/shops' => Http::response([
                'shops' => [
                    ['id' => 30037101, 'name' => 'My Shop'],
                ],
            ], 200),
        ]);

        $response = $this->postJson(route('settings.detect'), ['api_key' => 'a-working-key']);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'shops'   => [['id' => '30037101', 'name' => 'My Shop']],
        ]);
    }

    public function test_detect_reports_failure_for_a_rejected_key(): void
    {
        $this->actingAs(User::factory()->create());

        Http::fake([
            'pos.pages.fm/api/v1/shops' => Http::response([
                'success' => false,
                'message' => 'api_key is invalid',
            ], 403),
        ]);

        $response = $this->postJson(route('settings.detect'), ['api_key' => 'test-key']);

        $response->assertOk();
        $response->assertJson(['success' => false]);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SettingsControllerTest`
Expected: both tests **FAIL**. The first fails because the current `detectShop()` parses the (unfaked, since it uses raw Guzzle) response body via regex against `{"data": ...}` shape assumptions and `Http::fake()` never intercepts it — the real network call either errors out under the test sandbox or the regex finds nothing, so `shops` comes back empty. The second may accidentally pass or fail depending on network availability; either way, the first test's failure is what step 4 must fix.

- [ ] **Step 3: Rewrite `detectShop()` and add the `Http` import**

In `app/Http/Controllers/SettingsController.php`, add the import alongside the existing ones:

```php
use App\Models\Setting;
use App\Models\TsaShift;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
```

Replace the entire `detectShop()` method (currently lines 77–123) with:

```php
    /**
     * Verifies an API key against Pancake and returns the shop it belongs to.
     * GET /shops response shape (confirmed against Pancake's published OpenAPI
     * spec): {"shops": [{"id": <int>, "name": <string>, ...}]} — NOT {"data": [...]}.
     */
    private function detectShop(string $apiKey): array
    {
        try {
            $response = Http::timeout(5)->get('https://pos.pages.fm/api/v1/shops', [
                'api_key' => $apiKey,
            ]);

            if (!$response->successful()) {
                return ['success' => false, 'message' => 'Invalid API key or connection failed.'];
            }

            $body  = $response->json();
            $shops = $body['shops'] ?? [];

            if (($body['success'] ?? true) === false || empty($shops)) {
                return ['success' => false, 'message' => $body['message'] ?? 'No shops found for this API key.'];
            }

            $first = $shops[0];

            return [
                'success' => true,
                'shops'   => [[
                    'id'   => (string) ($first['id'] ?? ''),
                    'name' => $first['name'] ?? (string) ($first['id'] ?? ''),
                ]],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Connection failed. Check your API key.'];
        }
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=SettingsControllerTest`
Expected: **PASS** — 2 tests, 2 assertions each satisfied.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/SettingsController.php tests/Feature/SettingsControllerTest.php
git commit -m "fix: parse the correct 'shops' key from Pancake's /shops response

detectShop() used a raw Guzzle client streaming the first 2KB and
regex-scraping it against a {\"data\": [...]} shape. The real response
is {\"shops\": [...]} (confirmed against Pancake's OpenAPI spec). Switch
to the Http facade + json() decoding so this is also testable with
Http::fake(), which the next change depends on."
```

---

## Task 2: Verify the key server-side in `save()` before persisting

**Files:**
- Modify: `app/Http/Controllers/SettingsController.php:34-53` (`save()`)
- Test: `tests/Feature/SettingsControllerTest.php` (append to the file from Task 1)

- [ ] **Step 1: Write the failing tests**

Append these three methods to `tests/Feature/SettingsControllerTest.php` (inside the existing class):

```php
    public function test_save_rejects_a_key_that_fails_verification_and_does_not_persist_it(): void
    {
        $this->actingAs(User::factory()->create());

        Setting::set('pancake_api_key', 'the-real-working-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops' => Http::response([
                'success' => false,
                'message' => 'api_key is invalid',
            ], 403),
        ]);

        $response = $this->post(route('settings.save'), [
            'api_key' => 'test-key',
            'shop_id' => '30037101',
        ]);

        $response->assertSessionHasErrors('api_key');
        $this->assertSame('the-real-working-key', Setting::get('pancake_api_key'));
    }

    public function test_save_rejects_when_the_verified_shop_does_not_match_the_submitted_shop_id(): void
    {
        $this->actingAs(User::factory()->create());

        Setting::set('pancake_api_key', 'the-real-working-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops' => Http::response([
                'shops' => [
                    ['id' => 99999999, 'name' => 'A Different Shop'],
                ],
            ], 200),
        ]);

        $response = $this->post(route('settings.save'), [
            'api_key' => 'a-key-for-a-different-shop',
            'shop_id' => '30037101',
        ]);

        $response->assertSessionHasErrors('api_key');
        $this->assertSame('the-real-working-key', Setting::get('pancake_api_key'));
    }

    public function test_save_persists_settings_when_the_key_verifies_and_matches(): void
    {
        $this->actingAs(User::factory()->create());

        Http::fake([
            'pos.pages.fm/api/v1/shops' => Http::response([
                'shops' => [
                    ['id' => 30037101, 'name' => 'My Shop'],
                ],
            ], 200),
        ]);

        $response = $this->post(route('settings.save'), [
            'api_key'       => 'a-working-key',
            'shop_id'       => '30037101',
            'shop_name'     => 'My Shop',
            'sync_interval' => '5',
        ]);

        $response->assertRedirect(route('settings'));
        $response->assertSessionHas('success');
        $this->assertSame('a-working-key', Setting::get('pancake_api_key'));
        $this->assertSame('30037101', Setting::get('shop_id'));
        $this->assertSame(5, (int) Setting::get('sync_interval'));
    }
```

Add the `Setting` import at the top of the test file:

```php
use App\Models\Setting;
use App\Models\User;
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SettingsControllerTest`
Expected: the three new tests **FAIL**. `save()` currently persists whatever is submitted unconditionally, so:
- `test_save_rejects_a_key_that_fails_verification...` fails because no `api_key` session error is set and `Setting::get('pancake_api_key')` now reads `'test-key'` instead of the original.
- `test_save_rejects_when_the_verified_shop_does_not_match...` fails the same way.
- `test_save_persists_settings_when_the_key_verifies_and_matches` should already pass (it's the happy path with no behavior change) — confirm it does; if it doesn't, something in Task 1 broke the happy path and must be fixed before continuing.

- [ ] **Step 3: Update `save()` to verify before persisting**

Replace the `save()` method (currently lines 34–53) with:

```php
    public function save(Request $request)
    {
        $request->validate([
            'api_key'       => 'required|string|min:8',
            'shop_id'       => 'required|string',
            'shop_name'     => 'nullable|string',
            'sync_interval' => 'nullable|integer|min:1|max:60',
        ]);

        // Re-verify server-side: the api_key/shop_id fields on this form are
        // hidden inputs populated by the "Detect Shop" AJAX call, but nothing
        // stops a stale page, a skipped detect step, or a direct POST from
        // submitting an unverified value here. Trusting them without a second
        // check is exactly how a placeholder ("test-key") once overwrote a
        // working key and broke every scheduled sync for ~19 hours with no
        // visible error until someone checked the sync-run history.
        $verification = $this->detectShop($request->input('api_key'));

        if (!$verification['success']) {
            return back()
                ->withErrors(['api_key' => $verification['message'] ?? 'That API key could not be verified with Pancake POS.'])
                ->withInput();
        }

        $verifiedShopId = $verification['shops'][0]['id'] ?? null;
        if ($verifiedShopId !== null && $verifiedShopId !== (string) $request->input('shop_id')) {
            return back()
                ->withErrors(['api_key' => 'This API key belongs to a different shop than the one being saved. Click "Detect Shop" again to refresh it.'])
                ->withInput();
        }

        // Settings live in the DB only — do NOT write to .env here. Rewriting .env
        // makes the Vite dev server restart mid-redirect, which serves the settings
        // page with no CSS/JS (the "giant unstyled logo" breakage after every save).
        Setting::set('pancake_api_key', $request->input('api_key'));
        Setting::set('shop_id',         $request->input('shop_id'));
        Setting::set('shop_name',       $request->input('shop_name', $request->input('shop_id')));
        Setting::set('sync_interval',   $request->input('sync_interval', 1));

        $shopName = $request->input('shop_name', $request->input('shop_id'));
        return redirect()->route('settings')->with('success', "Connected to \"{$shopName}\" — settings saved.");
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=SettingsControllerTest`
Expected: **PASS** — 5 tests total (2 from Task 1, 3 from this task).

- [ ] **Step 5: Run the full test suite to check for regressions**

Run: `php artisan test`
Expected: **PASS**. Pay particular attention to `tests/Feature/SyncTodayOrdersBacklogTest.php` and any other test that calls `Setting::set('pancake_api_key', 'test-key')` directly — those bypass `SettingsController::save()` entirely (they write to the `Setting` model directly, then invoke the `pancake:sync-today` Artisan command), so this change does not affect them. If any of them fail, stop and investigate before proceeding — do not adjust this plan's code to force them to pass without understanding why.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/SettingsController.php tests/Feature/SettingsControllerTest.php
git commit -m "fix: verify the Pancake API key server-side before saving settings

save() previously trusted the api_key/shop_id hidden form fields
unconditionally — the only verification (detectShop(), via the
'Detect Shop' button) ran client-side and nothing forced it to have
run before submit. On 2026-07-13 the DB key was overwritten with the
placeholder 'test-key' this way, and every scheduled sync failed
silently for ~19 hours. save() now re-verifies the key against
Pancake and checks it resolves to the shop being saved before writing
anything, rejecting the save with a visible form error otherwise."
```

---

## Self-Review

**Spec coverage:** The scope is "server-side verification before persisting Settings," established earlier in this conversation from the live incident (DB `pancake_api_key` overwritten with `"test-key"`, passing only client-side/JS verification, breaking 506 consecutive scheduled syncs). Task 1 makes the verifier testable and fixes its response-key bug (`shops` vs `data`); Task 2 wires it into `save()` and adds a regression test reproducing the incident directly (`test_save_rejects_a_key_that_fails_verification_and_does_not_persist_it`). No other subsystem was in scope — the `assigning_seller` per-item field question raised earlier is a separate, unrelated investigation and intentionally not part of this plan.

**Placeholder scan:** No TBD/TODO markers; every step has complete, runnable code and exact `php artisan test --filter=...` commands with stated expected outcomes.

**Type consistency:** `detectShop()` returns `shops[0]['id']` as a `(string)`-cast value in both Task 1 and Task 2 — Task 2's mismatch check compares it against `(string) $request->input('shop_id')`, so both sides are consistently strings (avoids a `"30037101" !== 30037101` false mismatch). `Setting::get()`/`Setting::set()` signatures are unchanged from the existing model. No new method names were introduced that could drift between tasks — `detectShop()` keeps its existing name and call signature (`string $apiKey): array`) throughout.
