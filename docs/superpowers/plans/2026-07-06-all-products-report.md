# "ALL" Per-Product Summary Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an "ALL" option to the TSA Performance page's team toggle that shows a per-product summary table (both teams combined) instead of the hourly per-TSA breakdown, and fix the existing report's mislabeled Upselling Rate formula.

**Architecture:** `TsaPerformanceController::index()` gains an early branch: when `team=all`, it calls a new `indexAll()` method that loops every `Product` (both teams) and builds one row each via a new `buildProductRow()`, rendering a new, separate Blade view (`tsa-performance-all.blade.php`) instead of the existing hourly grid. A shared `productRates()` helper computes Pick-up/Conversion/Upselling rates from raw counts so the Grand Total row is computed from its own summed counts, not by averaging per-row percentages.

**Tech Stack:** Laravel 11, Eloquent, Blade, PHPUnit (sqlite `:memory:`, per `phpunit.xml`).

---

## Reference: spec

Full design at `docs/superpowers/specs/2026-07-06-all-products-report-design.md`. Read it before starting if anything below is unclear.

## File Structure

- Modify: `app/Http/Controllers/TsaPerformanceController.php` — rename/fix `conversionRate()` → `upsellingRate()`; add `indexAll()`, `buildProductRow()`, `productRates()`, `PRODUCT_SUMMARY_COLUMNS`.
- Modify: `resources/views/tsa-performance.blade.php` — rename `conversion_rate`/`totalConversionRate` references to `upselling_rate`/`totalUpsellingRate`; add "ALL" to the team-button loop (via controller-side `$teams` change, no template change needed for the button itself).
- Create: `resources/views/tsa-performance-all.blade.php` — the new per-product summary view.
- Create: `tests/Feature/TsaPerformanceUpsellingRateTest.php`
- Create: `tests/Feature/TsaPerformanceAllProductsTest.php`

---

### Task 1: Fix the Upselling Rate formula

**Files:**
- Modify: `app/Http/Controllers/TsaPerformanceController.php` (the `conversionRate()` method and its 3 call sites)
- Modify: `resources/views/tsa-performance.blade.php:120-121,137,153`
- Test: `tests/Feature/TsaPerformanceUpsellingRateTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TsaPerformanceUpsellingRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_upselling_rate_is_upsell_over_upsell_plus_confirmed_via_call(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        $today = now()->toDateString();

        // 3 upsell orders (disposition deliberately NOT "confirmed via call", so it
        // doesn't also get counted as a confirmed_via_call order below).
        for ($i = 0; $i < 3; $i++) {
            Order::create([
                'pancake_order_id'   => "upsell-$i",
                'team'               => 'SH Naturals',
                'tsa_name'           => $shift->tsa_key,
                'disposition'        => 'UPSELL W CONFIRMATION',
                'is_upsell'          => true,
                'status_code'        => 1,
                'pancake_created_at' => now(),
                'synced_at'          => now(),
            ]);
        }

        // 2 confirmed-via-call-only orders (no upsell).
        for ($i = 0; $i < 2; $i++) {
            Order::create([
                'pancake_order_id'   => "confirmed-$i",
                'team'               => 'SH Naturals',
                'tsa_name'           => $shift->tsa_key,
                'disposition'        => 'CONFIRMED VIA CALL',
                'is_upsell'          => false,
                'status_code'        => 1,
                'pancake_created_at' => now(),
                'synced_at'          => now(),
            ]);
        }

        // Upselling Rate = 3 / (3 + 2) = 60.0%, NOT the old (broken) 3/5-of-total formula
        // which would coincidentally also read 60% here — so also assert against a
        // total that DIFFERS from (upsell + confirmed_via_call) to prove which formula
        // is actually running: add an unrelated, uncatered order that inflates total
        // but must NOT affect the rate under the correct formula.
        Order::create([
            'pancake_order_id'   => 'uncatered-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => null,
            'disposition'        => 'UNCATERED LEADS',
            'is_upsell'          => false,
            'status_code'        => 0,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date' => $today]));

        $response->assertOk();
        $response->assertViewHas('totalUpsellingRate', 60.0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/TsaPerformanceUpsellingRateTest.php`
Expected: FAIL — the view has no `totalUpsellingRate` key yet (still named `totalConversionRate`), or the value differs once renamed, since the current formula divides by `total` (6 orders here) instead of `upsell + confirmed_via_call` (5)

- [ ] **Step 3: Fix the controller**

In `app/Http/Controllers/TsaPerformanceController.php`, replace:

```php
    /** Upsell conversions as a % of total called leads — null when there were no leads at all. */
    private function conversionRate(array $columns): ?float
    {
        if ($columns['total'] <= 0) return null;
        return round($columns['upsell_confirmation'] / $columns['total'] * 100, 1);
    }
```

with:

```php
    /** Upsell w/ Confirmation as a % of (Upsell w/ Confirmation + Confirmed via Call) —
     *  the official Upselling Rate formula (TSD Updated Formula Base, May 2026). Null
     *  when both are zero (nothing to compute a rate from). */
    private function upsellingRate(array $columns): ?float
    {
        $denominator = $columns['upsell_confirmation'] + $columns['confirmed_via_call'];
        if ($denominator <= 0) return null;
        return round($columns['upsell_confirmation'] / $denominator * 100, 1);
    }
```

Then update the 3 call sites. Replace:

```php
        $row['conversion_rate'] = $this->conversionRate($row);
```

with:

```php
        $row['upselling_rate'] = $this->upsellingRate($row);
```

Replace:

```php
                'conversion_rate'  => $this->conversionRate($blockTotals),
```

with:

```php
                'upselling_rate'   => $this->upsellingRate($blockTotals),
```

Replace:

```php
        $totalConversionRate = $this->conversionRate($totals);

        return view('tsa-performance', compact(
            'selectedDate', 'hourBlocks', 'totals', 'showEmpty',
            'teams', 'selectedTeam', 'metricCols', 'totalConversionRate',
            'availableProducts', 'selectedProduct'
        ));
```

with:

```php
        $totalUpsellingRate = $this->upsellingRate($totals);

        return view('tsa-performance', compact(
            'selectedDate', 'hourBlocks', 'totals', 'showEmpty',
            'teams', 'selectedTeam', 'metricCols', 'totalUpsellingRate',
            'availableProducts', 'selectedProduct'
        ));
```

- [ ] **Step 4: Update the view**

In `resources/views/tsa-performance.blade.php`, replace (around line 120-121):

```blade
                    <td class="border border-slate-200 px-2 py-2.5 text-center font-semibold {{ $row['conversion_rate'] !== null ? 'text-yellow-700' : 'text-slate-300' }}">
                        {{ $row['conversion_rate'] !== null ? $row['conversion_rate'].'%' : '—' }}
                    </td>
```

with:

```blade
                    <td class="border border-slate-200 px-2 py-2.5 text-center font-semibold {{ $row['upselling_rate'] !== null ? 'text-yellow-700' : 'text-slate-300' }}">
                        {{ $row['upselling_rate'] !== null ? $row['upselling_rate'].'%' : '—' }}
                    </td>
```

Replace (around line 137):

```blade
                    <td class="border border-slate-600 px-3 py-2.5 text-center text-yellow-300">
                        {{ $block['conversion_rate'] !== null ? $block['conversion_rate'].'%' : '—' }}
                    </td>
```

with:

```blade
                    <td class="border border-slate-600 px-3 py-2.5 text-center text-yellow-300">
                        {{ $block['upselling_rate'] !== null ? $block['upselling_rate'].'%' : '—' }}
                    </td>
```

Replace (around line 153):

```blade
                    <td class="border border-slate-700 px-3 py-3 text-center text-yellow-300">
                        {{ $totalConversionRate !== null ? $totalConversionRate.'%' : '—' }}
                    </td>
```

with:

```blade
                    <td class="border border-slate-700 px-3 py-3 text-center text-yellow-300">
                        {{ $totalUpsellingRate !== null ? $totalUpsellingRate.'%' : '—' }}
                    </td>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/TsaPerformanceUpsellingRateTest.php`
Expected: PASS

- [ ] **Step 6: Run the full test suite to check for regressions**

Run: `php artisan test`
Expected: all tests pass — no other test references `conversion_rate`/`totalConversionRate` (confirm with `grep -rn "conversion_rate\|totalConversionRate" app/ resources/ tests/` returning nothing)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/TsaPerformanceController.php resources/views/tsa-performance.blade.php tests/Feature/TsaPerformanceUpsellingRateTest.php
git commit -m "fix: correct Upselling Rate formula to upsell / (upsell + confirmed via call)"
```

---

### Task 2: `indexAll()` + `buildProductRow()` controller logic

**Files:**
- Modify: `app/Http/Controllers/TsaPerformanceController.php`
- Test: `tests/Feature/TsaPerformanceAllProductsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TsaPerformanceAllProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_view_renders_one_row_per_product_with_correct_totals(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        $sinuxyl = Product::where('display_name', 'SINUXYL')->first();
        $today = now()->toDateString();

        // 1 confirmed-via-call order for SINUXYL
        Order::create([
            'pancake_order_id'   => 'sinuxyl-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'product'            => 'Sinuxyl',
            'is_upsell'          => false,
            'status_code'        => 1,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        // 1 genuinely uncatered order for SINUXYL
        Order::create([
            'pancake_order_id'   => 'sinuxyl-2',
            'team'               => 'SH Naturals',
            'tsa_name'           => null,
            'disposition'        => 'UNCATERED LEADS',
            'product'            => 'Sinuxyl',
            'is_upsell'          => false,
            'status_code'        => 0,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $response = $this->get(route('tsa-performance', ['team' => 'all', 'date' => $today]));

        $response->assertOk();
        $response->assertViewHas('productRows', function ($rows) use ($sinuxyl) {
            $row = $rows->firstWhere('display_name', $sinuxyl->display_name);
            return $row
                && $row['total'] === 2
                && $row['catered'] === 1
                && $row['excess'] === 1;
        });
        $response->assertViewHas('grandTotal', fn($g) => $g['total'] === 2 && $g['catered'] === 1 && $g['excess'] === 1);
    }

    public function test_all_view_does_not_expose_hourly_view_data(): void
    {
        $response = $this->get(route('tsa-performance', ['team' => 'all', 'date' => now()->toDateString()]));

        $response->assertOk();
        $response->assertViewMissing('hourBlocks');
    }

    public function test_selecting_a_real_team_still_renders_the_hourly_view(): void
    {
        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date' => now()->toDateString()]));

        $response->assertOk();
        $response->assertViewIs('tsa-performance');
    }

    public function test_product_with_zero_orders_has_null_rates_not_a_division_error(): void
    {
        $response = $this->get(route('tsa-performance', ['team' => 'all', 'date' => '2020-01-01']));

        $response->assertOk();
        $response->assertViewHas('productRows', function ($rows) {
            return $rows->every(fn($r) => $r['total'] === 0 && $r['pick_up_rate'] === null && $r['conversion_rate'] === null && $r['upselling_rate'] === null);
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/TsaPerformanceAllProductsTest.php`
Expected: FAIL — `team=all` currently falls into the existing `if (!array_key_exists($selectedTeam, $teamsConfig))` branch and resets to `sh-naturals`, so `productRows`/`grandTotal` view data don't exist yet

- [ ] **Step 3: Add the controller logic**

In `app/Http/Controllers/TsaPerformanceController.php`, add this new const right after the existing `COLUMNS` const:

```php
    /** Accumulator keys for the "ALL" per-product summary (adds 'answered'/'unanswered'
     *  subtotals, needed so the Grand Total's rates are computed from its own summed
     *  counts — never by averaging the per-product percentages, which would be wrong). */
    private const PRODUCT_SUMMARY_COLUMNS = [
        'total', 'catered',
        'confirmed_via_call', 'upsell_confirmation', 'call_back', 'call_dropped',
        'repeat_order_upsell', 'rude_customer', 'relatives_confirmation',
        'dfr', 'double_order', 'fsd_uncleared', 'not_answering', 'unattended', 'invalid_number',
        'excess', 'answered', 'unanswered',
    ];
```

Add this import at the top, alongside the existing `use App\Models\Product;`:

```php
use Illuminate\Support\Carbon;
```

(Already present — skip if it's already imported. Check the current imports first; `Carbon` is already used in `index()`, so this should already be there.)

In `index()`, insert this branch immediately after `$teamsConfig = config('teams', []);` and before the existing `if (!array_key_exists($selectedTeam, $teamsConfig))` line:

```php
        if ($selectedTeam === 'all') {
            return $this->indexAll($selectedDate, $date, $teamsConfig);
        }
```

Also, in the existing line further down in `index()`:

```php
        $teams       = collect($teamsConfig)->map(fn($t) => $t['name']);
```

change it to:

```php
        $teams       = collect($teamsConfig)->map(fn($t) => $t['name']);
        $teams->prepend('ALL', 'all');
```

Add these 3 new private methods anywhere after `index()` (e.g. right before `conversionRate`/`upsellingRate`):

```php
    private function indexAll(string $selectedDate, Carbon $date, array $teamsConfig)
    {
        $orderTeams = collect($teamsConfig)->pluck('order_team')->all();

        $orders = Order::whereBetween('pancake_created_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->whereIn('team', $orderTeams)
            ->get();

        $products    = Product::orderBy('team')->orderBy('sort_order')->get();
        $productRows = $products->map(fn($product) => $this->buildProductRow($product, $orders))->values();

        $grandTotal = array_fill_keys(self::PRODUCT_SUMMARY_COLUMNS, 0);
        foreach ($productRows as $row) {
            foreach (self::PRODUCT_SUMMARY_COLUMNS as $col) {
                $grandTotal[$col] += $row[$col];
            }
        }
        $grandTotal = array_merge($grandTotal, $this->productRates($grandTotal));

        $teams = collect($teamsConfig)->map(fn($t) => $t['name']);
        $teams->prepend('ALL', 'all');

        return view('tsa-performance-all', [
            'selectedDate' => $selectedDate,
            'productRows'  => $productRows,
            'grandTotal'   => $grandTotal,
            'teams'        => $teams,
            'selectedTeam' => 'all',
            'metricCols'   => self::METRIC_COLUMNS,
        ]);
    }

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

        $row['answered'] = $row['confirmed_via_call'] + $row['upsell_confirmation'] + $row['call_back'] + $row['call_dropped']
            + $row['repeat_order_upsell'] + $row['rude_customer'] + $row['relatives_confirmation'];
        $row['unanswered'] = $row['dfr'] + $row['double_order'] + $row['fsd_uncleared'] + $row['not_answering']
            + $row['unattended'] + $row['invalid_number'];

        return array_merge($row, $this->productRates($row));
    }

    /** Pick-up / Conversion / Upselling rates from a row with 'answered', 'unanswered',
     *  'upsell_confirmation', and 'confirmed_via_call' keys — shared by per-product rows
     *  and the Grand Total row (see PRODUCT_SUMMARY_COLUMNS doc comment for why). */
    private function productRates(array $row): array
    {
        $totalCalled = $row['answered'] + $row['unanswered'];

        return [
            'pick_up_rate'    => $totalCalled > 0 ? round($row['answered'] / $totalCalled * 100, 1) : null,
            // Denominator is Answered only, NOT Answered + Unanswered — confirmed
            // against the "TSD Updated Formula Base" reference ("Total Answered Called Leads").
            'conversion_rate' => $row['answered'] > 0 ? round($row['upsell_confirmation'] / $row['answered'] * 100, 1) : null,
            'upselling_rate'  => $this->upsellingRate($row),
        ];
    }
```

- [ ] **Step 4: Create a minimal placeholder view so the tests can render**

Create `resources/views/tsa-performance-all.blade.php` with just enough to satisfy the tests (the full UI is built in Task 3):

```blade
@extends('layouts.app')
@section('title', 'TSA Performance')
@section('content')
<div>All Products</div>
@endsection
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/TsaPerformanceAllProductsTest.php`
Expected: PASS (4 tests, all green)

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: all tests pass, including Task 1's `TsaPerformanceUpsellingRateTest` and every pre-existing test

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/TsaPerformanceController.php resources/views/tsa-performance-all.blade.php tests/Feature/TsaPerformanceAllProductsTest.php
git commit -m "feat: add per-product summary data/logic for the ALL team view"
```

---

### Task 3: Full `tsa-performance-all.blade.php` view

**Files:**
- Modify: `resources/views/tsa-performance-all.blade.php` (replace placeholder from Task 2)

No new automated test — this is presentational HTML already covered by Task 2's controller tests for the underlying data. Verify manually in Step 2.

- [ ] **Step 1: Replace the placeholder view**

Replace the full contents of `resources/views/tsa-performance-all.blade.php` with:

```blade
@extends('layouts.app')
@section('title', 'TSA Performance')
@section('subtitle', 'All products · ' . $selectedDate)

@section('content')

@if($productRows->isEmpty())
<div class="bg-white rounded-xl border border-slate-200 shadow-sm py-24 flex flex-col items-center justify-center gap-4">
    <svg class="w-12 h-12 text-slate-200" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">No products configured</p>
    <p class="text-xs font-mono text-slate-300">Add products on the Product Management page.</p>
</div>
@else

<div class="overflow-auto bg-white rounded-xl border border-slate-200 shadow-sm" style="max-height:calc(100vh - 180px)">
    <table class="w-full border-collapse text-xs font-mono" style="min-width:1400px">
        <thead class="sticky top-0 z-20 shadow-sm">
            <tr>
                <th rowspan="2"
                    class="bg-yellow-50 border border-slate-300 px-3 py-2.5 text-left text-[11px] font-bold text-slate-700 uppercase tracking-wide whitespace-nowrap"
                    style="min-width:180px">
                    Product
                </th>
                <th rowspan="2"
                    class="bg-yellow-50 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-slate-700 uppercase tracking-wide whitespace-nowrap">
                    Total<br>Leads
                </th>
                <th rowspan="2"
                    class="bg-yellow-50 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-slate-700 uppercase tracking-wide whitespace-nowrap">
                    Catered<br>Leads
                </th>
                <th colspan="7"
                    class="bg-green-200 border border-slate-300 px-3 py-2 text-center text-[11px] font-bold text-green-900 uppercase tracking-wide">
                    Answered Called Leads
                </th>
                <th colspan="6"
                    class="bg-red-200 border border-slate-300 px-3 py-2 text-center text-[11px] font-bold text-red-900 uppercase tracking-wide">
                    Unanswered Call Leads
                </th>
                <th colspan="1"
                    class="bg-rose-300 border border-slate-300 px-3 py-2 text-center text-[11px] font-bold text-rose-900 uppercase tracking-wide">
                    Excess Leads
                </th>
                <th rowspan="2"
                    class="bg-blue-100 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-blue-900 uppercase tracking-wide leading-tight"
                    style="min-width:90px">
                    Pick-up<br>Rate
                </th>
                <th rowspan="2"
                    class="bg-orange-100 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-orange-900 uppercase tracking-wide leading-tight"
                    style="min-width:90px">
                    Conversion<br>Rate
                </th>
                <th rowspan="2"
                    class="bg-yellow-100 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-yellow-900 uppercase tracking-wide leading-tight"
                    style="min-width:90px">
                    Upselling<br>Rate
                </th>
            </tr>
            <tr>
                @foreach($metricCols as $col)
                @php
                    $headerColor = match($col['group']) {
                        'answered' => 'bg-green-50 text-green-800',
                        'excess'   => 'bg-rose-50 text-rose-800',
                        default    => 'bg-red-50 text-red-800',
                    };
                @endphp
                <th class="{{ $headerColor }} border border-slate-300 px-2 py-2 text-center text-[10px] font-semibold uppercase tracking-wide leading-tight"
                    style="min-width:{{ $col['min_width'] }}px">
                    {!! $col['label'] !!}
                </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($productRows as $row)
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="border border-slate-200 px-3 py-2.5 font-semibold text-slate-700 whitespace-nowrap">
                    {{ $row['display_name'] }}
                    <div class="text-[10px] font-normal text-slate-400">{{ $row['team'] }}</div>
                </td>
                <td class="border border-slate-200 px-3 py-2.5 text-center font-bold text-slate-800">
                    {{ $row['total'] ?: '' }}
                </td>
                <td class="border border-slate-200 px-3 py-2.5 text-center font-bold text-slate-800">
                    {{ $row['catered'] ?: '' }}
                </td>
                @foreach($metricCols as $col)
                <td class="border border-slate-200 px-2 py-2.5 text-center {{ !empty($col['highlight']) ? 'text-green-700 font-semibold' : ($col['group'] === 'excess' ? 'text-rose-700 font-semibold' : 'text-slate-700') }}">
                    {{ $row[$col['key']] ?: '' }}
                </td>
                @endforeach
                <td class="border border-slate-200 px-2 py-2.5 text-center font-semibold {{ $row['pick_up_rate'] !== null ? 'text-blue-700' : 'text-slate-300' }}">
                    {{ $row['pick_up_rate'] !== null ? $row['pick_up_rate'].'%' : '—' }}
                </td>
                <td class="border border-slate-200 px-2 py-2.5 text-center font-semibold {{ $row['conversion_rate'] !== null ? 'text-orange-700' : 'text-slate-300' }}">
                    {{ $row['conversion_rate'] !== null ? $row['conversion_rate'].'%' : '—' }}
                </td>
                <td class="border border-slate-200 px-2 py-2.5 text-center font-semibold {{ $row['upselling_rate'] !== null ? 'text-yellow-700' : 'text-slate-300' }}">
                    {{ $row['upselling_rate'] !== null ? $row['upselling_rate'].'%' : '—' }}
                </td>
            </tr>
            @endforeach

            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-3 py-3 uppercase tracking-wider text-[11px]">Grand Total</td>
                <td class="border border-slate-700 px-3 py-3 text-center">{{ $grandTotal['total'] ?: '' }}</td>
                <td class="border border-slate-700 px-3 py-3 text-center">{{ $grandTotal['catered'] ?: '' }}</td>
                @foreach($metricCols as $col)
                <td class="border border-slate-700 px-2 py-3 text-center {{ !empty($col['highlight']) ? 'text-green-300' : ($col['group'] === 'excess' ? 'text-rose-300' : '') }}">
                    {{ $grandTotal[$col['key']] ?: '' }}
                </td>
                @endforeach
                <td class="border border-slate-700 px-3 py-3 text-center text-blue-300">
                    {{ $grandTotal['pick_up_rate'] !== null ? $grandTotal['pick_up_rate'].'%' : '—' }}
                </td>
                <td class="border border-slate-700 px-3 py-3 text-center text-orange-300">
                    {{ $grandTotal['conversion_rate'] !== null ? $grandTotal['conversion_rate'].'%' : '—' }}
                </td>
                <td class="border border-slate-700 px-3 py-3 text-center text-yellow-300">
                    {{ $grandTotal['upselling_rate'] !== null ? $grandTotal['upselling_rate'].'%' : '—' }}
                </td>
            </tr>
        </tbody>
    </table>
</div>

@endif
@endsection

@push('topbar-right')
<div class="flex items-center gap-4 flex-wrap">

@if($selectedDate === now('Asia/Manila')->format('Y-m-d'))
@include('partials.live-indicator')
@endif

<form method="GET" action="{{ route('tsa-performance') }}" class="flex items-center gap-3 flex-wrap">
    <input type="hidden" name="team" value="{{ $selectedTeam }}">

    <div class="flex rounded-lg border border-slate-200 overflow-hidden">
        @foreach($teams as $key => $label)
        <button type="submit" name="team" value="{{ $key }}"
                class="px-3 py-1.5 text-xs font-semibold font-mono cursor-pointer transition-colors
                       {{ $selectedTeam === $key ? 'bg-primary text-white' : 'bg-white text-slate-500 hover:bg-slate-50' }}">
            {{ $label }}
        </button>
        @endforeach
    </div>

    @include('partials.date-picker', ['mode' => 'single', 'id' => 'drp', 'date' => $selectedDate, 'submit' => 'form', 'dateField' => 'date'])

    <button type="submit"
            class="px-4 py-1.5 bg-yellow-700 text-white text-xs font-semibold rounded-lg
                   hover:bg-yellow-800 transition-colors cursor-pointer">
        Load
    </button>
</form>

</div>
@endpush
```

- [ ] **Step 2: Manually verify in the browser**

Run: `php artisan serve` (if not already running — check `lsof -i :8000` first), then visit `http://127.0.0.1:8000/tsa-performance?team=all&date=2026-07-05`
Expected: page loads, shows one row per product (16 rows) grouped SH Naturals-then-Eyecare, a Grand Total row at the bottom, and Pick-up/Conversion/Upselling Rate columns with real percentages (not all `—`). Clicking "SH Naturals" or "Eyecare" from this page returns to the existing hourly view; clicking "ALL" from the hourly view lands back here.

- [ ] **Step 3: Commit**

```bash
git add resources/views/tsa-performance-all.blade.php
git commit -m "feat: build out the ALL per-product summary table UI"
```

---

### Task 4: Verify against real data

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite one final time**

Run: `php artisan test`
Expected: all tests pass (Task 1's and Task 2's new tests, plus every pre-existing test — 20+ tests total)

- [ ] **Step 2: Check the real Grand Total against the two already-verified team totals**

Visit `http://127.0.0.1:8000/tsa-performance?team=all&date=2026-07-05` against the real (non-test) database.
Expected: Grand Total's Total/Catered/Excess roughly match the sum of the two teams' already-verified totals from the earlier debugging session — **SH Naturals 131/79/52 + Eyecare 150/104/46 → Grand Total ≈ 281/183/98** (small variance expected from the known, separately-tracked upsell-product-naming gap — this is not a regression to fix as part of this plan).

- [ ] **Step 3: Spot-check one product's rates by hand**

Pick any product row with a non-zero total (e.g. SINUXYL) from the Step 2 page, and manually verify: Pick-up Rate = (sum of the 7 green Answered columns) ÷ (that sum + sum of the 6 red Unanswered columns); Conversion Rate = Upsell w/ Confirmation ÷ (the same Answered sum); Upselling Rate = Upsell w/ Confirmation ÷ (Upsell w/ Confirmation + Confirmed via Call). Confirm the displayed percentages match your hand calculation to one decimal place.
