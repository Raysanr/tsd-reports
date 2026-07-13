# Hide Product Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a product be hidden from forward-looking product lists (Leads Report tables, Charts comparison, TSA Performance's filter dropdown) without affecting order matching or historical reports.

**Architecture:** One new boolean column (`products.is_hidden`), one new toggle route/controller method, one new button in the Product Management view, and a `reject()` filter added at three existing read sites that already build a per-product row/table from `ProductPerformance::buildRow()`.

**Tech Stack:** Laravel 11, Blade, PHPUnit (Feature tests), SQLite for tests / MySQL for dev+prod.

---

Spec reference: `docs/superpowers/specs/2026-07-13-hide-product-design.md`

## Baseline check

Before starting, confirm the test suite is green:

```bash
php artisan test
```
Expected: all tests pass (31 as of this plan being written). If anything fails, stop and fix that first — these tasks assume a clean baseline.

---

### Task 1: `is_hidden` column on products

**Files:**
- Create: `database/migrations/2026_07_13_140000_add_is_hidden_to_products_table.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: `2026_07_13_140000_add_is_hidden_to_products_table ... DONE`

- [ ] **Step 3: Add a boolean cast so Eloquent returns real booleans**

Without this, MySQL/SQLite return the raw column as `int(0)`/`int(1)`, and the strict `=== false` check in the next step fails. In `app/Models/Product.php`, add right after the `$fillable` array:

```php
    protected $casts = [
        'is_hidden' => 'boolean',
    ];
```

(Do NOT add `is_hidden` to `$fillable` — the toggle route in Task 2 sets the attribute directly, not through mass assignment, so it never needs to be mass-assignable.)

- [ ] **Step 4: Verify the column exists and defaults to false**

Run: `php artisan tinker --execute="echo App\Models\Product::first()->is_hidden === false ? 'OK' : 'FAIL';"`
Expected: `OK`

- [ ] **Step 5: Run the full test suite to confirm nothing broke**

Run: `php artisan test`
Expected: all tests still pass (a plain `ADD COLUMN ... DEFAULT false` is safe on sqlite/mysql/pgsql alike, no driver branching needed)

- [ ] **Step 6: Commit — the migration and the cast ONLY**

The working directory has other unrelated uncommitted changes from earlier work. Stage these two files by exact path (never `git add -A`/`git add .`, which would sweep in that unrelated work):

```bash
git add database/migrations/2026_07_13_140000_add_is_hidden_to_products_table.php app/Models/Product.php
git commit -m "Add is_hidden column to products"
```

After committing, run `git show --stat HEAD` and confirm it lists exactly those two files — nothing else.

---

### Task 2: Toggle route + controller method

**Files:**
- Modify: `routes/web.php:58` (right after the existing `product-management.destroy` route)
- Modify: `app/Http/Controllers/ProductManagementController.php`
- Test: `tests/Feature/ProductManagementControllerTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ProductManagementControllerTest.php`, inside the class, after `test_destroy_removes_a_product`:

```php
    public function test_toggle_hidden_hides_a_visible_product(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $this->assertFalse($product->is_hidden);

        $response = $this->patch(route('product-management.toggle-hidden', $product));

        $response->assertRedirect(route('product-management'));
        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_hidden' => true]);
    }

    public function test_toggle_hidden_twice_unhides_it_again(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();

        $this->patch(route('product-management.toggle-hidden', $product));
        $this->patch(route('product-management.toggle-hidden', $product));

        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_hidden' => false]);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=ProductManagementControllerTest`
Expected: FAIL — `Route [product-management.toggle-hidden] not defined.`

- [ ] **Step 3: Add the route**

In `routes/web.php`, right after the line:
```php
    Route::delete('/product-management/{product}',   [ProductManagementController::class, 'destroy'])->name('product-management.destroy');
```
add:
```php
    Route::patch('/product-management/{product}/toggle-hidden', [ProductManagementController::class, 'toggleHidden'])->name('product-management.toggle-hidden');
```

- [ ] **Step 4: Add the controller method**

In `app/Http/Controllers/ProductManagementController.php`, add this method right after `destroy()`:

```php
    public function toggleHidden(Product $product)
    {
        $product->is_hidden = !$product->is_hidden;
        $product->save();

        $verb = $product->is_hidden ? 'Hidden' : 'Unhidden';

        return redirect()->route('product-management')
            ->with('success', "{$verb} \"{$product->display_name}\".");
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=ProductManagementControllerTest`
Expected: PASS (7 tests)

- [ ] **Step 6: Commit**

```bash
git add routes/web.php app/Http/Controllers/ProductManagementController.php tests/Feature/ProductManagementControllerTest.php
git commit -m "Add product hide/unhide toggle endpoint"
```

---

### Task 3: Product Management view — toggle button + hidden badge

**Files:**
- Modify: `resources/views/product-management.blade.php`
- Test: `tests/Feature/ProductManagementControllerTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/ProductManagementControllerTest.php`:

```php
    public function test_hidden_products_show_a_hidden_badge_on_the_page(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $product->is_hidden = true;
        $product->save();

        $response = $this->get(route('product-management'));

        $response->assertOk();
        $response->assertSee('Hidden');
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=test_hidden_products_show_a_hidden_badge_on_the_page`
Expected: FAIL — page never contains the literal text "Hidden"

- [ ] **Step 3: Replace the product row block**

In `resources/views/product-management.blade.php`, replace the entire block from `@foreach($group['products'] as $product)` through its matching `@endforeach` (lines 45-77) with:

```blade
            @foreach($group['products'] as $product)
            <div class="px-6 py-3 flex items-center gap-4 {{ $product->is_hidden ? 'opacity-50' : '' }}">
                <div class="flex-1">
                    <div class="flex items-center gap-2">
                        <p class="text-sm font-mono font-semibold text-slate-700">{{ $product->display_name }}</p>
                        @if($product->is_hidden)
                        <span class="text-[10px] font-semibold text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded">Hidden</span>
                        @endif
                    </div>
                    @if($product->match_keyword)
                    <p class="text-[10px] text-slate-400 font-mono">matches: {{ $product->match_keyword }}</p>
                    @endif
                </div>

                <div class="flex items-center gap-1 shrink-0">
                    <button type="button"
                        class="editProductBtn p-1.5 rounded-lg text-slate-400 hover:text-yellow-600 hover:bg-yellow-50 transition-colors cursor-pointer"
                        title="Edit"
                        data-id="{{ $product->id }}"
                        data-display-name="{{ $product->display_name }}"
                        data-match-keyword="{{ $product->match_keyword }}"
                        data-team="{{ $product->team }}">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                    </button>
                    <button type="button"
                        class="toggleHiddenBtn p-1.5 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50 transition-colors cursor-pointer"
                        title="{{ $product->is_hidden ? 'Unhide' : 'Hide' }}"
                        data-id="{{ $product->id }}">
                        @if($product->is_hidden)
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/>
                        </svg>
                        @else
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        @endif
                    </button>
                    <button type="button"
                        class="deleteProductBtn p-1.5 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors cursor-pointer"
                        title="Remove"
                        data-id="{{ $product->id }}"
                        data-name="{{ $product->display_name }}">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16"/>
                        </svg>
                    </button>
                </div>
            </div>
            @endforeach
```

- [ ] **Step 4: Add the hidden toggle form**

Right after the existing block:
```blade
<form id="deleteProductForm" method="POST" style="display:none">
    @csrf
    @method('DELETE')
</form>
```
add:
```blade
<form id="toggleHiddenProductForm" method="POST" style="display:none">
    @csrf
    @method('PATCH')
</form>
```

- [ ] **Step 5: Wire up the toggle button's JS**

In the `@push('scripts')` block, right after this existing line (near the top of the IIFE):
```js
    const storeUrl    = form.action;
```
add:
```js
    const toggleHiddenForm = document.getElementById('toggleHiddenProductForm');
```

Then, right after the existing `deleteProductBtn` wiring block:
```js
    const deleteForm = document.getElementById('deleteProductForm');
    document.querySelectorAll('.deleteProductBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            const name = btn.dataset.name || 'this product';
            if (!confirm(`Remove "${name}"? This can't be undone.`)) return;
            deleteForm.action = storeUrl + '/' + btn.dataset.id;
            deleteForm.submit();
        });
    });
```
add:
```js
    document.querySelectorAll('.toggleHiddenBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            toggleHiddenForm.action = storeUrl + '/' + btn.dataset.id + '/toggle-hidden';
            toggleHiddenForm.submit();
        });
    });
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=ProductManagementControllerTest`
Expected: PASS (8 tests)

- [ ] **Step 7: Manually verify in the browser**

Run: `php artisan serve` (if not already running), visit `http://localhost:8000/product-management`, click the new eye icon on any product, confirm the row dims and shows a "Hidden" badge, click it again and confirm it returns to normal.

- [ ] **Step 8: Commit**

```bash
git add resources/views/product-management.blade.php tests/Feature/ProductManagementControllerTest.php
git commit -m "Add hide/unhide toggle button to Product Management page"
```

---

### Task 4: Leads Report — exclude hidden products (per-team view)

**Files:**
- Modify: `app/Http/Controllers/LeadsReportController.php`
- Test: `tests/Feature/LeadsReportHiddenProductTest.php` (new file)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/LeadsReportHiddenProductTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadsReportHiddenProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_hidden_product_with_no_leads_in_range_is_dropped_from_the_team_view(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $product->is_hidden = true;
        $product->save();

        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            return $tables->doesntContain(fn($t) => $t['product']->display_name === 'SINUXYL');
        });
    }

    public function test_hidden_product_with_leads_in_range_still_shows_on_the_team_view(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $product->is_hidden = true;
        $product->save();

        $shift = TsaShift::where('team', 'SH Naturals')->first();
        Order::create([
            'pancake_order_id'   => 'hidden-sinuxyl-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'product'            => 'Sinuxyl',
            'raw_tags'           => ['SINUXYL', strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell'          => false,
            'status_code'        => 1,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            return $tables->contains(fn($t) => $t['product']->display_name === 'SINUXYL');
        });
    }
}
```

- [ ] **Step 2: Run the tests to verify the first one fails**

Run: `php artisan test --filter=LeadsReportHiddenProductTest`
Expected: `test_hidden_product_with_no_leads_in_range_is_dropped_from_the_team_view` FAILS (SINUXYL's table still present — no filtering exists yet); the second test passes already (hidden products aren't excluded yet, so it trivially shows)

- [ ] **Step 3: Add the filter**

In `app/Http/Controllers/LeadsReportController.php`, find this block inside `index()`:

```php
        $productTables = $products->map(function ($product) use ($slots, $ordersBySlot, $allOrders) {
            $hourlyRows = [];
            foreach ($slots as $slot) {
                $hourOrders = $ordersBySlot->get($slot['key'], collect());
                if ($hourOrders->isEmpty()) continue;

                $row = ProductPerformance::buildRow($product, $hourOrders);
                // Skip hours where THIS product had no leads at all (other products may
                // still have had activity that hour — $hourOrders holds every product's
                // orders, and buildRow's matching already scoped it down to this one).
                if ($row['total'] === 0) continue;

                $hourlyRows[] = ['label' => $slot['label'], 'row' => $row];
            }

            return [
                'product'    => $product,
                'hourlyRows' => $hourlyRows,
                'total'      => ProductPerformance::buildRow($product, $allOrders),
            ];
        })->values();
```

Add immediately after it (before the `// Grand total` comment that follows):

```php
        // Hidden products (Product Management's hide toggle) drop out of this list
        // once they have nothing to show for the selected range — but a hidden
        // product's table still renders for a range where it actually had leads, so
        // looking back at an old month it was still active in isn't affected.
        $productTables = $productTables->reject(
            fn($table) => $table['product']->is_hidden && $table['total']['total'] === 0
        )->values();
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=LeadsReportHiddenProductTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: all tests pass

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/LeadsReportController.php tests/Feature/LeadsReportHiddenProductTest.php
git commit -m "Exclude hidden products with no leads from Leads Report's team view"
```

---

### Task 5: Leads Report — exclude hidden products (ALL view)

**Files:**
- Modify: `app/Http/Controllers/LeadsReportController.php`
- Test: `tests/Feature/LeadsReportHiddenProductTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/LeadsReportHiddenProductTest.php`:

```php
    public function test_hidden_product_with_no_orders_in_range_is_dropped_from_the_all_view(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $product->is_hidden = true;
        $product->save();

        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'all', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('productRows', function ($rows) {
            return $rows->doesntContain(fn($r) => $r['display_name'] === 'SINUXYL');
        });
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=test_hidden_product_with_no_orders_in_range_is_dropped_from_the_all_view`
Expected: FAIL — SINUXYL's row is still present

- [ ] **Step 3: Add the filter**

In `app/Http/Controllers/LeadsReportController.php`, inside the private `indexAll()` method, find:

```php
        $productRows = $products->map(fn($product) => ProductPerformance::buildRow($product, $orders))->values();
```

Replace it with:

```php
        // Same hidden-product rule as the per-team view above: dropped only when
        // there's genuinely nothing to show for the selected range.
        $productRows = $products
            ->map(fn($product) => ['product' => $product, 'row' => ProductPerformance::buildRow($product, $orders)])
            ->reject(fn($item) => $item['product']->is_hidden && $item['row']['total'] === 0)
            ->pluck('row')
            ->values();
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=LeadsReportHiddenProductTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: all tests pass

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/LeadsReportController.php tests/Feature/LeadsReportHiddenProductTest.php
git commit -m "Exclude hidden products with no orders from Leads Report's ALL view"
```

---

### Task 6: Charts — exclude hidden products from the comparison row

**Files:**
- Modify: `app/Http/Controllers/ChartsController.php`
- Test: `tests/Feature/ChartsHiddenProductTest.php` (new file)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ChartsHiddenProductTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartsHiddenProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_hidden_product_with_no_orders_in_range_is_dropped_from_the_comparison_chart(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $product->is_hidden = true;
        $product->save();

        $response = $this->get(route('charts'));

        $response->assertOk();
        $response->assertViewHas('productRows', function ($rows) {
            return $rows->doesntContain(fn($r) => $r['display_name'] === 'SINUXYL');
        });
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ChartsHiddenProductTest`
Expected: FAIL — SINUXYL's row is still present (fresh test DB has zero orders for it anyway, so this exercises the "hidden AND zero orders" path directly)

- [ ] **Step 3: Add the filter**

In `app/Http/Controllers/ChartsController.php`, find:

```php
        $productRows = $products
            ->map(fn($p) => ProductPerformance::buildRow($p, $orders))
            ->sortByDesc('upselling_rate')
            ->values();
```

Replace it with:

```php
        // Same hidden-product rule as Leads Report: dropped only when there's
        // genuinely nothing to show for the selected range.
        $productRows = $products
            ->map(fn($p) => ['product' => $p, 'row' => ProductPerformance::buildRow($p, $orders)])
            ->reject(fn($item) => $item['product']->is_hidden && $item['row']['total'] === 0)
            ->pluck('row')
            ->sortByDesc('upselling_rate')
            ->values();
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=ChartsHiddenProductTest`
Expected: PASS (1 test)

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: all tests pass

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ChartsController.php tests/Feature/ChartsHiddenProductTest.php
git commit -m "Exclude hidden products with no orders from the Charts comparison row"
```

---

### Task 7: TSA Performance — exclude hidden products from the filter dropdown

**Files:**
- Modify: `app/Http/Controllers/TsaPerformanceController.php`
- Test: `tests/Feature/TsaPerformanceProductFilterTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/TsaPerformanceProductFilterTest.php`, inside the class:

```php
    public function test_hidden_product_is_excluded_from_the_dropdown_regardless_of_date(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $product->is_hidden = true;
        $product->save();

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals']));

        $response->assertOk();
        $response->assertViewHas('availableProducts', function ($products) {
            return $products->doesntContain(fn($p) => $p->display_name === 'SINUXYL');
        });
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=test_hidden_product_is_excluded_from_the_dropdown_regardless_of_date`
Expected: FAIL — SINUXYL still appears in `availableProducts`

- [ ] **Step 3: Add the filter**

In `app/Http/Controllers/TsaPerformanceController.php`, find:

```php
        $availableProducts = Product::where('team', $teamsConfig[$selectedTeam]['order_team'])
            ->orderBy('sort_order')->get();
```

Replace it with:

```php
        // Hidden products never appear in this filter dropdown, regardless of date
        // range — it's a picker shortcut, not a data view, and "All Products" (the
        // default) already includes their data correctly either way.
        $availableProducts = Product::where('team', $teamsConfig[$selectedTeam]['order_team'])
            ->where('is_hidden', false)
            ->orderBy('sort_order')->get();
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=TsaPerformanceProductFilterTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: all tests pass

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/TsaPerformanceController.php tests/Feature/TsaPerformanceProductFilterTest.php
git commit -m "Exclude hidden products from TSA Performance's product filter dropdown"
```

---

### Task 8: End-to-end manual verification

- [ ] **Step 1: Full test suite**

Run: `php artisan test`
Expected: all tests pass

- [ ] **Step 2: Manual walkthrough**

1. Visit `/product-management`, hide a product with recent activity (e.g. SINUXYL).
2. Visit `/leads-report?team=sh-naturals` for today — confirm SINUXYL's table is gone.
3. Change the date range to a day you know SINUXYL had leads (e.g. yesterday) — confirm its table is back.
4. Visit `/leads-report?team=all` for today — confirm SINUXYL's row is gone from the combined table.
5. Visit `/charts` — confirm SINUXYL is absent from the product comparison chart.
6. Visit `/tsa-performance?team=sh-naturals` — open the product filter dropdown, confirm SINUXYL isn't listed.
7. Go back to `/product-management`, click the eye icon again to unhide SINUXYL, confirm the badge disappears and everything reverts.

- [ ] **Step 3: Final commit (if any cleanup was needed)**

```bash
git status
```
If clean, nothing further to do.
