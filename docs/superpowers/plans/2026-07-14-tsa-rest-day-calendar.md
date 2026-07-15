# TSA Rest Day Calendar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a rest-day calendar to the TSA Management page (recurring weekly day off per TSA + one-off date exceptions), and a "Day Off" banner on TSA Performance when viewing a rest day — both purely additive, no change to any existing calculation.

**Architecture:** A rule-plus-exception data model — one nullable `rest_day_of_week` column on `tsa_shifts` for the recurring pattern, plus a new `tsa_rest_days` table holding only actual deviations from that pattern (both additions and overrides). `TsaShift::isOffOn(Carbon $date): bool` is the single source of truth every other piece reads from. The calendar UI reuses this app's existing modal + form-POST-and-redirect pattern (no new AJAX machinery); the TSA Performance banner is a read-only display with zero effect on any computed metric.

**Tech Stack:** Laravel 12, Blade + Tailwind (existing conventions), PHPUnit via `php artisan test`.

Full design context: `docs/superpowers/specs/2026-07-14-tsa-rest-day-calendar-design.md`.

---

## File Structure

- **Create:** `database/migrations/2026_07_14_160000_add_rest_day_of_week_to_tsa_shifts_table.php`
- **Create:** `database/migrations/2026_07_14_160100_create_tsa_rest_days_table.php`
- **Create:** `app/Models/TsaRestDay.php`
- **Modify:** `app/Models/TsaShift.php` — add `rest_day_of_week` to `$fillable`, add `restDays()` relationship and `isOffOn()` method
- **Modify:** `app/Http/Controllers/TsaManagementController.php` — calendar data, save-rest-days endpoint, `rest_day_of_week` in validate/store/update
- **Modify:** `routes/web.php` — new route for saving a date's rest days
- **Modify:** `resources/views/tsa-management.blade.php` — two-column layout, calendar grid, rest-day modal, "Rest day" field in the existing Add/Edit modal
- **Modify:** `app/Http/Controllers/TsaPerformanceController.php` — compute `$isRestDay` in `showTsa()`
- **Modify:** `resources/views/tsa-performance-individual.blade.php` — "Day Off" banner
- **Create:** `tests/Feature/TsaShiftIsOffOnTest.php`
- **Create:** `tests/Feature/TsaManagementControllerTest.php` (no test file exists for this controller today)
- **Create:** `tests/Feature/TsaPerformanceRestDayBannerTest.php`

---

## Task 1: Data model — migrations, `TsaRestDay` model, `TsaShift` additions

**Files:**
- Create: `database/migrations/2026_07_14_160000_add_rest_day_of_week_to_tsa_shifts_table.php`
- Create: `database/migrations/2026_07_14_160100_create_tsa_rest_days_table.php`
- Create: `app/Models/TsaRestDay.php`
- Modify: `app/Models/TsaShift.php`
- Test: `tests/Feature/TsaShiftIsOffOnTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/TsaShiftIsOffOnTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\TsaRestDay;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TsaShiftIsOffOnTest extends TestCase
{
    use RefreshDatabase;

    public function test_not_off_when_no_recurring_day_and_no_override(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();

        $this->assertFalse($julie->isOffOn(Carbon::parse('next sunday')));
    }

    public function test_off_when_date_matches_recurring_rest_day(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $julie->refresh();

        $this->assertTrue($julie->isOffOn(Carbon::parse('next sunday')));
    }

    public function test_not_off_when_date_does_not_match_recurring_rest_day(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $julie->refresh();

        $monday = Carbon::parse('next sunday')->addDay();
        $this->assertFalse($julie->isOffOn($monday));
    }

    public function test_off_on_an_explicit_extra_day_off_with_no_recurring_rule(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $monday = Carbon::parse('next monday');
        TsaRestDay::create(['tsa_shift_id' => $julie->id, 'date' => $monday->toDateString(), 'is_off' => true]);
        $julie->refresh();

        $this->assertTrue($julie->isOffOn($monday));
    }

    public function test_not_off_when_explicit_override_cancels_the_recurring_rest_day(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $sunday = Carbon::parse('next sunday');
        TsaRestDay::create(['tsa_shift_id' => $julie->id, 'date' => $sunday->toDateString(), 'is_off' => false]);
        $julie->refresh();

        $this->assertFalse($julie->isOffOn($sunday));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=TsaShiftIsOffOnTest`
Expected: **FAIL** — `rest_day_of_week` column doesn't exist, `TsaRestDay` class doesn't exist, `isOffOn()` method doesn't exist.

- [ ] **Step 3: Create the migrations**

Create `database/migrations/2026_07_14_160000_add_rest_day_of_week_to_tsa_shifts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            // Lowercase full day name ("sunday".."saturday"), or null = no recurring
            // rest day. One-off exceptions (extra days off, or working through the
            // usual rest day) live in tsa_rest_days instead — see that table's comment.
            $table->string('rest_day_of_week')->nullable()->after('seller_keywords');
        });
    }

    public function down(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->dropColumn('rest_day_of_week');
        });
    }
};
```

Create `database/migrations/2026_07_14_160100_create_tsa_rest_days_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tsa_rest_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tsa_shift_id')->constrained('tsa_shifts')->cascadeOnDelete();
            $table->date('date');
            // true = an extra day off not implied by rest_day_of_week.
            // false = an explicit override: working despite the usual rest day.
            // A row only ever exists here when the date's actual status differs from
            // what rest_day_of_week alone would produce — see TsaShift::isOffOn().
            $table->boolean('is_off');
            $table->timestamps();

            $table->unique(['tsa_shift_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tsa_rest_days');
    }
};
```

- [ ] **Step 4: Create the `TsaRestDay` model**

Create `app/Models/TsaRestDay.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TsaRestDay extends Model
{
    protected $fillable = ['tsa_shift_id', 'date', 'is_off'];

    protected $casts = [
        'date'   => 'date',
        'is_off' => 'boolean',
    ];

    public function tsaShift()
    {
        return $this->belongsTo(TsaShift::class);
    }
}
```

- [ ] **Step 5: Add `rest_day_of_week`, `restDays()`, and `isOffOn()` to `TsaShift`**

In `app/Models/TsaShift.php`, change the `$fillable` array (currently lines 9-12) to:

```php
    protected $fillable = [
        'tsa_key', 'pos_user_id', 'display_name', 'team', 'tag_keywords', 'seller_keywords',
        'shift_start', 'shift_end', 'sort_order', 'rest_day_of_week',
    ];
```

Add these two methods to the class (after `getExtraTagKeywordsAttribute()` and before the closing `splitKeywords()` private method, or anywhere else in the class body — placement within the class doesn't matter):

```php
    public function restDays()
    {
        return $this->hasMany(TsaRestDay::class);
    }

    /**
     * Whether this TSA is off on $date. An explicit tsa_rest_days row (either an
     * extra day off, or an override back to working) always wins over the
     * recurring rule; otherwise falls back to whether $date's weekday matches
     * rest_day_of_week.
     *
     * Deliberately does NOT use Collection::firstWhere('date', ...) — the `date`
     * attribute is Carbon-cast, and firstWhere's loose `==` comparison against a
     * plain date string compares Carbon's default __toString() ("Y-m-d H:i:s")
     * against a "Y-m-d" string, which never matches. Compares toDateString()
     * explicitly instead.
     */
    public function isOffOn(\Illuminate\Support\Carbon $date): bool
    {
        $override = $this->restDays->first(
            fn (TsaRestDay $r) => $r->date->toDateString() === $date->toDateString()
        );

        if ($override !== null) {
            return $override->is_off;
        }

        return $this->rest_day_of_week !== null
            && strtolower($date->format('l')) === $this->rest_day_of_week;
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=TsaShiftIsOffOnTest`
Expected: **PASS** — 5 tests.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_14_160000_add_rest_day_of_week_to_tsa_shifts_table.php \
        database/migrations/2026_07_14_160100_create_tsa_rest_days_table.php \
        app/Models/TsaRestDay.php app/Models/TsaShift.php \
        tests/Feature/TsaShiftIsOffOnTest.php
git commit -m "feat: add rest-day data model (recurring rule + date exceptions)

tsa_shifts.rest_day_of_week holds the recurring weekly day off; the
new tsa_rest_days table holds only actual exceptions to that rule
(extra days off, or overrides back to working). TsaShift::isOffOn()
is the single resolution point every other feature reads from."
```

---

## Task 2: Calendar data on `TsaManagementController::index()`

**Files:**
- Modify: `app/Http/Controllers/TsaManagementController.php`
- Test: `tests/Feature/TsaManagementControllerTest.php` (new file)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TsaManagementControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TsaManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_index_passes_a_calendar_for_the_requested_month(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);

        $sunday = Carbon::parse('next sunday');

        $response = $this->get(route('tsa-management', ['month' => $sunday->format('Y-m')]));

        $response->assertOk();
        $response->assertViewHas('calendar');

        $calendar = $response->viewData('calendar');
        $this->assertSame($sunday->format('F Y'), $calendar['month_label']);
        $this->assertCount($sunday->daysInMonth, $calendar['days']);

        $sundayEntry = collect($calendar['days'])->firstWhere('date', $sunday->toDateString());
        $this->assertNotNull($sundayEntry);
        $this->assertTrue($sundayEntry['off_tsas']->contains('tsa_key', 'Julie'));
    }

    public function test_index_defaults_to_the_current_month_with_no_month_param(): void
    {
        $response = $this->get(route('tsa-management'));

        $response->assertOk();
        $calendar = $response->viewData('calendar');
        $this->assertSame(now('Asia/Manila')->format('F Y'), $calendar['month_label']);
    }

    public function test_index_falls_back_to_the_current_month_for_an_invalid_month_param(): void
    {
        $response = $this->get(route('tsa-management', ['month' => 'not-a-month']));

        $response->assertOk();
        $calendar = $response->viewData('calendar');
        $this->assertSame(now('Asia/Manila')->format('F Y'), $calendar['month_label']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=TsaManagementControllerTest`
Expected: **FAIL** — `assertViewHas('calendar')` fails, `calendar` key doesn't exist in the view data.

- [ ] **Step 3: Add calendar-building to the controller**

In `app/Http/Controllers/TsaManagementController.php`, add the import:

```php
use App\Models\Setting;
use App\Models\TsaRestDay;
use App\Models\TsaShift;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
```

Replace the `index()` method (currently lines 14-32) with:

```php
    public function index()
    {
        $teamsConfig = config('teams', []);
        $shifts      = TsaShift::with('restDays')->orderBy('sort_order')->get();

        // Group TSAs by team for display, using each configured team's real
        // 'order_team' string (e.g. "SH Naturals") as the grouping key.
        $teamGroups = collect($teamsConfig)->map(function ($team) use ($shifts) {
            return [
                'name'  => $team['name'],
                'shifts' => $shifts->where('team', $team['order_team'])->values(),
            ];
        });

        // Any TSA whose team doesn't match a configured team (data issue / manual edit)
        $unassigned = $shifts->reject(fn($s) => collect($teamsConfig)->pluck('order_team')->contains($s->team));

        $calendar = $this->buildCalendar($shifts, request('month'));

        return view('tsa-management', compact('teamGroups', 'teamsConfig', 'unassigned', 'calendar', 'shifts'));
    }

    /**
     * Builds the month calendar grid for the rest-day sidebar: which TSAs are off on
     * each date of the requested (or current) month. Falls back to the current month
     * for a missing/invalid ?month= value rather than erroring.
     */
    private function buildCalendar(Collection $shifts, ?string $monthParam): array
    {
        $month = null;
        if ($monthParam) {
            try {
                $month = Carbon::createFromFormat('Y-m', $monthParam)->startOfMonth();
            } catch (\Throwable $e) {
                $month = null;
            }
        }
        $month ??= now('Asia/Manila')->startOfMonth();

        $days   = [];
        $cursor = $month->copy();
        while ($cursor->month === $month->month) {
            $offTsas = $shifts
                ->filter(fn($shift) => $shift->isOffOn($cursor))
                ->map(fn($shift) => ['tsa_key' => $shift->tsa_key, 'initials' => strtoupper(substr($shift->display_name, 0, 2))])
                ->values();

            $days[] = [
                'date'     => $cursor->toDateString(),
                'day'      => $cursor->day,
                'off_tsas' => $offTsas,
            ];
            $cursor->addDay();
        }

        return [
            'month_label'    => $month->format('F Y'),
            'prev_month'     => $month->copy()->subMonthNoOverflow()->format('Y-m'),
            'next_month'     => $month->copy()->addMonthNoOverflow()->format('Y-m'),
            'leading_blanks' => $month->copy()->startOfMonth()->dayOfWeek,
            'days'           => $days,
        ];
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=TsaManagementControllerTest`
Expected: **PASS** — 3 tests.

- [ ] **Step 5: Run the full test suite to check for regressions**

Run: `php artisan test`
Expected: **PASS**, no regressions.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/TsaManagementController.php tests/Feature/TsaManagementControllerTest.php
git commit -m "feat: build month calendar data on TSA Management's index()

No UI yet — this wires the controller side (which TSAs are off on
each date of the requested/current month) so the view can be built
and tested against it independently."
```

---

## Task 3: Calendar UI in the view

**Files:**
- Modify: `resources/views/tsa-management.blade.php`
- Test: `tests/Feature/TsaManagementControllerTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TsaManagementControllerTest.php` (inside the existing class):

```php
    public function test_calendar_cell_lists_the_tsa_key_off_on_that_date(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $sunday = Carbon::parse('next sunday');

        $response = $this->get(route('tsa-management', ['month' => $sunday->format('Y-m')]));

        $response->assertOk();
        $response->assertSee('data-date="' . $sunday->toDateString() . '" data-off="Julie"', false);
    }

    public function test_calendar_cell_has_no_off_tsas_on_a_normal_working_day(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $monday = Carbon::parse('next sunday')->addDay();

        $response = $this->get(route('tsa-management', ['month' => $monday->format('Y-m')]));

        $response->assertOk();
        $response->assertSee('data-date="' . $monday->toDateString() . '" data-off=""', false);
    }

    public function test_calendar_month_navigation_links_are_present(): void
    {
        $month = Carbon::parse('next sunday')->format('Y-m');

        $response = $this->get(route('tsa-management', ['month' => $month]));

        $response->assertOk();
        $response->assertSee('month=' . Carbon::createFromFormat('Y-m', $month)->subMonthNoOverflow()->format('Y-m'), false);
        $response->assertSee('month=' . Carbon::createFromFormat('Y-m', $month)->addMonthNoOverflow()->format('Y-m'), false);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=TsaManagementControllerTest`
Expected: the 3 new tests **FAIL** — the calendar markup doesn't exist in the view yet, so none of the `data-date`/`month=` strings appear.

- [ ] **Step 3: Restructure the page into two columns and add the calendar card**

In `resources/views/tsa-management.blade.php`, change the opening wrapper (currently line 6, `<div class="max-w-3xl space-y-6">`) to:

```blade
<div class="max-w-6xl">
<div class="flex flex-col lg:flex-row gap-6 items-start">
<div class="flex-1 min-w-0 space-y-6">
```

This opens two new wrapping `<div>`s around all the existing content (the success/error banners, the header, the roster `<form>`, the unassigned-team banner, and the save-button bar). Do **not** touch any of that existing content — only the opening tags change.

Immediately after the existing roster `<form>...</form>` closes (currently line 138, `</form>`) and before the existing top-level closing `</div>` (currently line 140), close the two new wrapper divs and add the calendar card:

```blade
</div>

<div class="w-full lg:w-80 shrink-0">
    <div class="bg-white rounded-xl border border-yellow-100 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <a href="{{ route('tsa-management', ['month' => $calendar['prev_month']]) }}"
               class="p-1.5 rounded-lg text-slate-400 hover:text-yellow-600 hover:bg-yellow-50 transition-colors cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <h3 class="text-sm font-semibold text-slate-800">{{ $calendar['month_label'] }}</h3>
            <a href="{{ route('tsa-management', ['month' => $calendar['next_month']]) }}"
               class="p-1.5 rounded-lg text-slate-400 hover:text-yellow-600 hover:bg-yellow-50 transition-colors cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
        </div>
        <div class="p-4">
            <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-mono text-slate-400 mb-2">
                <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
            </div>
            <div class="grid grid-cols-7 gap-1">
                @for($i = 0; $i < $calendar['leading_blanks']; $i++)
                <div></div>
                @endfor
                @foreach($calendar['days'] as $dayData)
                <button type="button" class="restDayCell aspect-square rounded-lg border border-slate-100 hover:border-yellow-300 hover:bg-yellow-50 transition-colors cursor-pointer p-1 flex flex-col items-center justify-start"
                    data-date="{{ $dayData['date'] }}" data-off="{{ $dayData['off_tsas']->pluck('tsa_key')->join(',') }}">
                    <span class="text-[11px] font-mono text-slate-500">{{ $dayData['day'] }}</span>
                    @if($dayData['off_tsas']->isNotEmpty())
                    <span class="text-[9px] font-mono text-yellow-700 leading-tight text-center">
                        {{ $dayData['off_tsas']->pluck('initials')->join(' ') }}
                    </span>
                    @endif
                </button>
                @endforeach
            </div>
        </div>
    </div>
</div>

</div>
</div>
```

The net effect: the roster form and the calendar card sit side by side (`flex-row`) on large screens and stack (`flex-col`) below `lg`, matching this app's existing mobile-first responsive conventions.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=TsaManagementControllerTest`
Expected: **PASS** — 6 tests (3 from Task 2, 3 new).

- [ ] **Step 5: Run the full test suite to check for regressions**

Run: `php artisan test`
Expected: **PASS**, no regressions. Pay attention to any test that asserts on the exact structure of `tsa-management.blade.php`'s content — none are known to exist today, but confirm.

- [ ] **Step 6: Commit**

```bash
git add resources/views/tsa-management.blade.php tests/Feature/TsaManagementControllerTest.php
git commit -m "feat: render the rest-day calendar on TSA Management

Read-only for now — the page shows a month grid with each TSA's
initials on the dates they're off, plus month navigation. Clicking a
date to edit it is the next task."
```

---

## Task 4: Save rest days for a date (click-to-edit)

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/TsaManagementController.php`
- Modify: `resources/views/tsa-management.blade.php`
- Test: `tests/Feature/TsaManagementControllerTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TsaManagementControllerTest.php`:

```php
    public function test_save_rest_days_creates_an_override_for_a_non_recurring_extra_day_off(): void
    {
        $marisol = TsaShift::where('tsa_key', 'Marisol')->first();
        $monday  = Carbon::parse('next monday');

        $response = $this->post(route('tsa-management.rest-days', $monday->toDateString()), [
            'tsas' => ['Marisol'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tsa_rest_days', [
            'tsa_shift_id' => $marisol->id,
            'date'         => $monday->toDateString(),
            'is_off'       => true,
        ]);
    }

    public function test_save_rest_days_does_not_create_a_row_when_it_matches_the_recurring_default(): void
    {
        $julie  = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $sunday = Carbon::parse('next sunday');

        $response = $this->post(route('tsa-management.rest-days', $sunday->toDateString()), [
            'tsas' => ['Julie'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseMissing('tsa_rest_days', ['tsa_shift_id' => $julie->id, 'date' => $sunday->toDateString()]);
    }

    public function test_save_rest_days_creates_an_override_when_unchecking_a_recurring_rest_day(): void
    {
        $julie  = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $sunday = Carbon::parse('next sunday');

        $response = $this->post(route('tsa-management.rest-days', $sunday->toDateString()), [
            'tsas' => [], // Julie unchecked despite her recurring Sunday off
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tsa_rest_days', [
            'tsa_shift_id' => $julie->id,
            'date'         => $sunday->toDateString(),
            'is_off'       => false,
        ]);
    }

    public function test_save_rest_days_deletes_a_stale_override_that_now_matches_the_recurring_default(): void
    {
        $julie  = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $sunday = Carbon::parse('next sunday');
        \App\Models\TsaRestDay::create(['tsa_shift_id' => $julie->id, 'date' => $sunday->toDateString(), 'is_off' => false]);

        $response = $this->post(route('tsa-management.rest-days', $sunday->toDateString()), [
            'tsas' => ['Julie'], // checked again, matches the recurring default now
        ]);

        $response->assertRedirect();
        $this->assertDatabaseMissing('tsa_rest_days', ['tsa_shift_id' => $julie->id, 'date' => $sunday->toDateString()]);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=TsaManagementControllerTest`
Expected: the 4 new tests **FAIL** — the `tsa-management.rest-days` named route doesn't exist yet.

- [ ] **Step 3: Add the route**

In `routes/web.php`, add this line immediately after the existing `Route::delete('/tsa-management/{tsaShift}', ...)` line (inside the same `auth` middleware group):

```php
    Route::post('/tsa-management/rest-days/{date}', [TsaManagementController::class, 'saveRestDays'])->name('tsa-management.rest-days');
```

- [ ] **Step 4: Add `saveRestDays()` to the controller**

In `app/Http/Controllers/TsaManagementController.php`, add this public method (e.g. after `destroy()`):

```php
    /**
     * Persists which TSAs are off on $date. Only writes a tsa_rest_days row when
     * the submitted state actually differs from what rest_day_of_week alone would
     * produce — matching or removed rows are deleted so the table only ever holds
     * genuine exceptions (see the migration's comment on tsa_rest_days.is_off).
     */
    public function saveRestDays(Request $request, string $date)
    {
        $parsedDate  = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        $checkedKeys = $request->input('tsas', []);
        $shifts      = TsaShift::with('restDays')->get();

        foreach ($shifts as $shift) {
            $defaultOff = $shift->rest_day_of_week !== null
                && strtolower($parsedDate->format('l')) === $shift->rest_day_of_week;
            $desiredOff = in_array($shift->tsa_key, $checkedKeys, true);

            $existing = $shift->restDays->first(
                fn (TsaRestDay $r) => $r->date->toDateString() === $parsedDate->toDateString()
            );

            if ($desiredOff === $defaultOff) {
                $existing?->delete();
            } elseif ($existing) {
                $existing->update(['is_off' => $desiredOff]);
            } else {
                TsaRestDay::create([
                    'tsa_shift_id' => $shift->id,
                    'date'         => $parsedDate->toDateString(),
                    'is_off'       => $desiredOff,
                ]);
            }
        }

        return redirect()->route('tsa-management', ['month' => $parsedDate->format('Y-m')])
            ->with('success', "Updated rest days for {$parsedDate->format('M j, Y')}.");
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=TsaManagementControllerTest`
Expected: **PASS** — 10 tests (6 from Tasks 2-3, 4 new).

- [ ] **Step 6: Wire the click-to-edit modal in the view**

In `resources/views/tsa-management.blade.php`, add a new modal after the existing `{{-- Standalone delete form ... --}}` block (currently ends around line 197) and before `@push('scripts')`:

```blade
{{-- Rest Day modal — a separate modal instance from #tsaModal above, toggled the
     same way (hidden class + click handlers), so editing a date's rest days doesn't
     share state with the Add/Edit TSA modal. --}}
<div id="restDayModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100">
            <h3 id="restDayModalTitle" class="text-sm font-bold text-slate-800">Rest days</h3>
        </div>
        <form id="restDayForm" method="POST" class="px-6 py-5 space-y-3">
            @csrf
            @foreach($shifts as $shift)
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="tsas[]" value="{{ $shift->tsa_key }}" class="restDayCheckbox" data-tsa-key="{{ $shift->tsa_key }}">
                {{ $shift->display_name }}
            </label>
            @endforeach
            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" id="cancelRestDayModal" class="px-3 py-2 text-xs font-mono text-slate-600 hover:text-slate-800 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-2 text-xs font-semibold text-white bg-yellow-700 hover:bg-yellow-800 rounded-lg transition-colors cursor-pointer">Save</button>
            </div>
        </form>
    </div>
</div>
```

Inside the existing `@push('scripts')` block, add this as a second, separate IIFE right after the existing `(function () { ... })();` block (do not modify the existing script — add this after it, still inside the same `@push('scripts')` ... `@endpush`):

```blade
<script>
(function () {
    const restDayModal      = document.getElementById('restDayModal');
    const restDayForm       = document.getElementById('restDayForm');
    const restDayModalTitle = document.getElementById('restDayModalTitle');

    document.querySelectorAll('.restDayCell').forEach(cell => {
        cell.addEventListener('click', () => {
            const date    = cell.dataset.date;
            const offKeys = cell.dataset.off ? cell.dataset.off.split(',') : [];

            document.querySelectorAll('.restDayCheckbox').forEach(cb => {
                cb.checked = offKeys.includes(cb.dataset.tsaKey);
            });

            restDayForm.action = `{{ url('/tsa-management/rest-days') }}/${date}`;
            restDayModalTitle.textContent = 'Rest days — ' + new Date(date + 'T00:00:00')
                .toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
            restDayModal.classList.remove('hidden');
        });
    });

    document.getElementById('cancelRestDayModal').addEventListener('click', () => {
        restDayModal.classList.add('hidden');
    });
    restDayModal.addEventListener('click', (e) => {
        if (e.target === restDayModal) restDayModal.classList.add('hidden');
    });
})();
</script>
```

- [ ] **Step 7: Run the full test suite to check for regressions**

Run: `php artisan test`
Expected: **PASS**, no regressions.

- [ ] **Step 8: Commit**

```bash
git add routes/web.php app/Http/Controllers/TsaManagementController.php resources/views/tsa-management.blade.php tests/Feature/TsaManagementControllerTest.php
git commit -m "feat: make the rest-day calendar editable

Clicking a date opens a modal (same mechanism as the existing
Add/Edit TSA modal) with a checkbox per TSA. Saving only ever writes
a tsa_rest_days row when the result actually differs from what the
recurring rest_day_of_week rule alone would produce."
```

---

## Task 5: Recurring day-off field on the TSA Edit modal

**Files:**
- Modify: `app/Http/Controllers/TsaManagementController.php`
- Modify: `resources/views/tsa-management.blade.php`
- Test: `tests/Feature/TsaManagementControllerTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TsaManagementControllerTest.php`:

```php
    public function test_store_persists_rest_day_of_week(): void
    {
        $response = $this->post(route('tsa-management.store'), [
            'display_name'     => 'New TSA',
            'team'              => 'SH Naturals',
            'rest_day_of_week'  => 'monday',
        ]);

        $response->assertRedirect(route('tsa-management'));
        $this->assertDatabaseHas('tsa_shifts', ['display_name' => 'New TSA', 'rest_day_of_week' => 'monday']);
    }

    public function test_update_can_clear_rest_day_of_week(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);

        $response = $this->put(route('tsa-management.update', $julie), [
            'display_name' => $julie->display_name,
            'team'          => $julie->team,
            // rest_day_of_week omitted entirely -> should clear back to null
        ]);

        $response->assertRedirect(route('tsa-management'));
        $this->assertDatabaseHas('tsa_shifts', ['id' => $julie->id, 'rest_day_of_week' => null]);
    }

    public function test_store_rejects_an_invalid_rest_day_value(): void
    {
        $response = $this->post(route('tsa-management.store'), [
            'display_name'     => 'Bad TSA',
            'team'              => 'SH Naturals',
            'rest_day_of_week'  => 'someday',
        ]);

        $response->assertSessionHasErrors('rest_day_of_week');
        $this->assertDatabaseMissing('tsa_shifts', ['display_name' => 'Bad TSA']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=TsaManagementControllerTest`
Expected: the 3 new tests **FAIL** — `rest_day_of_week` isn't validated or persisted by `store()`/`update()` yet.

- [ ] **Step 3: Extend validation and persistence**

In `app/Http/Controllers/TsaManagementController.php`, replace `validateTsa()` (currently near the bottom of the class) with:

```php
    private function validateTsa(Request $request): array
    {
        $teamsConfig = config('teams', []);
        $validTeams  = collect($teamsConfig)->pluck('order_team')->all();

        return $request->validate([
            'display_name'     => 'required|string|max:100',
            'team'             => 'required|string|in:' . implode(',', $validTeams),
            'pos_user_id'      => 'nullable|string|max:100',
            'extra_keywords'   => 'nullable|string|max:255',
            'rest_day_of_week' => 'nullable|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
        ]);
    }
```

Replace the `TsaShift::create([...])` array inside `store()` with:

```php
        TsaShift::create([
            'tsa_key'          => $tsaKey,
            'pos_user_id'      => $data['pos_user_id'] ?? null,
            'display_name'     => $data['display_name'],
            'team'             => $data['team'],
            'tag_keywords'     => $this->buildTagKeywords($tsaKey, $data['extra_keywords'] ?? ''),
            'seller_keywords'  => !empty($data['pos_user_id']) ? strtolower($data['display_name']) : null,
            'rest_day_of_week' => $data['rest_day_of_week'] ?? null,
            'sort_order'       => $nextSort,
        ]);
```

Replace the `$tsaShift->update([...])` array inside `update()` with:

```php
        $tsaShift->update([
            'pos_user_id'      => $data['pos_user_id'] ?? $tsaShift->pos_user_id,
            'display_name'     => $data['display_name'],
            'team'             => $data['team'],
            'tag_keywords'     => $this->buildTagKeywords($tsaShift->tsa_key, $data['extra_keywords'] ?? ''),
            'seller_keywords'  => $sellerKeywords,
            'rest_day_of_week' => $data['rest_day_of_week'] ?? null,
        ]);
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=TsaManagementControllerTest`
Expected: **PASS** — 13 tests (10 from Tasks 2-4, 3 new).

- [ ] **Step 5: Add the field to the modal view**

In `resources/views/tsa-management.blade.php`, inside the existing `#tsaModal` form, add this new field immediately after the "Also matches" `<div>` block (the one with `id="tsaExtraInput"`) and before the `<div class="flex items-center justify-end gap-2 pt-2">` buttons row:

```blade
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">
                    Rest day <span class="text-slate-400 font-normal">(optional)</span>
                </label>
                <select name="rest_day_of_week" id="tsaRestDaySelect"
                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    <option value="">None</option>
                    <option value="sunday">Sunday</option>
                    <option value="monday">Monday</option>
                    <option value="tuesday">Tuesday</option>
                    <option value="wednesday">Wednesday</option>
                    <option value="thursday">Thursday</option>
                    <option value="friday">Friday</option>
                    <option value="saturday">Saturday</option>
                </select>
            </div>
```

Add a `data-rest-day` attribute to the existing `.editTsaBtn` button (in the roster row, alongside its existing `data-display-name`/`data-team`/etc. attributes):

```blade
                            data-rest-day="{{ $shift->rest_day_of_week }}">
```

(This goes as one more line in the existing attribute list on that button — right before the closing `>`.)

In the `@push('scripts')` block's **first** IIFE (the one with `resetForm()`/`editTsaBtn` — do not touch the second IIFE added in Task 4), add the new field to the existing `const` declarations at the top:

```js
    const restDaySelect = document.getElementById('tsaRestDaySelect');
```

In `resetForm()`, add one line:

```js
        restDaySelect.value = '';
```

In the `editTsaBtn` click handler, add one line alongside the other `btn.dataset.*` assignments:

```js
            restDaySelect.value = btn.dataset.restDay || '';
```

- [ ] **Step 6: Run the full test suite to check for regressions**

Run: `php artisan test`
Expected: **PASS**, no regressions.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/TsaManagementController.php resources/views/tsa-management.blade.php tests/Feature/TsaManagementControllerTest.php
git commit -m "feat: set a TSA's recurring rest day from the Add/Edit modal

One new 'Rest day' dropdown alongside the existing name/team/shift
fields — no new modal, no new page."
```

---

## Task 6: "Day Off" banner on TSA Performance

**Files:**
- Modify: `app/Http/Controllers/TsaPerformanceController.php`
- Modify: `resources/views/tsa-performance-individual.blade.php`
- Test: `tests/Feature/TsaPerformanceRestDayBannerTest.php` (new)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/TsaPerformanceRestDayBannerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TsaPerformanceRestDayBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_day_off_banner_when_viewing_a_rest_day(): void
    {
        $this->actingAs(User::factory()->create());

        $julie  = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $sunday = Carbon::parse('next sunday')->toDateString();

        $response = $this->get(route('tsa-performance.individual', [
            'team'      => 'eyecare',
            'tsaKey'    => 'Julie',
            'date_from' => $sunday,
            'date_to'   => $sunday,
        ]));

        $response->assertOk();
        $response->assertSee('is marked as off on');
    }

    public function test_does_not_show_day_off_banner_on_a_working_day(): void
    {
        $this->actingAs(User::factory()->create());

        $julie  = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $monday = Carbon::parse('next sunday')->addDay()->toDateString();

        $response = $this->get(route('tsa-performance.individual', [
            'team'      => 'eyecare',
            'tsaKey'    => 'Julie',
            'date_from' => $monday,
            'date_to'   => $monday,
        ]));

        $response->assertOk();
        $response->assertDontSee('is marked as off on');
    }

    public function test_does_not_show_banner_for_a_multi_day_range_even_if_it_includes_a_rest_day(): void
    {
        $this->actingAs(User::factory()->create());

        $julie  = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $sunday = Carbon::parse('next sunday');

        $response = $this->get(route('tsa-performance.individual', [
            'team'      => 'eyecare',
            'tsaKey'    => 'Julie',
            'date_from' => $sunday->toDateString(),
            'date_to'   => $sunday->copy()->addDay()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertDontSee('is marked as off on');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=TsaPerformanceRestDayBannerTest`
Expected: `test_shows_day_off_banner_when_viewing_a_rest_day` **FAILS** (banner doesn't exist yet). The other two should already pass (there's nothing to show, and nothing does).

- [ ] **Step 3: Compute `$isRestDay` in the controller**

In `app/Http/Controllers/TsaPerformanceController.php`'s `showTsa()` method, add this line immediately after `$to = Carbon::parse($dateTo)->endOfDay();` (currently line 209):

```php
        // Only meaningful when viewing exactly one date — a multi-day range could
        // span both rest and working days, so a single banner would be misleading.
        $isRestDay = $dateFrom === $dateTo && $shift->isOffOn($from);
```

Add `'isRestDay' => $isRestDay,` to the `view('tsa-performance-individual', [...])` array (currently ending at line 327, right before the closing `]);`).

- [ ] **Step 4: Add the banner to the view**

In `resources/views/tsa-performance-individual.blade.php`, insert this block immediately after the "Back to..." `</a>` (currently line 22) and before the `{{-- KPI CARDS ... --}}` comment (currently line 24):

```blade
@if($isRestDay)
<div class="mb-6 flex items-center gap-3 bg-slate-100 border border-slate-200 rounded-xl px-5 py-3">
    <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
    </svg>
    <p class="text-sm font-mono text-slate-600">{{ $displayName }} is marked as off on {{ $rangeLabel }}.</p>
</div>
@endif
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=TsaPerformanceRestDayBannerTest`
Expected: **PASS** — 3 tests.

- [ ] **Step 6: Run the full test suite to check for regressions**

Run: `php artisan test`
Expected: **PASS**, no regressions.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/TsaPerformanceController.php resources/views/tsa-performance-individual.blade.php tests/Feature/TsaPerformanceRestDayBannerTest.php
git commit -m "feat: show a Day Off banner on TSA Performance for rest days

Purely informational — no change to any computed metric (upselling
rate, pick-up rate, totals, etc. are all untouched). Only shown when
viewing a single date, since a multi-day range could span both rest
and working days."
```

---

## Self-Review

**Spec coverage:** Every section of `docs/superpowers/specs/2026-07-14-tsa-rest-day-calendar-design.md` maps to a task — data model → Task 1, calendar UI → Tasks 2-3, editing → Task 4, recurring day-off field → Task 5, TSA Performance banner → Task 6. The spec's "Out of Scope" items (no metric changes, no indicators outside these two pages, no bulk entry) are respected throughout — nothing in any task touches `ProductPerformance`, the Dashboard, or Leads Report.

**Placeholder scan:** No TBD/TODO. Every step has complete code and exact `php artisan test --filter=...` commands with stated expected outcomes.

**Type consistency:** `TsaShift::isOffOn(Carbon $date): bool` is defined once in Task 1 and consumed identically everywhere else — `buildCalendar()` (Task 2), `saveRestDays()` (Task 4, via the same explicit `restDays->first(fn...)` pattern rather than re-introducing the `firstWhere` bug), and `TsaPerformanceController::showTsa()` (Task 6) all call it the same way with a `Carbon` instance. The `off_tsas` array shape (`['tsa_key' => ..., 'initials' => ...]`) established in Task 2's `buildCalendar()` is what Task 3's view and tests consume (`->pluck('tsa_key')`, `->pluck('initials')`) — no drift between what the controller produces and what the view/tests expect. `rest_day_of_week` is consistently a lowercase full day name string everywhere it appears (migration, `isOffOn()`, `saveRestDays()`, the modal's `<select>` option values, validation's `in:` rule).
