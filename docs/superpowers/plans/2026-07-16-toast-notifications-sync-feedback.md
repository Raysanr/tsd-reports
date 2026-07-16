# Toast Notifications + Sync Feedback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the app's four duplicated inline `session('success')` banners with one shared, dismissible toast component, and give the Dashboard's Sync button real feedback (order/upsell counts, or the failure reason) instead of completing silently.

**Architecture:** A single toast container lives in the persistent layout (`layouts/app.blade.php`), fed by a global `window.showToast(message, variant)` helper in `resources/js/app.js`. Server-side flash messages (`session('success')`/`session('error')`/`session('info')`) are turned into a toast call on page load via a small inline script in the layout. `DashboardController::sync()` is changed to read the `SyncRun` rows its own sync command already writes and return real counts, which the Sync button's existing JS feeds into `window.showToast`.

**Tech Stack:** Laravel 12 (Blade views), Tailwind CSS v4 (utility classes only, no new config), vanilla JS (no framework, no bundler test runner), PHPUnit Feature tests with `RefreshDatabase` + `Http::fake()`.

**Reference spec:** `docs/superpowers/specs/2026-07-16-toast-notifications-sync-feedback-design.md`

---

## Task 1: Add the toast component to `resources/js/app.js`

**Files:**
- Modify: `resources/js/app.js`

This is pure client-side JS with no server dependency, so it goes first — every later task calls `window.showToast`.

- [ ] **Step 1: Append the toast component to the end of `resources/js/app.js`**

Add this to the very end of the file (after the existing CSV/PNG export `document.addEventListener('click', ...)` block):

```js

// ─── Toast notifications ─────────────────────────────────────────────────────
// window.showToast(message, variant) is the one entry point every part of the
// app uses for transient feedback — server-flashed messages (see the bootstrap
// script in layouts/app.blade.php) and client-side actions (e.g. the Dashboard's
// Sync button) both go through this. Reuses the exact card styling the old
// per-page session('success') banners used (bg-{color}-50/border-{color}-200/
// rounded-xl), just floated in a fixed corner instead of inline in the page.
const TOAST_VARIANTS = {
    success: {
        classes      : 'bg-green-50 border-green-200',
        iconClasses  : 'text-green-500',
        textClasses  : 'text-green-700',
        closeClasses : 'text-green-400 hover:text-green-600',
        // Checkmark — identical glyph to the banner this replaces.
        iconPath     : 'M5 13l4 4L19 7',
    },
    error: {
        classes      : 'bg-red-50 border-red-200',
        iconClasses  : 'text-red-500',
        textClasses  : 'text-red-700',
        closeClasses : 'text-red-400 hover:text-red-600',
        // x-circle — distinct SHAPE from success, not just color, so the
        // variant reads correctly for colorblind users too.
        iconPath     : 'M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    },
    info: {
        classes      : 'bg-blue-50 border-blue-200',
        iconClasses  : 'text-blue-500',
        textClasses  : 'text-blue-700',
        closeClasses : 'text-blue-400 hover:text-blue-600',
        // info-circle — deliberately not the brand yellow (bg-yellow-*): that
        // color is reserved elsewhere in this app for "a custom date filter is
        // active" (see partials/date-picker.blade.php's dot indicator), and
        // reusing it here would make a toast read as that unrelated signal.
        iconPath     : 'M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z',
    },
};

window.showToast = function (message, variant = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const v = TOAST_VARIANTS[variant] || TOAST_VARIANTS.success;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const toast = document.createElement('div');
    toast.setAttribute('role', 'status');
    toast.className = 'pointer-events-auto flex items-center gap-3 border rounded-xl px-4 py-3 shadow-lg '
        + v.classes + ' opacity-0 transition-all duration-200 ease-out'
        + (reduceMotion ? '' : ' translate-x-4');

    // Icon + close button are built from fixed, developer-controlled strings
    // (safe as innerHTML). The message itself is set via textContent below,
    // NEVER interpolated into innerHTML — flashed messages can contain
    // admin-entered free text (e.g. a product/TSA/user display name), and
    // building HTML from that would be a stored XSS hole.
    toast.innerHTML = `
        <svg class="w-4 h-4 ${v.iconClasses} shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="${v.iconPath}"/>
        </svg>
        <p class="text-sm font-mono ${v.textClasses} flex-1"></p>
        <button type="button" class="${v.closeClasses} shrink-0 cursor-pointer" aria-label="Dismiss">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    `;
    toast.querySelector('p').textContent = message;

    container.appendChild(toast);

    // Animate in next frame (so the initial opacity-0/translate-x-4 actually
    // paints first — setting the "in" classes in the same tick would collapse
    // into one state and skip the transition).
    requestAnimationFrame(() => {
        toast.classList.remove('opacity-0');
        toast.classList.add('opacity-100');
        if (!reduceMotion) {
            toast.classList.remove('translate-x-4');
            toast.classList.add('translate-x-0');
        }
    });

    let dismissTimer = null;
    const dismiss = () => {
        clearTimeout(dismissTimer);
        toast.classList.remove('opacity-100', 'translate-x-0');
        toast.classList.add('opacity-0');
        if (!reduceMotion) toast.classList.add('translate-x-4');
        toast.classList.replace('duration-200', 'duration-150');
        toast.classList.replace('ease-out', 'ease-in');
        setTimeout(() => toast.remove(), reduceMotion ? 0 : 150);
    };

    const startTimer = () => { dismissTimer = setTimeout(dismiss, 4000); };
    startTimer();

    toast.addEventListener('mouseenter', () => clearTimeout(dismissTimer));
    toast.addEventListener('mouseleave', startTimer);
    toast.querySelector('button').addEventListener('click', dismiss);
};
```

- [ ] **Step 2: Verify the file builds with no syntax errors**

Run: `cd "tsd-reports" && npm run build`
Expected: exits 0, output ends with a `vite v...  building for production...` summary and no error mentioning `app.js`.

(There is no JS test runner in this project — Vite's build is the only automated check available for a syntax/reference error here. Full visual/behavioral verification happens in Task 6 once the container this depends on exists.)

- [ ] **Step 3: Commit**

```bash
cd "tsd-reports"
git add resources/js/app.js
git commit -m "$(cat <<'EOF'
Add shared toast notification component

Reusable window.showToast(message, variant) for success/error/info
feedback, styled to match the app's existing flash-banner cards.
Nothing calls it yet.
EOF
)"
```

---

## Task 2: Add the toast container and session-flash bootstrap to the layout

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Test: `tests/Feature/ToastContainerRenderingTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ToastContainerRenderingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToastContainerRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_authenticated_page_renders_the_toast_container(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('id="toastContainer"', false);
        $response->assertSee('aria-live="polite"', false);
    }

    public function test_a_flashed_success_message_is_rendered_as_a_toast_call(): void
    {
        $this->actingAs(User::factory()->create());
        session()->flash('success', 'Test flash message.');

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('window.showToast(', false);
        $response->assertSee('Test flash message.', false);
        $response->assertSee("'success'", false);
    }

    public function test_no_bootstrap_script_is_rendered_when_nothing_is_flashed(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('window.showToast(', false);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd "tsd-reports" && php artisan test --filter=ToastContainerRenderingTest`
Expected: FAIL — `#toastContainer` doesn't exist in the layout yet, so the first test's `assertSee('id="toastContainer"', false)` fails.

- [ ] **Step 3: Add the toast container**

In `resources/views/layouts/app.blade.php`, find:

```html
<body class="flex h-screen overflow-hidden bg-slate-100">

{{-- Mobile-only backdrop, shown behind the sidebar while it's open as an overlay drawer --}}
<div id="sidebarBackdrop" class="hidden fixed inset-0 bg-black/50 z-40 md:hidden"></div>
```

Replace with:

```html
<body class="flex h-screen overflow-hidden bg-slate-100">

{{-- Toast notifications — populated by window.showToast() (resources/js/app.js).
     z-[70]: above the sidebar (z-50) and its mobile backdrop (z-40), so a toast
     is never hidden behind either, including with the mobile drawer open.
     pointer-events-none on the container so the empty space around toasts
     doesn't block clicks on the page underneath; each toast card opts back in
     via pointer-events-auto so its own close button still works. --}}
<div id="toastContainer" aria-live="polite" aria-atomic="false"
     class="fixed top-4 right-4 z-[70] flex flex-col gap-2 w-full max-w-sm pointer-events-none"></div>

{{-- Mobile-only backdrop, shown behind the sidebar while it's open as an overlay drawer --}}
<div id="sidebarBackdrop" class="hidden fixed inset-0 bg-black/50 z-40 md:hidden"></div>
```

- [ ] **Step 4: Add the session-flash bootstrap script**

In the same file, find the end of the sidebar drawer script block:

```html
    // Switching to a desktop width (e.g. rotating a tablet) shouldn't leave the
    // drawer open-but-invisible behind the now-static sidebar.
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) closeSidebar();
    });
})();
</script>

@stack('scripts')
</body>
</html>
```

Replace with:

```html
    // Switching to a desktop width (e.g. rotating a tablet) shouldn't leave the
    // drawer open-but-invisible behind the now-static sidebar.
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) closeSidebar();
    });
})();
</script>

{{-- One-shot toast for this request's flashed session message, if any — this is
     what every controller's ->with('success', ...) now renders as (replacing
     the old per-page inline banner blocks). Deferred to DOMContentLoaded
     because @vite's app.js is a module script (defers like a classic `defer`
     script): window.showToast isn't defined yet at the point this inline
     script is parsed, only by the time DOMContentLoaded fires — module/defer
     scripts always run before that event. --}}
@if(session('success') || session('error') || session('info'))
<script>
document.addEventListener('DOMContentLoaded', function () {
    @if(session('success')) window.showToast(@json(session('success')), 'success'); @endif
    @if(session('error'))   window.showToast(@json(session('error')),   'error');   @endif
    @if(session('info'))    window.showToast(@json(session('info')),    'info');    @endif
});
</script>
@endif

@stack('scripts')
</body>
</html>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd "tsd-reports" && php artisan test --filter=ToastContainerRenderingTest`
Expected: PASS — 3 tests, 3 assertions groups all green.

- [ ] **Step 6: Commit**

```bash
cd "tsd-reports"
git add resources/views/layouts/app.blade.php tests/Feature/ToastContainerRenderingTest.php
git commit -m "$(cat <<'EOF'
Render session-flash messages as toasts from the layout

Adds the fixed toast container and a DOMContentLoaded-deferred
bootstrap script that turns session('success')/('error')/('info')
into a window.showToast() call. The four pages still rendering their
own inline banner are updated in a later task.
EOF
)"
```

---

## Task 3: Enrich `DashboardController::sync()` with real counts

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php:235-249`
- Test: `tests/Feature/DashboardSyncFeedbackTest.php`

`SyncTodayOrders::recordRun()` (`app/Console/Commands/SyncTodayOrders.php:403-421`) already writes one `SyncRun` row per `Artisan::call('pancake:sync-today', ...)`, with `new_orders`, `upsell_count`, `upsell_sales`, `success`, and `error_message`. `sync()` currently discards all of that and returns a bare `{success: true, date_from, date_to}`. This task makes it read those rows back.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/DashboardSyncFeedbackTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardSyncFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_sync_endpoint_reports_failure_when_pancake_is_not_configured(): void
    {
        // No pancake_api_key / shop_id Setting — SyncTodayOrders::handle()
        // bails immediately and records a failed SyncRun (see
        // app/Console/Commands/SyncTodayOrders.php:62-66).
        $response = $this->postJson(route('dashboard.sync'), [
            'date_from' => '2026-07-10',
            'date_to'   => '2026-07-10',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('new_orders', 0);
        $response->assertJsonPath('upsell_count', 0);
        $response->assertJsonPath('upsell_sales', 0.0);
        $this->assertStringContainsString(
            'API key or shop ID not configured',
            $response->json('error_message')
        );
    }

    public function test_sync_endpoint_reports_zero_new_orders_when_pancake_returns_nothing(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::response(['data' => []]),
        ]);

        $response = $this->postJson(route('dashboard.sync'), [
            'date_from' => '2026-07-10',
            'date_to'   => '2026-07-10',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('new_orders', 0);
        $response->assertJsonPath('upsell_count', 0);
        $response->assertJsonPath('upsell_sales', 0.0);
        $response->assertJsonPath('error_message', null);
    }

    public function test_sync_endpoint_creates_and_aggregates_one_run_per_day_in_range(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::response(['data' => []]),
        ]);

        $response = $this->postJson(route('dashboard.sync'), [
            'date_from' => '2026-07-08',
            'date_to'   => '2026-07-10',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        // One SyncRun row per day (07-08, 07-09, 07-10) — proves the loop in
        // sync() still runs once per day, and the aggregation below counts
        // every one of them, not just the last.
        $this->assertSame(3, SyncRun::count());
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd "tsd-reports" && php artisan test --filter=DashboardSyncFeedbackTest`
Expected: FAIL on the `assertJsonPath('new_orders', 0)` calls — the current response has no `new_orders` key at all.

- [ ] **Step 3: Implement the aggregation**

In `app/Http/Controllers/DashboardController.php`, find:

```php
    public function sync(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->toDateString());
        $dateTo   = $request->input('date_to',   $dateFrom);

        $from = Carbon::parse($dateFrom);
        $to   = Carbon::parse($dateTo);

        while ($from->lte($to)) {
            \Artisan::call('pancake:sync-today', ['--date' => $from->toDateString()]);
            $from->addDay();
        }

        return response()->json(['success' => true, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
    }
```

Replace with:

```php
    public function sync(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->toDateString());
        $dateTo   = $request->input('date_to',   $dateFrom);

        $from = Carbon::parse($dateFrom);
        $to   = Carbon::parse($dateTo);

        // Every Artisan::call below writes exactly one new SyncRun row
        // (SyncTodayOrders::recordRun) — remember the high-water mark first so
        // the rows created by THIS request can be picked out afterward without
        // guessing how many days were in range.
        $lastRunIdBeforeSync = SyncRun::max('id') ?? 0;

        while ($from->lte($to)) {
            \Artisan::call('pancake:sync-today', ['--date' => $from->toDateString()]);
            $from->addDay();
        }

        $runsFromThisSync = SyncRun::where('id', '>', $lastRunIdBeforeSync)->orderBy('id')->get();
        $firstFailure      = $runsFromThisSync->first(fn (SyncRun $run) => !$run->success);

        return response()->json([
            'success'       => $firstFailure === null,
            'date_from'     => $dateFrom,
            'date_to'       => $dateTo,
            'new_orders'    => (int) $runsFromThisSync->sum('new_orders'),
            'upsell_count'  => (int) $runsFromThisSync->sum('upsell_count'),
            'upsell_sales'  => (float) $runsFromThisSync->sum('upsell_sales'),
            'error_message' => $firstFailure?->error_message,
        ]);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd "tsd-reports" && php artisan test --filter=DashboardSyncFeedbackTest`
Expected: PASS — 3 tests green.

- [ ] **Step 5: Run the existing role-access test to confirm no regression**

Run: `cd "tsd-reports" && php artisan test --filter=RoleAccessTest`
Expected: PASS — `test_normal_user_can_trigger_sync` and `test_guest_role_cannot_trigger_sync` still pass unchanged (they only assert the HTTP status, not the JSON body shape).

- [ ] **Step 6: Commit**

```bash
cd "tsd-reports"
git add app/Http/Controllers/DashboardController.php tests/Feature/DashboardSyncFeedbackTest.php
git commit -m "$(cat <<'EOF'
Return real SyncRun counts from the /sync endpoint

DashboardController::sync() previously discarded the result of every
Artisan::call('pancake:sync-today', ...) and returned a bare
{success: true}. Now aggregates the SyncRun row each call writes
(new_orders, upsell_count, upsell_sales, success, error_message)
across the whole date range, so callers can show what actually
happened instead of just "done". Nothing consumes the new fields yet.
EOF
)"
```

---

## Task 4: Wire the Dashboard Sync button to show a toast

**Files:**
- Modify: `resources/views/dashboard.blade.php:645-678`
- Test: `tests/Feature/DashboardSyncFeedbackTest.php` (extend from Task 3)

- [ ] **Step 1: Write the failing test**

Add this test method to `tests/Feature/DashboardSyncFeedbackTest.php` (inside the class, alongside the others added in Task 3):

```php
    public function test_dashboard_page_wires_the_sync_button_to_show_a_toast(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertOk();
        // Weak-but-real regression guard: PHPUnit can't execute the click
        // handler (no browser), so this just proves the wiring is present in
        // the shipped markup. The actual click-and-see-a-toast behavior is
        // verified manually (see Task 6).
        $response->assertSee('window.showToast(', false);
        $response->assertSee('data.error_message', false);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd "tsd-reports" && php artisan test --filter=DashboardSyncFeedbackTest`
Expected: FAIL on `test_dashboard_page_wires_the_sync_button_to_show_a_toast` — `window.showToast(` isn't in `dashboard.blade.php` yet.

- [ ] **Step 3: Update the Sync button's script**

In `resources/views/dashboard.blade.php`, find:

```html
        const csrfToken = document.querySelector('meta[name=csrf-token]').content;
        fetch('/sync', {
            method : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body   : JSON.stringify({ date_from: range.from, date_to: range.to }),
        })
        .then(r => r.json())
        // Swap the freshly synced numbers in place — no full reload, no
        // flicker, scroll position kept (softRefresh in resources/js/app.js).
        .then(() => window.softRefresh())
        .finally(() => { syncBtn.disabled = false; icon.classList.remove('animate-spin'); });
```

Replace with:

```html
        const csrfToken = document.querySelector('meta[name=csrf-token]').content;
        fetch('/sync', {
            method : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body   : JSON.stringify({ date_from: range.from, date_to: range.to }),
        })
        .then(r => r.json())
        .then((data) => {
            if (data.success) {
                const peso = (data.upsell_sales || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                const message = data.new_orders > 0
                    ? `Synced — ${data.new_orders} new order${data.new_orders === 1 ? '' : 's'}, ${data.upsell_count} upsell${data.upsell_count === 1 ? '' : 's'} (₱${peso})`
                    : 'Synced — no new orders.';
                window.showToast(message, 'success');
            } else {
                window.showToast(`Sync failed: ${data.error_message || 'Unknown error'}`, 'error');
            }
            // Swap the freshly synced numbers in place — no full reload, no
            // flicker, scroll position kept (softRefresh in resources/js/app.js).
            return window.softRefresh();
        })
        .finally(() => { syncBtn.disabled = false; icon.classList.remove('animate-spin'); });
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd "tsd-reports" && php artisan test --filter=DashboardSyncFeedbackTest`
Expected: PASS — 4 tests green (the 3 from Task 3 plus this one).

- [ ] **Step 5: Verify the asset build still succeeds**

Run: `cd "tsd-reports" && npm run build`
Expected: exits 0, no error.

- [ ] **Step 6: Commit**

```bash
cd "tsd-reports"
git add resources/views/dashboard.blade.php tests/Feature/DashboardSyncFeedbackTest.php
git commit -m "$(cat <<'EOF'
Show a toast with the result of Dashboard Sync

The Sync button previously spun, POSTed, and soft-refreshed with no
visible confirmation of what happened. Now shows "Synced — N new
orders, M upsells (₱X)" or "Sync failed: <reason>" using the counts
DashboardController::sync() now returns.
EOF
)"
```

---

## Task 5: Remove the duplicated inline banners from the four admin pages

**Files:**
- Modify: `resources/views/product-management.blade.php:8-15`
- Modify: `resources/views/tsa-management.blade.php:10-17`
- Modify: `resources/views/user-management.blade.php:8-15`
- Modify: `resources/views/settings.blade.php:8-15`
- Test: `tests/Feature/ToastReplacesFlashBannerTest.php`

All four files currently render the identical block:

```html
    @if(session('success'))
    <div class="flex items-center gap-3 bg-green-50 border border-green-200 rounded-xl px-5 py-4">
        <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
        <p class="text-sm font-mono text-green-700">{{ session('success') }}</p>
    </div>
    @endif
```

Since Task 2 made the layout render `session('success')` as a toast automatically, this block is now pure duplication. The `$errors->any()` block immediately below it in each file is **not** touched — those are field-level validation errors, not transient status, and stay inline next to the form per the design spec's non-goals.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ToastReplacesFlashBannerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToastReplacesFlashBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /** @return array<string, array{0: string}> */
    public static function pageRouteProvider(): array
    {
        return [
            'product-management' => ['product-management'],
            'tsa-management'     => ['tsa-management'],
            'user-management'    => ['user-management'],
            'settings'           => ['settings'],
        ];
    }

    /** @dataProvider pageRouteProvider */
    public function test_page_no_longer_renders_its_own_inline_success_banner(string $routeName): void
    {
        session()->flash('success', 'Saved.');

        $response = $this->get(route($routeName));

        $response->assertOk();
        // The old banner's exact wrapper classes — if this string is gone,
        // the inline block was removed (the toast bootstrap script in the
        // layout, covered by ToastContainerRenderingTest, is what renders the
        // message now instead).
        $response->assertDontSee('bg-green-50 border border-green-200 rounded-xl px-5 py-4', false);
        $response->assertSee('window.showToast(', false);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd "tsd-reports" && php artisan test --filter=ToastReplacesFlashBannerTest`
Expected: FAIL on all 4 data-provider cases — each page still renders the old banner block.

- [ ] **Step 3: Remove the block from `resources/views/product-management.blade.php`**

Find:

```html
@section('content')
<div class="max-w-3xl space-y-6">

    @if(session('success'))
    <div class="flex items-center gap-3 bg-green-50 border border-green-200 rounded-xl px-5 py-4">
        <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
        <p class="text-sm font-mono text-green-700">{{ session('success') }}</p>
    </div>
    @endif

    @if($errors->any())
```

Replace with:

```html
@section('content')
<div class="max-w-3xl space-y-6">

    @if($errors->any())
```

- [ ] **Step 4: Remove the block from `resources/views/user-management.blade.php`**

Same find/replace as Step 3 (identical surrounding markup in this file: `<div class="max-w-3xl space-y-6">` wrapper, same banner block, same `@if($errors->any())` right after).

- [ ] **Step 5: Remove the block from `resources/views/settings.blade.php`**

Find:

```html
@section('content')
<div class="max-w-2xl space-y-6">

    @if(session('success'))
    <div class="flex items-center gap-3 bg-green-50 border border-green-200 rounded-xl px-5 py-4">
        <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
        <p class="text-sm font-mono text-green-700">{{ session('success') }}</p>
    </div>
    @endif

    {{-- Step 1: Paste API Key --}}
```

Replace with:

```html
@section('content')
<div class="max-w-2xl space-y-6">

    {{-- Step 1: Paste API Key --}}
```

- [ ] **Step 6: Remove the block from `resources/views/tsa-management.blade.php`**

This file's banner sits inside an extra wrapper div (`<div class="flex-1 min-w-0 space-y-6">`), not directly under the `max-w-*` div. Find:

```html
<div class="flex-1 min-w-0 space-y-6">

    @if(session('success'))
    <div class="flex items-center gap-3 bg-green-50 border border-green-200 rounded-xl px-5 py-4">
        <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
        <p class="text-sm font-mono text-green-700">{{ session('success') }}</p>
    </div>
    @endif

    @if($errors->any())
```

Replace with:

```html
<div class="flex-1 min-w-0 space-y-6">

    @if($errors->any())
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `cd "tsd-reports" && php artisan test --filter=ToastReplacesFlashBannerTest`
Expected: PASS — all 4 data-provider cases green.

- [ ] **Step 8: Run the full test suite to confirm nothing else broke**

Run: `cd "tsd-reports" && php artisan test`
Expected: PASS — every test green, including `ProductManagementControllerTest`, `TsaManagementControllerTest`, `UserManagementControllerTest`, `SettingsControllerTest` (none of these assert on the removed banner markup directly, but this confirms it).

- [ ] **Step 9: Commit**

```bash
cd "tsd-reports"
git add resources/views/product-management.blade.php resources/views/tsa-management.blade.php \
        resources/views/user-management.blade.php resources/views/settings.blade.php \
        tests/Feature/ToastReplacesFlashBannerTest.php
git commit -m "$(cat <<'EOF'
Remove the duplicated inline success banner from 4 admin pages

Product/TSA/User Management and Settings each rendered their own
copy of the same session('success') banner. The layout now renders
that same session key as a toast (see previous commit), so the
inline copies are dead code. Field-level validation errors
($errors->any()) are untouched — those stay inline next to the form.
EOF
)"
```

---

## Task 6: Manual verification pass

**Files:** none — this is a checklist, run against the running app.

Automated tests cover response shape, markup presence, and JSON aggregation, but cannot exercise animation, timing, or real browser rendering. Run the app locally (`npm run dev` in one terminal, `php artisan serve` in another, or whatever this project's normal local-dev flow is) and confirm:

- [ ] **Step 1:** Update a Product's display name on `/product-management`. Confirm a green toast appears top-right with the exact message the old banner used to show, auto-dismisses after ~4 seconds, and can be closed early with the × button.
- [ ] **Step 2:** Repeat Step 1 for `/tsa-management` (add or edit a TSA), `/user-management` (add or edit a user), and `/settings` (save/detect/clear).
- [ ] **Step 3:** On the Dashboard, click Sync for a date range you know has synced orders before. Confirm the toast reads `"Synced — N new orders, M upsells (₱X.XX)"` with real, non-placeholder numbers.
- [ ] **Step 4:** Click Sync again immediately for the same range (no new orders this time). Confirm the toast reads `"Synced — no new orders."`, not `"...0 new orders..."`.
- [ ] **Step 5:** Temporarily clear the Pancake API key on `/settings` (or edit the `pancake_api_key` Setting row directly), click Sync, confirm a red toast reads `"Sync failed: API key or shop ID not configured."`. Restore the key afterward.
- [ ] **Step 6:** Resize the browser to mobile width, open the sidebar drawer, trigger any toast (e.g. Sync). Confirm the toast is still visible above the drawer/backdrop, not hidden behind them.
- [ ] **Step 7:** In Chrome DevTools, enable "Emulate CSS media feature prefers-reduced-motion: reduce", trigger a toast, confirm it fades in/out with no slide.
- [ ] **Step 8:** Trigger two toasts in quick succession (e.g. Sync twice fast). Confirm they stack vertically with visible spacing, not overlapping.
- [ ] **Step 9:** Hover over a toast before its 4s auto-dismiss elapses. Confirm it does not disappear while hovered, and resumes its countdown after the mouse leaves.

- [ ] **Step 10: Final commit (only if any of the above required a fix)**

If every check above passes with no code changes needed, there is nothing to commit here. If a fix was needed, commit it with a message describing what manual check caught it.

---

## Self-review notes

- **Spec coverage:** container/position/z-index ✓ (Task 2), anatomy/variants ✓ (Task 1), motion/auto-dismiss/manual-dismiss/hover-pause ✓ (Task 1), accessibility (`aria-live`, decorative icon, textContent not innerHTML for the message) ✓ (Task 1–2), sync feedback with real counts ✓ (Task 3–4), migrating the four pages ✓ (Task 5), non-goal of leaving `$errors->any()` inline ✓ (Task 5 explicitly does not touch it).
- **Type consistency:** `window.showToast(message, variant)` signature is identical everywhere it's called (Task 1 definition, Task 2's Blade bootstrap, Task 4's Sync handler). The JSON keys returned by `DashboardController::sync()` in Task 3 (`success`, `new_orders`, `upsell_count`, `upsell_sales`, `error_message`) match exactly what Task 4's JS reads (`data.success`, `data.new_orders`, `data.upsell_count`, `data.upsell_sales`, `data.error_message`).
- **No placeholders:** every step above has complete, exact code — nothing deferred to "add appropriate handling" language.
