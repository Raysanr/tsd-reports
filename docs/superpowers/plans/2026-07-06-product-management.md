# Product Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move product-to-team matching out of `config/teams.php` into a database-backed, UI-editable "Product Management" page, mirroring the existing "TSA Management" page, with zero change to existing behavior/data on rollout.

**Architecture:** New `products` table + `Product` model (display_name, match_keyword, team, sort_order), seeded in the migration itself from today's `config/teams.php` contents (16 products). A new `ProductManagementController` provides simple CRUD (mirroring `TsaManagementController`). The two existing call sites that currently read `config('teams')[...]['products']` — `TsaPerformanceController` and `SyncTodayOrders` — switch to querying `Product`, and both files' duplicate `PRODUCT_TAG_OVERRIDES` consts are deleted in favor of the DB's `match_keyword` column.

**Tech Stack:** Laravel 11, Eloquent, Blade, PHPUnit (sqlite `:memory:` for tests, per `phpunit.xml`).

---

## Reference: spec

Full design at `docs/superpowers/specs/2026-07-06-product-management-design.md`. Read it before starting if anything below is unclear.

## File Structure

- Create: `database/migrations/2026_07_06_000000_create_products_table.php` — table + seed data.
- Create: `app/Models/Product.php` — model + `effective_keyword` accessor.
- Create: `app/Http/Controllers/ProductManagementController.php` — CRUD.
- Create: `resources/views/product-management.blade.php` — admin page.
- Modify: `routes/web.php` — 4 new routes.
- Modify: `resources/views/layouts/app.blade.php` — sidebar nav link.
- Modify: `app/Http/Controllers/TsaPerformanceController.php` — read `Product` instead of config; delete `PRODUCT_TAG_OVERRIDES`.
- Modify: `app/Console/Commands/SyncTodayOrders.php` — `inferTeamFromProduct()` reads `Product` instead of config; delete `PRODUCT_TAG_OVERRIDES`.
- Create: `tests/Feature/ProductSeedTest.php`
- Create: `tests/Feature/ProductManagementControllerTest.php`
- Create: `tests/Feature/TsaPerformanceProductFilterTest.php`
- Create: `tests/Feature/SyncTodayOrdersProductTeamInferenceTest.php`

---

### Task 1: `products` table + seed data

**Files:**
- Create: `database/migrations/2026_07_06_000000_create_products_table.php`
- Test: `tests/Feature/ProductSeedTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_seeds_all_16_products_matching_current_config(): void
    {
        $this->assertSame(16, Product::count());
        $this->assertSame(10, Product::where('team', 'SH Naturals')->count());
        $this->assertSame(6, Product::where('team', 'Eyecare Team')->count());
    }

    public function test_canpro_keeps_its_short_match_keyword(): void
    {
        $product = Product::where('display_name', 'CANPRO JUICE DRINK')->first();

        $this->assertNotNull($product);
        $this->assertSame('CANPRO', $product->match_keyword);
        $this->assertSame('CANPRO', $product->effective_keyword);
    }

    public function test_product_with_no_override_falls_back_to_display_name_as_keyword(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();

        $this->assertNotNull($product);
        $this->assertNull($product->match_keyword);
        $this->assertSame('SINUXYL', $product->effective_keyword);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ProductSeedTest.php`
Expected: FAIL — `Class "App\Models\Product" not found`

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('display_name');
            $table->string('match_keyword')->nullable(); // null = match on display_name itself
            $table->string('team'); // literal order_team string, e.g. "SH Naturals"
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed from config/teams.php's contents as of 2026-07-06 (the original 14
        // products plus VisionEx/Vision Pro, added earlier the same day) so behavior
        // is identical the moment this ships.
        $seed = [
            // SH Naturals
            ['display_name' => 'SINUXYL',             'match_keyword' => null,       'team' => 'SH Naturals'],
            ['display_name' => 'SINUVEX',             'match_keyword' => null,       'team' => 'SH Naturals'],
            ['display_name' => 'STEAMPACK',           'match_keyword' => null,       'team' => 'SH Naturals'],
            ['display_name' => 'AUDICURE',            'match_keyword' => null,       'team' => 'SH Naturals'],
            ['display_name' => 'GINSENG SERUM',       'match_keyword' => 'GINSENG',  'team' => 'SH Naturals'],
            ['display_name' => 'VITAMIN C TONER',     'match_keyword' => null,       'team' => 'SH Naturals'],
            ['display_name' => 'CANPRO JUICE DRINK',  'match_keyword' => 'CANPRO',   'team' => 'SH Naturals'],
            ['display_name' => 'BATH PACK',           'match_keyword' => null,       'team' => 'SH Naturals'],
            ['display_name' => 'SCAR CREAM',          'match_keyword' => null,       'team' => 'SH Naturals'],
            ['display_name' => 'MINI GB',             'match_keyword' => null,       'team' => 'SH Naturals'],
            // Eyecare
            ['display_name' => 'CLEARSIGHT',          'match_keyword' => null,       'team' => 'Eyecare Team'],
            ['display_name' => 'PTERYGIUM',           'match_keyword' => null,       'team' => 'Eyecare Team'],
            ['display_name' => 'GLAUCO FREE',         'match_keyword' => null,       'team' => 'Eyecare Team'],
            ['display_name' => 'LUMIEYES',            'match_keyword' => null,       'team' => 'Eyecare Team'],
            ['display_name' => 'VISIONEX',            'match_keyword' => null,       'team' => 'Eyecare Team'],
            ['display_name' => 'VISION PRO',          'match_keyword' => null,       'team' => 'Eyecare Team'],
        ];

        foreach ($seed as $i => $row) {
            DB::table('products')->insert(array_merge($row, [
                'sort_order' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
```

- [ ] **Step 4: Create the `Product` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'display_name', 'match_keyword', 'team', 'sort_order',
    ];

    /** The string actually used for substring matching against a tag or a
     *  cart's product name — falls back to display_name when no shorter
     *  override keyword is set. Replaces the old PRODUCT_TAG_OVERRIDES concept. */
    public function getEffectiveKeywordAttribute(): string
    {
        return $this->match_keyword ?: $this->display_name;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/ProductSeedTest.php`
Expected: PASS (3 tests, all green)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_06_000000_create_products_table.php app/Models/Product.php tests/Feature/ProductSeedTest.php
git commit -m "feat: add products table seeded from current config/teams.php"
```

---

### Task 2: `ProductManagementController` + routes

**Files:**
- Create: `app/Http/Controllers/ProductManagementController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ProductManagementControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_groups_products_by_team(): void
    {
        $response = $this->get(route('product-management'));

        $response->assertOk();
        $response->assertViewHas('teamGroups');
    }

    public function test_store_creates_a_product(): void
    {
        $response = $this->post(route('product-management.store'), [
            'display_name'  => 'NutriLay',
            'match_keyword' => '',
            'team'          => 'SH Naturals',
        ]);

        $response->assertRedirect(route('product-management'));
        $this->assertDatabaseHas('products', [
            'display_name'  => 'NutriLay',
            'match_keyword' => null,
            'team'          => 'SH Naturals',
        ]);
    }

    public function test_store_rejects_a_team_not_in_config(): void
    {
        $response = $this->post(route('product-management.store'), [
            'display_name' => 'Mystery Product',
            'team'         => 'Not A Real Team',
        ]);

        $response->assertSessionHasErrors('team');
        $this->assertDatabaseMissing('products', ['display_name' => 'Mystery Product']);
    }

    public function test_update_changes_a_product(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();

        $response = $this->put(route('product-management.update', $product), [
            'display_name'  => 'Sinuxyl Nasal Spray',
            'match_keyword' => 'SINUXYL',
            'team'          => 'SH Naturals',
        ]);

        $response->assertRedirect(route('product-management'));
        $this->assertDatabaseHas('products', [
            'id'            => $product->id,
            'display_name'  => 'Sinuxyl Nasal Spray',
            'match_keyword' => 'SINUXYL',
        ]);
    }

    public function test_destroy_removes_a_product(): void
    {
        $product = Product::where('display_name', 'MINI GB')->first();

        $response = $this->delete(route('product-management.destroy', $product));

        $response->assertRedirect(route('product-management'));
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ProductManagementControllerTest.php`
Expected: FAIL — route `product-management` not defined

- [ ] **Step 3: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class ProductManagementController extends Controller
{
    public function index()
    {
        $teamsConfig = config('teams', []);
        $products    = Product::orderBy('sort_order')->get();

        $teamGroups = collect($teamsConfig)->map(function ($team) use ($products) {
            return [
                'name'     => $team['name'],
                'products' => $products->where('team', $team['order_team'])->values(),
            ];
        });

        $unassigned = $products->reject(fn($p) => collect($teamsConfig)->pluck('order_team')->contains($p->team));

        return view('product-management', compact('teamGroups', 'teamsConfig', 'unassigned'));
    }

    public function store(Request $request)
    {
        $data = $this->validateProduct($request);
        $nextSort = (int) (Product::max('sort_order') ?? 0) + 1;

        Product::create([
            'display_name'  => $data['display_name'],
            'match_keyword' => $data['match_keyword'] ?: null,
            'team'          => $data['team'],
            'sort_order'    => $nextSort,
        ]);

        return redirect()->route('product-management')
            ->with('success', "Added \"{$data['display_name']}\".");
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validateProduct($request);

        $product->update([
            'display_name'  => $data['display_name'],
            'match_keyword' => $data['match_keyword'] ?: null,
            'team'          => $data['team'],
        ]);

        return redirect()->route('product-management')
            ->with('success', "Updated \"{$data['display_name']}\".");
    }

    public function destroy(Product $product)
    {
        $name = $product->display_name;
        $product->delete();

        return redirect()->route('product-management')
            ->with('success', "Removed \"{$name}\".");
    }

    private function validateProduct(Request $request): array
    {
        $teamsConfig = config('teams', []);
        $validTeams  = collect($teamsConfig)->pluck('order_team')->all();

        return $request->validate([
            'display_name'  => 'required|string|max:150',
            'match_keyword' => 'nullable|string|max:150',
            'team'          => 'required|string|in:' . implode(',', $validTeams),
        ]);
    }
}
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, add the import near the other controller imports:

```php
use App\Http\Controllers\ProductManagementController;
```

And add these four routes directly below the existing `tsa-management` routes:

```php
Route::get('/product-management',               [ProductManagementController::class, 'index'])->name('product-management');
Route::post('/product-management',               [ProductManagementController::class, 'store'])->name('product-management.store');
Route::put('/product-management/{product}',      [ProductManagementController::class, 'update'])->name('product-management.update');
Route::delete('/product-management/{product}',   [ProductManagementController::class, 'destroy'])->name('product-management.destroy');
```

- [ ] **Step 5: Create a minimal placeholder view so the index test passes**

Create `resources/views/product-management.blade.php` with just enough to render (the full UI is built in Task 3):

```blade
@extends('layouts.app')
@section('title', 'Product Management')
@section('content')
<div>Product Management</div>
@endsection
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/ProductManagementControllerTest.php`
Expected: PASS (5 tests, all green)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/ProductManagementController.php routes/web.php resources/views/product-management.blade.php tests/Feature/ProductManagementControllerTest.php
git commit -m "feat: add ProductManagementController with CRUD routes"
```

---

### Task 3: Full `product-management.blade.php` view + sidebar link

**Files:**
- Modify: `resources/views/product-management.blade.php` (replace placeholder from Task 2)
- Modify: `resources/views/layouts/app.blade.php`

No new automated test in this task — this is presentational HTML mirroring `tsa-management.blade.php`'s structure (already covered by Task 2's controller tests for the underlying data/routes). Verify manually in Step 3.

- [ ] **Step 1: Replace the placeholder view**

Replace the full contents of `resources/views/product-management.blade.php` with:

```blade
@extends('layouts.app')
@section('title', 'Product Management')
@section('subtitle', 'Products and which team each one belongs to')

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
    <div class="px-5 py-4 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">
        @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
    @endif

    <div class="flex items-center justify-between">
        <p class="text-xs text-slate-400 font-mono">Add, edit, or remove products and set which team each belongs to — reflected immediately on TSA Performance and syncing.</p>
        <button type="button" id="addProductBtn"
            class="inline-flex items-center gap-1.5 px-4 py-2 bg-yellow-700 hover:bg-yellow-800 text-white text-xs font-semibold rounded-lg transition-colors cursor-pointer whitespace-nowrap shrink-0 ml-4">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add Product
        </button>
    </div>

    @foreach($teamGroups as $group)
    <div class="bg-white rounded-xl border border-yellow-100 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">{{ $group['name'] }}</h3>
            <p class="text-xs text-slate-500 mt-0.5">{{ $group['products']->count() }} {{ \Illuminate\Support\Str::plural('product', $group['products']->count()) }}</p>
        </div>

        @if($group['products']->isEmpty())
        <div class="py-10 text-center text-sm text-slate-400 font-mono">No products for this team yet</div>
        @else
        <div class="divide-y divide-slate-100">
            @foreach($group['products'] as $product)
            <div class="px-6 py-3 flex items-center gap-4">
                <div class="flex-1">
                    <p class="text-sm font-mono font-semibold text-slate-700">{{ $product->display_name }}</p>
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
        </div>
        @endif
    </div>
    @endforeach

    @if($unassigned->isNotEmpty())
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl px-6 py-4">
        <p class="text-xs font-semibold text-yellow-800 mb-1">Unassigned team</p>
        <p class="text-xs text-yellow-700">{{ $unassigned->pluck('display_name')->join(', ') }} — team value doesn't match a configured team.</p>
    </div>
    @endif

</div>

{{-- Shared Add / Edit modal --}}
<div id="productModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100">
            <h3 id="productModalTitle" class="text-sm font-bold text-slate-800">Add a new product</h3>
            <p class="text-xs text-slate-500 mt-0.5">Recognized starting with the next sync</p>
        </div>
        <form id="productForm" method="POST" action="{{ route('product-management.store') }}" class="px-6 py-5 space-y-4">
            @csrf
            <input type="hidden" name="_method" id="productFormMethod" value="">

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Display name</label>
                <input type="text" id="productNameInput" name="display_name" required
                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Team</label>
                <select name="team" id="productTeamSelect" required
                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    @foreach($teamsConfig as $team)
                    <option value="{{ $team['order_team'] }}">{{ $team['name'] }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">
                    Match keyword <span class="text-slate-400 font-normal">(optional)</span>
                </label>
                <input type="text" name="match_keyword" id="productKeywordInput"
                    placeholder="e.g. CANPRO — only needed if shorter than the display name"
                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                <p class="text-[11px] text-slate-400 mt-1">Leave blank to match on the display name itself.</p>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" id="cancelProductModal" class="px-3 py-2 text-xs font-mono text-slate-600 hover:text-slate-800 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors cursor-pointer">Cancel</button>
                <button type="submit" id="productSubmitBtn" class="px-4 py-2 text-xs font-semibold text-white bg-yellow-700 hover:bg-yellow-800 rounded-lg transition-colors cursor-pointer">Add Product</button>
            </div>
        </form>
    </div>
</div>

<form id="deleteProductForm" method="POST" style="display:none">
    @csrf
    @method('DELETE')
</form>

@push('scripts')
<script>
(function () {
    const modal       = document.getElementById('productModal');
    const modalTitle  = document.getElementById('productModalTitle');
    const form        = document.getElementById('productForm');
    const methodInput = document.getElementById('productFormMethod');
    const nameInput   = document.getElementById('productNameInput');
    const teamSelect  = document.getElementById('productTeamSelect');
    const keywordInput = document.getElementById('productKeywordInput');
    const submitBtn   = document.getElementById('productSubmitBtn');
    const storeUrl    = form.action;

    function openModal() { modal.classList.remove('hidden'); }
    function closeModal() { modal.classList.add('hidden'); }

    function resetForm() {
        form.action = storeUrl;
        methodInput.value = '';
        nameInput.value = '';
        keywordInput.value = '';
        teamSelect.selectedIndex = 0;
        modalTitle.textContent = 'Add a new product';
        submitBtn.textContent = 'Add Product';
    }

    document.getElementById('addProductBtn').addEventListener('click', () => { resetForm(); openModal(); });
    document.getElementById('cancelProductModal').addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

    document.querySelectorAll('.editProductBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            resetForm();
            const id = btn.dataset.id;
            form.action = storeUrl + '/' + id;
            methodInput.value = 'PUT';
            nameInput.value = btn.dataset.displayName || '';
            teamSelect.value = btn.dataset.team || '';
            keywordInput.value = btn.dataset.matchKeyword || '';
            modalTitle.textContent = 'Edit product';
            submitBtn.textContent = 'Save Changes';
            openModal();
        });
    });

    const deleteForm = document.getElementById('deleteProductForm');
    document.querySelectorAll('.deleteProductBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            const name = btn.dataset.name || 'this product';
            if (!confirm(`Remove "${name}"? This can't be undone.`)) return;
            deleteForm.action = storeUrl + '/' + btn.dataset.id;
            deleteForm.submit();
        });
    });
})();
</script>
@endpush
@endsection
```

- [ ] **Step 2: Add the sidebar link**

In `resources/views/layouts/app.blade.php`, insert this new `<a>` block immediately after the closing `</a>` of the "TSA Management" link (before the "Settings" link, around line 199):

```blade
        <a href="{{ route('product-management') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('product-management*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
            </svg>
            Product Management
        </a>
```

- [ ] **Step 3: Manually verify in the browser**

Run: `php artisan serve` (if not already running), then visit `http://127.0.0.1:8000/product-management`
Expected: page loads, shows "SH Naturals" (10 products) and "Eyecare" (6 products) sections, Add Product button opens the modal, editing/deleting a product works and redirects back with a success banner. Sidebar shows "Product Management" between "TSA Management" and "Settings", highlighted when active.

- [ ] **Step 4: Commit**

```bash
git add resources/views/product-management.blade.php resources/views/layouts/app.blade.php
git commit -m "feat: build out Product Management page UI and sidebar link"
```

---

### Task 4: Switch `TsaPerformanceController` to read `Product` instead of config

**Files:**
- Modify: `app/Http/Controllers/TsaPerformanceController.php:1-25` (imports + delete `PRODUCT_TAG_OVERRIDES`), `:80-118` (product list + filter)
- Test: `tests/Feature/TsaPerformanceProductFilterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TsaPerformanceProductFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_products_come_from_the_database_not_config(): void
    {
        Product::create(['display_name' => 'Brand New Product', 'team' => 'SH Naturals', 'sort_order' => 99]);

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals']));

        $response->assertOk();
        $response->assertViewHas('availableProducts', function ($products) {
            return $products->pluck('display_name')->contains('Brand New Product');
        });
    }

    public function test_product_filter_matches_via_the_products_table_match_keyword(): void
    {
        // CANPRO JUICE DRINK's match_keyword is "CANPRO" — an order tagged with the
        // longer real product name should still be found when filtering by it.
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        Order::create([
            'pancake_order_id'   => 'test-canpro-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'raw_tags'           => ['CANPRO JUICE DRINK', strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell'          => false,
            'status_code'        => 1,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $response = $this->get(route('tsa-performance', [
            'team'    => 'sh-naturals',
            'product' => 'CANPRO JUICE DRINK',
            'date'    => now()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertViewHas('totals', fn($totals) => $totals['total'] === 1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/TsaPerformanceProductFilterTest.php`
Expected: FAIL — `availableProducts` is still a plain config array (no `display_name` key), and `product=CANPRO JUICE DRINK` won't resolve to the `CANPRO` keyword yet

- [ ] **Step 3: Update the controller**

In `app/Http/Controllers/TsaPerformanceController.php`, add the import near the top:

```php
use App\Models\Product;
```

Delete the `PRODUCT_TAG_OVERRIDES` const entirely (lines 17-25):

```php
    /** Some products in config/teams.php are tagged in Pancake with a shorter word
     *  than their full display name — e.g. "CANPRO JUICE DRINK" shows up in raw_tags
     *  as just "Call In Progress - CANPRO", and "GINSENG SERUM" as "...(Ginseng)".
     *  A tag that's shorter than the product name can never contain it as a
     *  substring, so the product filter needs this override to actually match. */
    private const PRODUCT_TAG_OVERRIDES = [
        'CANPRO JUICE DRINK' => 'CANPRO',
        'GINSENG SERUM'      => 'GINSENG',
    ];

```

Replace the `$availableProducts` line and the product-filter block:

```php
        // Per-product toggle (the sheets split each team's report into one tab per
        // pack/product — this mirrors that). 'all' means no filtering, same as before.
        $availableProducts = $teamsConfig[$selectedTeam]['products'] ?? [];
        $selectedProduct   = request('product', 'all');

        if ($selectedProduct !== 'all' && !in_array($selectedProduct, $availableProducts, true)) {
            $selectedProduct = 'all';
        }
```

with:

```php
        // Per-product toggle (the sheets split each team's report into one tab per
        // pack/product — this mirrors that). 'all' means no filtering, same as before.
        // Sourced from the products table (Product Management page) instead of
        // config/teams.php — see docs/superpowers/specs/2026-07-06-product-management-design.md.
        $availableProducts = Product::where('team', $teamsConfig[$selectedTeam]['order_team'])
            ->orderBy('sort_order')->get();
        $selectedProduct   = request('product', 'all');

        $selectedProductModel = $availableProducts->firstWhere('display_name', $selectedProduct);
        if ($selectedProduct !== 'all' && !$selectedProductModel) {
            $selectedProduct = 'all';
        }
```

And replace the filter block:

```php
        if ($selectedProduct !== 'all') {
            $matchKeyword = self::PRODUCT_TAG_OVERRIDES[$selectedProduct] ?? $selectedProduct;
            $orders = $orders->filter(function ($order) use ($matchKeyword) {
                foreach ($order->raw_tags ?? [] as $tag) {
                    if (stripos($tag, $matchKeyword) !== false) return true;
                }
                return false;
            })->values();
        }
```

with:

```php
        if ($selectedProduct !== 'all') {
            $matchKeyword = $selectedProductModel->effective_keyword;
            $orders = $orders->filter(function ($order) use ($matchKeyword) {
                foreach ($order->raw_tags ?? [] as $tag) {
                    if (stripos($tag, $matchKeyword) !== false) return true;
                }
                return false;
            })->values();
        }
```

Finally, update the Blade view's product dropdown to use `display_name` instead of a plain string. In `resources/views/tsa-performance.blade.php`, find the product dropdown loop (`@foreach($availableProducts as $product)`) and change:

```blade
            <button type="submit" name="product" value="{{ $product }}" role="option" aria-selected="{{ $selectedProduct === $product ? 'true' : 'false' }}"
                    class="w-full text-left px-4 py-2 text-xs font-mono transition-colors cursor-pointer border-t border-slate-100
                           {{ $selectedProduct === $product ? 'bg-slate-700 text-white font-semibold' : 'text-slate-600 hover:bg-slate-50' }}">
                {{ $product }}
            </button>
```

to:

```blade
            <button type="submit" name="product" value="{{ $product->display_name }}" role="option" aria-selected="{{ $selectedProduct === $product->display_name ? 'true' : 'false' }}"
                    class="w-full text-left px-4 py-2 text-xs font-mono transition-colors cursor-pointer border-t border-slate-100
                           {{ $selectedProduct === $product->display_name ? 'bg-slate-700 text-white font-semibold' : 'text-slate-600 hover:bg-slate-50' }}">
                {{ $product->display_name }}
            </button>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/TsaPerformanceProductFilterTest.php`
Expected: PASS (2 tests, both green)

- [ ] **Step 5: Run the full test suite to check for regressions**

Run: `php artisan test`
Expected: all tests pass, including the pre-existing `SyncTodayOrdersBacklogTest` and `SyncTodayOrdersWorkedAtTest`

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/TsaPerformanceController.php resources/views/tsa-performance.blade.php tests/Feature/TsaPerformanceProductFilterTest.php
git commit -m "refactor: TsaPerformanceController reads products from the database"
```

---

### Task 5: Switch `SyncTodayOrders::inferTeamFromProduct()` to read `Product` instead of config

**Files:**
- Modify: `app/Console/Commands/SyncTodayOrders.php:395-459`
- Test: `tests/Feature/SyncTodayOrdersProductTeamInferenceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncTodayOrdersProductTeamInferenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression test for the Fix #15 team-inference fallback, now reading from
     * the products table instead of config/teams.php. A newly-added product (via
     * Product Management) with a custom match_keyword must be picked up on the
     * very next sync, with no code change.
     */
    public function test_new_product_added_via_product_management_is_recognized_immediately(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        // Simulates adding a brand-new product through the Product Management UI —
        // no TSA/seller match exists for this lead, only the product in its cart.
        Product::create([
            'display_name'  => 'Nutrilay Herbal Blend',
            'match_keyword' => 'NUTRILAY',
            'team'          => 'SH Naturals',
            'sort_order'    => 99,
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::sequence()
                ->push([
                    'data' => [[
                        'id' => 9999001,
                        'status' => 0,
                        'inserted_at' => '2026-07-05T10:00:00',
                        'updated_at'  => '2026-07-05T10:00:00',
                        'tags' => [],
                        'items' => [[
                            'quantity' => 1,
                            'product_name' => 'Nutrilay Herbal Blend',
                            'variation_info' => ['name' => 'Nutrilay Herbal Blend', 'retail_price' => 500],
                        ]],
                        'histories' => [],
                    ]],
                ])
                ->push(['data' => []]),
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-07-05']);

        $order = Order::where('pancake_order_id', '9999001')->first();

        $this->assertNotNull($order);
        $this->assertNull($order->tsa_name, 'Nobody claimed this lead, so no TSA name should be invented');
        $this->assertSame('SH Naturals', $order->team);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/SyncTodayOrdersProductTeamInferenceTest.php`
Expected: FAIL — `$order->team` is `null` (the product isn't in `config('teams')`, so `inferTeamFromProduct()` doesn't find it yet)

- [ ] **Step 3: Update `SyncTodayOrders`**

Add the import near the top of `app/Console/Commands/SyncTodayOrders.php`:

```php
use App\Models\Product;
```

Delete the `PRODUCT_TAG_OVERRIDES` const (the block just before `extractTsaInfo()`):

```php
    /** Mirrors TsaPerformanceController::PRODUCT_TAG_OVERRIDES — a product's actual
     *  name (e.g. "CanPro Guyabano Herbal Drink") can be longer/differently worded than
     *  its config/teams.php product-list entry ("CANPRO JUICE DRINK"); this maps the
     *  config entry down to the short root both forms actually share. Keep both
     *  overrides lists in sync if either changes. */
    private const PRODUCT_TAG_OVERRIDES = [
        'CANPRO JUICE DRINK' => 'CANPRO',
        'GINSENG SERUM'      => 'GINSENG',
    ];

```

Replace `inferTeamFromProduct()`:

```php
    private function inferTeamFromProduct(?string $productName): ?string
    {
        if (!$productName) return null;

        foreach (config('teams', []) as $team) {
            foreach ($team['products'] ?? [] as $product) {
                $keyword = self::PRODUCT_TAG_OVERRIDES[$product] ?? $product;
                if (stripos($productName, $keyword) !== false) {
                    return $team['order_team'];
                }
            }
        }

        return null;
    }
```

with:

```php
    private function inferTeamFromProduct(?string $productName): ?string
    {
        if (!$productName) return null;

        // Sourced from the products table (Product Management page) instead of
        // config/teams.php — see docs/superpowers/specs/2026-07-06-product-management-design.md.
        foreach (Product::all() as $product) {
            if (stripos($productName, $product->effective_keyword) !== false) {
                return $product->team;
            }
        }

        return null;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/SyncTodayOrdersProductTeamInferenceTest.php`
Expected: PASS

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: all tests pass (including `ProductSeedTest`, `ProductManagementControllerTest`, `TsaPerformanceProductFilterTest`, both pre-existing `SyncTodayOrders*` tests, and this new one)

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/SyncTodayOrders.php tests/Feature/SyncTodayOrdersProductTeamInferenceTest.php
git commit -m "refactor: SyncTodayOrders infers team from the products table"
```

---

### Task 6: Verify production data is unaffected

**Files:** none (verification only)

- [ ] **Step 1: Run the migration against the real database**

Run: `php artisan migrate`
Expected: `2026_07_06_000000_create_products_table` migrates successfully, inserting 16 rows.

- [ ] **Step 2: Confirm the seeded products match what was in config/teams.php**

Run: `php artisan tinker --execute="echo App\Models\Product::count();"`
Expected: `16`

- [ ] **Step 3: Re-check the two numbers this whole effort was built around**

Visit `http://127.0.0.1:8000/tsa-performance?team=sh-naturals&product=all&date=2026-07-05` and `http://127.0.0.1:8000/tsa-performance?team=eyecare&product=all&date=2026-07-05`.
Expected: Grand Totals are unchanged from before this feature shipped — **SH Naturals: 131, Eyecare: 152** (same numbers confirmed at the end of the earlier debugging session).

- [ ] **Step 4: Remove the now-unused `products` arrays from `config/teams.php`**

```php
<?php

/*
 * Each team entry:
 *   name       — display name
 *   order_team — literal string stored in orders.team / tsa_shifts.team (legacy sync
 *                writes this directly, not the slug key below — see SyncTodayOrders)
 *
 * Products are managed via the Product Management page (see app/Models/Product.php),
 * not here — this file only defines the teams themselves.
 */
return [
    'sh-naturals' => [
        'name'       => 'SH Naturals',
        'order_team' => 'SH Naturals',
    ],

    'eyecare' => [
        'name'       => 'Eyecare',
        'order_team' => 'Eyecare Team',
    ],
];
```

- [ ] **Step 5: Run the full test suite one final time**

Run: `php artisan test`
Expected: all tests pass — nothing else reads `config('teams')[...]['products']` anymore (confirm with `grep -rn "\['products'\]" app/` returning no results).

- [ ] **Step 6: Commit**

```bash
git add config/teams.php
git commit -m "chore: remove now-unused products arrays from config/teams.php"
```
