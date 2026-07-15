# User Management & Roles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add four fixed user roles (Super Admin, Admin, Normal, Guest) with role-gated routes, an Admin-only User Management page for adding/editing/deactivating accounts, and close the public sign-up path (including the Google auto-create backdoor) so every account comes from an existing admin.

**Architecture:** A plain `role` string column + `is_active` boolean on `users` (no new package — four fixed tiers don't need dynamic permission editing). Two small route middlewares (`active`, `role:<allowed-roles>`) gate route groups declaratively in `routes/web.php`. A new `UserManagementController` + Blade view follow the exact modal-based CRUD pattern already used by `ProductManagementController`/`product-management.blade.php` (this codebase's established convention for admin config pages).

**Tech Stack:** Laravel 11, Blade, plain migrations (no Spatie/permission packages), PHPUnit feature tests, SQLite in-memory for tests.

**Full spec:** `docs/superpowers/specs/2026-07-15-user-management-roles-design.md`

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_07_15_090000_add_role_and_is_active_to_users_table.php` | New columns + one-time backfill of the 2 existing rows to `super_admin` |
| `app/Models/User.php` | `role`/`is_active` casts, role-check helpers, `canManage()`, last-active-super-admin count |
| `database/factories/UserFactory.php` | Default `role => 'admin'`, `is_active => true`; add `superAdmin()`/`normal()`/`guestRole()` states |
| `app/Http/Middleware/EnsureUserIsActive.php` | Force-logout + redirect if the signed-in user has been deactivated |
| `app/Http/Middleware/EnsureUserHasRole.php` | 403 unless the signed-in user's role is in the middleware's allow-list |
| `bootstrap/app.php` | Register `active` and `role` middleware aliases |
| `app/Http/Controllers/AuthController.php` | Remove `showRegister()`/`register()`; block deactivated accounts at login (both paths); stop auto-creating a user on unmatched Google sign-in |
| `routes/web.php` | Remove `/register`; wrap the authenticated group in `['auth','active']`; add `role:super_admin,admin` to CONFIG routes and the new User Management routes; add `role:super_admin,admin,normal` to `/sync` |
| `resources/views/auth/login.blade.php` | Remove the "Sign up" link |
| `resources/views/auth/register.blade.php` | Deleted |
| `app/Http/Controllers/UserManagementController.php` | `index`, `store`, `update`, `toggleActive` |
| `resources/views/user-management.blade.php` | Table + Add/Edit modal, mirrors `product-management.blade.php` |
| `resources/views/layouts/app.blade.php` | New "User Management" nav link; whole CONFIG section only renders for `isAtLeastAdmin()` |
| `tests/Feature/AuthTest.php` | Replace register tests with route-removed test; add deactivated-account tests |
| `tests/Feature/GoogleAuthTest.php` (new) | Google sign-in: matches existing account, rejects unmatched email, rejects deactivated account |
| `tests/Feature/UserManagementControllerTest.php` (new) | Full CRUD + permission-boundary + last-super-admin-guard coverage |
| `tests/Feature/RoleAccessTest.php` (new) | CONFIG routes and `/sync` are actually blocked per role, across the existing report pages too |

---

## Task 1: Roles data model

**Files:**
- Create: `database/migrations/2026_07_15_090000_add_role_and_is_active_to_users_table.php`
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Test: `tests/Unit/UserRoleTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_default_role_is_admin_and_active(): void
    {
        $user = User::factory()->create();

        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->isAtLeastAdmin());
        $this->assertFalse($user->isSuperAdmin());
    }

    public function test_role_state_factories(): void
    {
        $this->assertTrue(User::factory()->superAdmin()->create()->isSuperAdmin());
        $this->assertSame('normal', User::factory()->normal()->create()->role);
        $this->assertSame('guest', User::factory()->guestRole()->create()->role);
    }

    public function test_can_manage_rules(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $admin      = User::factory()->admin()->create();
        $normal     = User::factory()->normal()->create();

        $this->assertTrue($superAdmin->canManage($admin));
        $this->assertTrue($superAdmin->canManage($normal));
        $this->assertFalse($superAdmin->canManage($superAdmin), 'no self-management');

        $this->assertTrue($admin->canManage($normal));
        $this->assertFalse($admin->canManage($superAdmin), 'admin cannot manage super admin');
        $this->assertFalse($admin->canManage($admin), 'no self-management, and admin cannot manage admin anyway');
    }

    public function test_active_super_admin_count(): void
    {
        $this->assertSame(0, User::activeSuperAdminCount());

        $superAdmin = User::factory()->superAdmin()->create();
        $this->assertSame(1, User::activeSuperAdminCount());

        $superAdmin->update(['is_active' => false]);
        $this->assertSame(0, User::activeSuperAdminCount());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UserRoleTest`
Expected: FAIL — `role` column / methods don't exist yet (likely a `BadMethodCallException` or SQL error on the first test).

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('normal')->after('avatar');
            $table->boolean('is_active')->default(true)->after('role');
        });

        // One-time backfill: both accounts that exist before this migration ever
        // ran (raysanred0@gmail.com and the seeded lizzie28@example.com row) become
        // Super Admin — see the design spec's "existing accounts become Super
        // Admin" decision. This UPDATE is a no-op on a fresh test database, since
        // RefreshDatabase migrates against an empty users table before any test
        // creates its own rows.
        DB::table('users')->update(['role' => 'super_admin', 'is_active' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
```

- [ ] **Step 4: Add role helpers to the User model**

Modify `app/Models/User.php` — add `role` and `is_active` to `$fillable`, add the boolean cast, and add the helper methods. Full file:

```php
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Every valid role, most to least privileged. */
    public const ROLES = ['super_admin', 'admin', 'normal', 'guest'];

    public const ROLE_LABELS = [
        'super_admin' => 'Super Admin',
        'admin'       => 'Admin',
        'normal'      => 'Normal User',
        'guest'       => 'Guest',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'avatar',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Super Admin or Admin — the two roles with CONFIG-page access. */
    public function isAtLeastAdmin(): bool
    {
        return in_array($this->role, ['super_admin', 'admin'], true);
    }

    /**
     * Whether $this (the acting user) is allowed to create/edit/deactivate
     * $target's account, per the design spec's permission matrix: Super Admin
     * manages everyone but themselves; Admin manages Normal/Guest only; nobody
     * manages their own row through this page (self-service isn't in scope —
     * see the spec's "Out of scope" section).
     */
    public function canManage(User $target): bool
    {
        if ($this->is($target)) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->isAdmin()) {
            return !$target->isAtLeastAdmin();
        }

        return false;
    }

    /** Roles $this is allowed to assign when creating/editing another user. */
    public function assignableRoles(): array
    {
        if ($this->isSuperAdmin()) {
            return self::ROLES;
        }

        if ($this->isAdmin()) {
            return ['normal', 'guest'];
        }

        return [];
    }

    /** Used to guard against deactivating/demoting the last active Super Admin. */
    public static function activeSuperAdminCount(): int
    {
        return static::where('role', 'super_admin')->where('is_active', true)->count();
    }
}
```

- [ ] **Step 5: Update the User factory**

Modify `database/factories/UserFactory.php` — default every factory-created user to `admin` + active (existing tests across the suite call `User::factory()->create()` expecting full access to config pages; see the plan's Task 7 notes), and add role-specific states:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => 'admin',
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'super_admin']);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'admin']);
    }

    public function normal(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'normal']);
    }

    public function guestRole(): static
    {
        // Named guestRole() (not guest()) — Laravel/HTTP-testing already
        // overloads "guest" to mean "unauthenticated"; this is a role value.
        return $this->state(fn (array $attributes) => ['role' => 'guest']);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=UserRoleTest`
Expected: PASS (4 tests)

- [ ] **Step 7: Run the full suite to confirm nothing broke**

Run: `php artisan test`
Expected: PASS — every existing test that does `User::factory()->create()` now gets an active `admin`, which already had access to everything those tests exercise.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_15_090000_add_role_and_is_active_to_users_table.php app/Models/User.php database/factories/UserFactory.php tests/Unit/UserRoleTest.php
git commit -m "feat: add role and is_active to users, with role-check helpers"
```

---

## Task 2: Role & active-session middleware

**Files:**
- Create: `app/Http/Middleware/EnsureUserIsActive.php`
- Create: `app/Http/Middleware/EnsureUserHasRole.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/RoleAccessTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_user_can_view_main_report_pages(): void
    {
        $this->actingAs(User::factory()->normal()->create());

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('leads-report'))->assertOk();
    }

    public function test_normal_user_is_forbidden_from_config_pages(): void
    {
        $this->actingAs(User::factory()->normal()->create());

        $this->get(route('tsa-management'))->assertForbidden();
        $this->get(route('product-management'))->assertForbidden();
        $this->get(route('settings'))->assertForbidden();
    }

    public function test_guest_role_is_forbidden_from_config_pages(): void
    {
        $this->actingAs(User::factory()->guestRole()->create());

        $this->get(route('tsa-management'))->assertForbidden();
    }

    public function test_admin_can_reach_config_pages(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('tsa-management'))->assertOk();
    }

    public function test_normal_user_can_trigger_sync(): void
    {
        $this->actingAs(User::factory()->normal()->create());

        $response = $this->post(route('dashboard.sync'));

        $response->assertStatus(302); // redirects back on success; the point is it's not 403
    }

    public function test_guest_role_cannot_trigger_sync(): void
    {
        $this->actingAs(User::factory()->guestRole()->create());

        $this->post(route('dashboard.sync'))->assertForbidden();
    }

    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $user->update(['is_active' => false]);

        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RoleAccessTest`
Expected: FAIL — `tsa-management` currently returns 200 for a `normal` user (no restriction exists yet), and the `role` middleware alias doesn't exist so routes using it would error once added in a later step. For now, at minimum `test_normal_user_is_forbidden_from_config_pages` and `test_guest_role_cannot_trigger_sync` fail because nothing is forbidden yet.

- [ ] **Step 3: Create the active-session middleware**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every authenticated route. A user deactivated mid-session (someone
 * else's Admin deactivated them from User Management while they were still
 * browsing) is force-logged-out on their very next request rather than kept
 * signed in until they happen to log out themselves.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'This account has been deactivated.',
            ]);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Create the role-check middleware**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('role:super_admin,admin') — 403s unless the signed-in
 * user's role is in the allow-list. Assumes 'auth' (and normally 'active')
 * already ran first, so $request->user() is present.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        abort_unless(in_array($request->user()->role, $roles, true), 403);

        return $next($request);
    }
}
```

- [ ] **Step 5: Register both as middleware aliases**

Modify `bootstrap/app.php`:

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'role'   => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

- [ ] **Step 6: Wire the middleware into the route groups**

Modify `routes/web.php` — change the authenticated group's middleware from `'auth'` to `['auth', 'active']`, and add `role:super_admin,admin` to every CONFIG route and `role:super_admin,admin,normal` to the sync route:

```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LeadsReportController;
use App\Http\Controllers\TsaPerformanceController;
use App\Http\Controllers\ChartsController;
use App\Http\Controllers\RtsReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TsaManagementController;
use App\Http\Controllers\ProductManagementController;

// Guest-only: a signed-in user hitting these is bounced to the dashboard
// instead of seeing the login/register form again.
Route::middleware('guest')->group(function () {
    Route::get('/login',     [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login',    [AuthController::class, 'login']);

    Route::get('/auth/google',          [AuthController::class, 'redirectToGoogle'])->name('google.redirect');
    Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('google.callback');
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// No 'auth' middleware — this is hit by an external cron pinger, not a signed-in
// user. Protected instead by a random token (CRON_SECRET, checked in the
// controller) that only Render's env vars and the pinger config know.
Route::get('/cron/run', [CronController::class, 'run'])->name('cron.run');

// Every report/config page requires a signed-in, active user — 'active' force-logs-out
// anyone deactivated mid-session (see EnsureUserIsActive).
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/',                [DashboardController::class,      'index'])->name('dashboard');
    Route::post('/sync',           [DashboardController::class,      'sync'])->name('dashboard.sync')
        ->middleware('role:super_admin,admin,normal');
    Route::get('/leads-report',    [LeadsReportController::class,    'index'])->name('leads-report');
    // Old URL kept alive for bookmarks/history — permanent redirect to the new name.
    Route::redirect('/team-report', '/leads-report', 301);
    Route::get('/tsa-performance', [TsaPerformanceController::class, 'index'])->name('tsa-performance');
    Route::get('/tsa-performance/{team}/{tsaKey}', [TsaPerformanceController::class, 'showTsa'])->name('tsa-performance.individual');
    Route::get('/charts',          [ChartsController::class,         'index'])->name('charts');
    Route::get('/rts-report',      [RtsReportController::class,      'index'])->name('rts-report');

    // CONFIG — Super Admin and Admin only.
    Route::middleware('role:super_admin,admin')->group(function () {
        Route::get('/tsa-management',             [TsaManagementController::class, 'index'])->name('tsa-management');
        Route::get('/tsa-management/pos-users',   [TsaManagementController::class, 'searchPosUsers'])->name('tsa-management.pos-users');
        Route::get('/tsa-management/tags',        [TsaManagementController::class, 'searchTags'])->name('tsa-management.tags');
        Route::post('/tsa-management',            [TsaManagementController::class, 'store'])->name('tsa-management.store');
        Route::put('/tsa-management/{tsaShift}',  [TsaManagementController::class, 'update'])->name('tsa-management.update');
        Route::delete('/tsa-management/{tsaShift}', [TsaManagementController::class, 'destroy'])->name('tsa-management.destroy');
        Route::post('/tsa-management/rest-days/{date}', [TsaManagementController::class, 'saveRestDays'])->name('tsa-management.rest-days');

        Route::get('/product-management',               [ProductManagementController::class, 'index'])->name('product-management');
        Route::post('/product-management',               [ProductManagementController::class, 'store'])->name('product-management.store');
        Route::put('/product-management/{product}',      [ProductManagementController::class, 'update'])->name('product-management.update');
        Route::delete('/product-management/{product}',   [ProductManagementController::class, 'destroy'])->name('product-management.destroy');
        Route::patch('/product-management/{product}/toggle-hidden', [ProductManagementController::class, 'toggleHidden'])->name('product-management.toggle-hidden');

        Route::get('/settings',          [SettingsController::class, 'index'])->name('settings');
        Route::post('/settings',         [SettingsController::class, 'save'])->name('settings.save');
        Route::post('/settings/detect',  [SettingsController::class, 'detect'])->name('settings.detect');
        Route::post('/settings/clear',   [SettingsController::class, 'clear'])->name('settings.clear');
        Route::post('/settings/shifts',  [SettingsController::class, 'saveShifts'])->name('settings.shifts');
    });
});
```

Note: `/user-management` routes are added in Task 4, inside this same `role:super_admin,admin` group.

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=RoleAccessTest`
Expected: PASS (7 tests)

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Http/Middleware/EnsureUserIsActive.php app/Http/Middleware/EnsureUserHasRole.php bootstrap/app.php routes/web.php tests/Feature/RoleAccessTest.php
git commit -m "feat: gate CONFIG routes and dashboard sync by role, force-logout deactivated sessions"
```

---

## Task 3: Close public sign-up (register removal + Google auto-create removal)

**Files:**
- Modify: `app/Http/Controllers/AuthController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/auth/login.blade.php`
- Delete: `resources/views/auth/register.blade.php`
- Modify: `tests/Feature/AuthTest.php`
- Create: `tests/Feature/GoogleAuthTest.php`

- [ ] **Step 1: Write the failing tests**

Replace the two register-related tests in `tests/Feature/AuthTest.php` and add deactivation coverage. Full file:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_the_dashboard_to_login(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_a_signed_in_user_can_reach_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('dashboard'));

        $response->assertOk();
    }

    public function test_register_route_no_longer_exists(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_login_page_has_no_sign_up_link(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertDontSee('Sign up');
    }

    public function test_login_succeeds_with_correct_credentials(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);

        $response = $this->post(route('login'), [
            'email'    => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);

        $response = $this->post(route('login'), [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_rejects_a_deactivated_account_even_with_correct_password(): void
    {
        $user = User::factory()->inactive()->create(['password' => Hash::make('secret123')]);

        $response = $this->post(route('login'), [
            'email'    => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_signed_in_user_visiting_login_is_redirected_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('login'));

        $response->assertRedirect(route('dashboard'));
    }
}
```

- [ ] **Step 2: Write the Google sign-in tests**

Socialite's Google user is faked via the `Socialite` facade's `shouldReceive` (Mockery), matching the four methods `AuthController::handleGoogleCallback()` calls on it (`getId`, `getEmail`, `getName`, `getAvatar`).

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogleUser(string $email, string $googleId = 'google-123', string $name = 'Jane Doe'): void
    {
        $socialiteUser = \Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getId')->andReturn($googleId);
        $socialiteUser->shouldReceive('getEmail')->andReturn($email);
        $socialiteUser->shouldReceive('getName')->andReturn($name);
        $socialiteUser->shouldReceive('getAvatar')->andReturn('https://example.com/avatar.png');

        Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);
    }

    public function test_google_sign_in_logs_in_an_existing_matching_account(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.com', 'google_id' => null]);
        $this->fakeGoogleUser('existing@example.com');

        $response = $this->get(route('google.callback'));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'google_id' => 'google-123']);
    }

    public function test_google_sign_in_does_not_create_an_account_for_an_unmatched_email(): void
    {
        $this->fakeGoogleUser('stranger@example.com');

        $response = $this->get(route('google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
    }

    public function test_google_sign_in_rejects_a_deactivated_account(): void
    {
        User::factory()->inactive()->create(['email' => 'deactivated@example.com']);
        $this->fakeGoogleUser('deactivated@example.com');

        $response = $this->get(route('google.callback'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --filter=AuthTest`
Run: `php artisan test --filter=GoogleAuthTest`
Expected: FAIL — `/register` still exists (200, not 404), login page still shows "Sign up", deactivated accounts can still log in, Google still auto-creates for unmatched emails.

- [ ] **Step 4: Update AuthController**

Full file:

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            // Attached to the email field (not a top-level banner) — same
            // field-level error convention as every other form in this app
            // (TSA Management, Product Management, Settings).
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (!Auth::user()->is_active) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            // Covers the user cancelling on Google's consent screen, an
            // expired/replayed callback URL, and bad client credentials.
            return redirect()->route('login')->withErrors([
                'email' => 'Google sign-in failed. Please try again.',
            ]);
        }

        // Match by google_id first (returning Google user), then by email so
        // an existing password account gets linked instead of duplicated —
        // users.email is unique, so creating blindly would throw.
        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $googleUser->getEmail())->first();

        // No auto-create: accounts only ever come from User Management now. A
        // stranger's Google account matching no existing row is not "sign up",
        // it's a dead end — the whole point of closing public registration.
        if (!$user) {
            return redirect()->route('login')->withErrors([
                'email' => 'No account found for that Google email — ask an admin to add you first.',
            ]);
        }

        if (!$user->is_active) {
            return redirect()->route('login')->withErrors([
                'email' => 'This account has been deactivated.',
            ]);
        }

        $user->update([
            'google_id' => $googleUser->getId(),
            'avatar'    => $googleUser->getAvatar(),
        ]);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
```

- [ ] **Step 5: Remove the register routes**

Modify `routes/web.php` — the `guest` middleware group loses its two register lines (already reflected in Task 2's full-file listing above, which omits them; if Task 2 hasn't been applied to the file yet, remove these two lines from the `Route::middleware('guest')->group(...)` block):

```php
    Route::get('/register',  [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
```

- [ ] **Step 6: Remove the "Sign up" link and delete the register view**

Modify `resources/views/auth/login.blade.php` — delete this block near the end of the `@section('content')`:

```blade
<p class="mt-7 text-center text-xs font-mono text-slate-400">
    Don't have an account?
    <a href="{{ route('register') }}" class="text-accent font-semibold hover:underline">Sign up</a>
</p>
```

Delete the file entirely:

```bash
rm resources/views/auth/register.blade.php
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=AuthTest`
Run: `php artisan test --filter=GoogleAuthTest`
Expected: PASS (9 + 3 tests)

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/AuthController.php routes/web.php resources/views/auth/login.blade.php tests/Feature/AuthTest.php tests/Feature/GoogleAuthTest.php
git rm resources/views/auth/register.blade.php
git commit -m "fix: close public sign-up — remove /register and Google auto-create, block deactivated accounts at login"
```

---

## Task 4: User Management controller & routes

**Files:**
- Create: `app/Http/Controllers/UserManagementController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/UserManagementControllerTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_user_cannot_reach_user_management(): void
    {
        $this->actingAs(User::factory()->normal()->create());

        $this->get(route('user-management'))->assertForbidden();
    }

    public function test_admin_can_view_the_user_list(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        User::factory()->normal()->create(['name' => 'Regular Person']);

        $response = $this->get(route('user-management'));

        $response->assertOk();
        $response->assertSee('Regular Person');
    }

    public function test_admin_can_create_a_normal_user(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->post(route('user-management.store'), [
            'name'  => 'New Person',
            'email' => 'new.person@example.com',
            'role'  => 'normal',
        ]);

        $response->assertRedirect(route('user-management'));
        $this->assertDatabaseHas('users', [
            'email'     => 'new.person@example.com',
            'role'      => 'normal',
            'is_active' => true,
        ]);
    }

    public function test_admin_cannot_create_an_admin_account(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->post(route('user-management.store'), [
            'name'  => 'Sneaky',
            'email' => 'sneaky@example.com',
            'role'  => 'admin',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    public function test_super_admin_can_create_an_admin_account(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $response = $this->post(route('user-management.store'), [
            'name'  => 'New Admin',
            'email' => 'new.admin@example.com',
            'role'  => 'admin',
        ]);

        $response->assertRedirect(route('user-management'));
        $this->assertDatabaseHas('users', ['email' => 'new.admin@example.com', 'role' => 'admin']);
    }

    public function test_admin_can_update_a_normal_user(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $target = User::factory()->normal()->create();

        $response = $this->put(route('user-management.update', $target), [
            'name'  => 'Renamed Person',
            'email' => $target->email,
            'role'  => 'guest',
        ]);

        $response->assertRedirect(route('user-management'));
        $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'Renamed Person', 'role' => 'guest']);
    }

    public function test_admin_cannot_update_another_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $target = User::factory()->admin()->create();

        $response = $this->put(route('user-management.update', $target), [
            'name'  => 'Should Not Apply',
            'email' => $target->email,
            'role'  => 'normal',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', ['id' => $target->id, 'name' => 'Should Not Apply']);
    }

    public function test_user_cannot_edit_their_own_row(): void
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);

        $response = $this->put(route('user-management.update', $actor), [
            'name'  => 'Self Edit',
            'email' => $actor->email,
            'role'  => 'admin',
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_deactivate_a_normal_user(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $target = User::factory()->normal()->create();
        $this->assertTrue($target->is_active);

        $response = $this->patch(route('user-management.toggle-active', $target));

        $response->assertRedirect(route('user-management'));
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
    }

    public function test_toggle_active_twice_reactivates(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $target = User::factory()->normal()->create();

        $this->patch(route('user-management.toggle-active', $target));
        $this->patch(route('user-management.toggle-active', $target));

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
    }

    public function test_admin_cannot_deactivate_another_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $target = User::factory()->admin()->create();

        $response = $this->patch(route('user-management.toggle-active', $target));

        $response->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
    }

    public function test_deactivating_one_of_two_super_admins_leaves_one_active(): void
    {
        // No explicit "last active Super Admin" runtime guard exists — it isn't
        // needed. Self-edit is blocked, and only a Super Admin can manage another
        // Super Admin, so reaching this controller with a Super Admin target
        // requires the actor to already be a SECOND, distinct, active Super
        // Admin — meaning at least one always remains after the mutation. This
        // test documents that invariant with the simplest case that exercises it
        // (two Super Admins, one deactivates the other).
        $actingSuperAdmin = User::factory()->superAdmin()->create();
        $targetSuperAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($actingSuperAdmin);

        $response = $this->patch(route('user-management.toggle-active', $targetSuperAdmin));

        $response->assertRedirect(route('user-management'));
        $this->assertDatabaseHas('users', ['id' => $targetSuperAdmin->id, 'is_active' => false]);
        $this->assertDatabaseHas('users', ['id' => $actingSuperAdmin->id, 'is_active' => true]);
        $this->assertSame(1, User::activeSuperAdminCount());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UserManagementControllerTest`
Expected: FAIL — route `user-management` doesn't exist yet (`RouteNotFoundException`).

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        $users = User::orderByRaw("field(role, 'super_admin','admin','normal','guest')")
            ->orderBy('name')
            ->get();

        return view('user-management', [
            'users'           => $users,
            'assignableRoles' => $actor->assignableRoles(),
        ]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        $data  = $this->validateUser($request, $actor);

        User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'role'     => $data['role'],
            'is_active' => true,
            // Random unusable password — same pattern as Google-created accounts
            // in AuthController::handleGoogleCallback(). This person signs in by
            // doing "Sign in with Google" with this same email; there's no
            // password-based path for accounts created here (see design spec).
            'password' => Hash::make(Str::random(40)),
        ]);

        return redirect()->route('user-management')
            ->with('success', "Added \"{$data['name']}\".");
    }

    public function update(Request $request, User $user)
    {
        $actor = $request->user();
        abort_unless($actor->canManage($user), 403);

        $data = $this->validateUser($request, $actor, $user);

        $user->update([
            'name'  => $data['name'],
            'email' => $data['email'],
            'role'  => $data['role'],
        ]);

        return redirect()->route('user-management')
            ->with('success', "Updated \"{$data['name']}\".");
    }

    /**
     * No explicit "last active Super Admin" runtime guard is needed here (or in
     * update() above): canManage() already blocks self-edit, and only a Super
     * Admin can manage another Super Admin's account — so reaching this method
     * with a Super Admin $user requires the acting user to already be a SECOND,
     * distinct, active Super Admin. At least one therefore always remains after
     * this mutation; see UserManagementControllerTest::
     * test_deactivating_one_of_two_super_admins_leaves_one_active for the proof.
     */
    public function toggleActive(Request $request, User $user)
    {
        $actor = $request->user();
        abort_unless($actor->canManage($user), 403);

        $user->is_active = !$user->is_active;
        $user->save();

        $verb = $user->is_active ? 'Reactivated' : 'Deactivated';

        return redirect()->route('user-management')
            ->with('success', "{$verb} \"{$user->name}\".");
    }

    private function validateUser(Request $request, User $actor, ?User $target = null): array
    {
        $assignable = $actor->assignableRoles();

        // A role value outside what this actor is allowed to assign is a
        // permission boundary, not a generic data-validity issue — so it's a
        // 403, same as canManage() failing, not a validation error.
        abort_unless(in_array($request->input('role'), $assignable, true), 403);

        return $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email' . ($target ? ",{$target->id}" : ''),
            'role'  => 'required|string|in:' . implode(',', $assignable),
        ]);
    }
}
```

- [ ] **Step 4: Add the routes**

Modify `routes/web.php` — inside the `role:super_admin,admin` group added in Task 2, right after the Settings routes:

```php
        Route::get('/user-management',                    [UserManagementController::class, 'index'])->name('user-management');
        Route::post('/user-management',                    [UserManagementController::class, 'store'])->name('user-management.store');
        Route::put('/user-management/{user}',               [UserManagementController::class, 'update'])->name('user-management.update');
        Route::patch('/user-management/{user}/toggle-active', [UserManagementController::class, 'toggleActive'])->name('user-management.toggle-active');
```

Add the import at the top of the file alongside the other controller `use` statements:

```php
use App\Http\Controllers\UserManagementController;
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=UserManagementControllerTest`
Expected: PASS (12 tests). Note: `resources/views/user-management.blade.php` doesn't exist until Task 5 — `index()` tests will fail on view rendering until then. If Step 5 fails only on the two `index` tests with a `ViewNotFoundException`, that's expected at this point; proceed to Task 5 and re-run before committing this task.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/UserManagementController.php routes/web.php tests/Feature/UserManagementControllerTest.php
git commit -m "feat: add UserManagementController with role-scoped create/edit/deactivate"
```

---

## Task 5: User Management view

**Files:**
- Create: `resources/views/user-management.blade.php`

- [ ] **Step 1: Write the view**

Mirrors `resources/views/product-management.blade.php`'s modal-based pattern (Add/Edit modal, hidden toggle form, vanilla JS — no build step needed, consistent with every other config page in this app):

```blade
@extends('layouts.app')
@section('title', 'User Management')
@section('subtitle', 'Accounts and roles')

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
        <p class="text-xs text-slate-400 font-mono">Add teammates by their Google account email, and set what they're allowed to see and do.</p>
        <button type="button" id="addUserBtn"
            class="inline-flex items-center gap-1.5 px-4 py-2 bg-yellow-700 hover:bg-yellow-800 text-white text-xs font-semibold rounded-lg transition-colors cursor-pointer whitespace-nowrap shrink-0 ml-4">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add User
        </button>
    </div>

    <div class="bg-white rounded-xl border border-yellow-100 shadow-sm overflow-hidden">
        <div class="divide-y divide-slate-100">
            @foreach($users as $user)
            <div class="px-6 py-3 flex items-center gap-4 {{ $user->is_active ? '' : 'opacity-50' }}">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <p class="text-sm font-mono font-semibold text-slate-700 truncate">{{ $user->name }}</p>
                        <span class="text-[10px] font-semibold text-yellow-800 bg-yellow-100 px-1.5 py-0.5 rounded shrink-0">{{ \App\Models\User::ROLE_LABELS[$user->role] ?? $user->role }}</span>
                        @if(!$user->is_active)
                        <span class="text-[10px] font-semibold text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded shrink-0">Deactivated</span>
                        @endif
                    </div>
                    <p class="text-[11px] text-slate-400 font-mono truncate">{{ $user->email }}</p>
                </div>

                @if(auth()->user()->canManage($user))
                <div class="flex items-center gap-1 shrink-0">
                    <button type="button"
                        class="editUserBtn p-1.5 rounded-lg text-slate-400 hover:text-yellow-600 hover:bg-yellow-50 transition-colors cursor-pointer"
                        title="Edit"
                        data-id="{{ $user->id }}"
                        data-name="{{ $user->name }}"
                        data-email="{{ $user->email }}"
                        data-role="{{ $user->role }}">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                    </button>
                    <button type="button"
                        class="toggleActiveBtn p-1.5 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50 transition-colors cursor-pointer"
                        title="{{ $user->is_active ? 'Deactivate' : 'Reactivate' }}"
                        data-id="{{ $user->id }}">
                        @if($user->is_active)
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.36 6.64a9 9 0 11-12.73 0M12 3v9"/>
                        </svg>
                        @else
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h1.5m5.5 0h-1.5M3 12a9 9 0 1118 0 9 9 0 01-18 0z"/>
                        </svg>
                        @endif
                    </button>
                </div>
                @endif
            </div>
            @endforeach
        </div>
    </div>
</div>

{{-- Shared Add / Edit modal --}}
<div id="userModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100">
            <h3 id="userModalTitle" class="text-sm font-bold text-slate-800">Add a new user</h3>
            <p id="userModalSubtitle" class="text-xs text-slate-500 mt-0.5">They sign in with "Sign in with Google" using this email</p>
        </div>
        <form id="userForm" method="POST" action="{{ route('user-management.store') }}" class="px-6 py-5 space-y-4">
            @csrf
            <input type="hidden" name="_method" id="userFormMethod" value="">

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Full name</label>
                <input type="text" id="userNameInput" name="name" required
                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Google account email</label>
                <input type="email" id="userEmailInput" name="email" required
                    placeholder="their.name@gmail.com"
                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                <p class="text-[11px] text-slate-400 mt-1">Must be the exact email on their Google account — that's how they'll sign in.</p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Role</label>
                <select name="role" id="userRoleSelect" required
                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    @foreach($assignableRoles as $role)
                    <option value="{{ $role }}">{{ \App\Models\User::ROLE_LABELS[$role] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" id="cancelUserModal" class="px-3 py-2 text-xs font-mono text-slate-600 hover:text-slate-800 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors cursor-pointer">Cancel</button>
                <button type="submit" id="userSubmitBtn" class="px-4 py-2 text-xs font-semibold text-white bg-yellow-700 hover:bg-yellow-800 rounded-lg transition-colors cursor-pointer">Add User</button>
            </div>
        </form>
    </div>
</div>

<form id="toggleActiveUserForm" method="POST" style="display:none">
    @csrf
    @method('PATCH')
</form>

@push('scripts')
<script>
(function () {
    const modal        = document.getElementById('userModal');
    const modalTitle    = document.getElementById('userModalTitle');
    const modalSubtitle = document.getElementById('userModalSubtitle');
    const form          = document.getElementById('userForm');
    const methodInput   = document.getElementById('userFormMethod');
    const nameInput     = document.getElementById('userNameInput');
    const emailInput    = document.getElementById('userEmailInput');
    const roleSelect    = document.getElementById('userRoleSelect');
    const submitBtn     = document.getElementById('userSubmitBtn');
    const storeUrl      = form.action;
    const toggleActiveForm = document.getElementById('toggleActiveUserForm');

    function openModal() { modal.classList.remove('hidden'); }
    function closeModal() { modal.classList.add('hidden'); }

    function resetForm() {
        form.action = storeUrl;
        methodInput.value = '';
        nameInput.value = '';
        emailInput.value = '';
        roleSelect.selectedIndex = 0;
        modalTitle.textContent = 'Add a new user';
        modalSubtitle.textContent = 'They sign in with "Sign in with Google" using this email';
        submitBtn.textContent = 'Add User';
    }

    document.getElementById('addUserBtn').addEventListener('click', () => { resetForm(); openModal(); });
    document.getElementById('cancelUserModal').addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

    document.querySelectorAll('.editUserBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            resetForm();
            const id = btn.dataset.id;
            form.action = storeUrl + '/' + id;
            methodInput.value = 'PUT';
            nameInput.value = btn.dataset.name || '';
            emailInput.value = btn.dataset.email || '';
            roleSelect.value = btn.dataset.role || '';
            modalTitle.textContent = 'Edit user';
            modalSubtitle.textContent = 'Changes apply immediately';
            submitBtn.textContent = 'Save Changes';
            openModal();
        });
    });

    document.querySelectorAll('.toggleActiveBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            toggleActiveForm.action = storeUrl + '/' + btn.dataset.id + '/toggle-active';
            toggleActiveForm.submit();
        });
    });
})();
</script>
@endpush
@endsection
```

- [ ] **Step 2: Run the User Management tests**

Run: `php artisan test --filter=UserManagementControllerTest`
Expected: PASS (12 tests, including the two `index` tests that needed this view)

- [ ] **Step 3: Run the full suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add resources/views/user-management.blade.php
git commit -m "feat: add the User Management page (add/edit/deactivate accounts)"
```

---

## Task 6: Sidebar navigation

**Files:**
- Modify: `resources/views/layouts/app.blade.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarConfigVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_config_section_and_user_management_link(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('User Management');
        $response->assertSee('TSA Management');
    }

    public function test_normal_user_does_not_see_config_section(): void
    {
        $this->actingAs(User::factory()->normal()->create());

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('User Management');
        $response->assertDontSee('TSA Management');
        $response->assertDontSee('Product Management');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SidebarConfigVisibilityTest`
Expected: FAIL — `test_admin_sees_config_section_and_user_management_link` fails because "User Management" isn't in the sidebar yet; `test_normal_user_does_not_see_config_section` fails because the CONFIG section currently renders unconditionally for every role.

- [ ] **Step 3: Wrap the CONFIG section and add the User Management link**

Modify `resources/views/layouts/app.blade.php` — wrap the whole CONFIG block (from the `<div class="my-3 border-t...">` divider through the closing of the Settings `<a>`, i.e. lines 217–252 in the current file) in `@if(auth()->user()?->isAtLeastAdmin())`, and add a new link after Settings:

```blade
        @if(auth()->user()?->isAtLeastAdmin())
        <div class="my-3 border-t border-white/10"></div>
        <p class="px-3 mb-2 text-[10px] font-mono font-semibold tracking-widest text-yellow-400/60 uppercase">Config</p>

        <a href="{{ route('tsa-management') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('tsa-management*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            TSA Management
        </a>

        <a href="{{ route('product-management') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('product-management*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
            </svg>
            Product Management
        </a>

        <a href="{{ route('user-management') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('user-management*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
            User Management
        </a>

        <a href="{{ route('settings') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('settings*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            Settings
            @if(!config('services.pancake.api_key'))
                <span class="ml-auto w-2 h-2 rounded-full bg-red-400 shrink-0"></span>
            @endif
        </a>
        @endif

    </nav>
```

(This replaces the existing unconditional block from the `<div class="my-3...">` divider down through the closing `</a>` of the Settings link, plus the `</nav>` line — the `@endif` goes right before `</nav>`.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SidebarConfigVisibilityTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS — this is the last task, so this is the final full-suite confirmation for the whole feature.

- [ ] **Step 6: Commit**

```bash
git add resources/views/layouts/app.blade.php tests/Feature/SidebarConfigVisibilityTest.php
git commit -m "feat: hide the CONFIG sidebar section from Normal/Guest users, add User Management link"
```

---

## Plan Self-Review Notes

- **Spec coverage:** Data model + backfill (Task 1), permission matrix / route gating (Task 2), sign-up removal + Google auto-create removal + deactivation-at-login (Task 3), User Management CRUD + self-edit block (Task 4), UI (Task 5), sidebar visibility (Task 6). Every section of the design spec maps to a task.
- **Existing test blast radius:** Confirmed via grep that 11 existing test files (22 call sites) use `User::factory()->create()` expecting full access to config pages — defaulting the factory to `role: 'admin'` (Task 1) keeps every one of them green without touching those files.
- **Last-active-Super-Admin guard, revised:** the spec called for an explicit runtime guard against deactivating/demoting the last active Super Admin. Working through Task 4, that guard turned out to be unreachable dead code: `canManage()` already blocks self-edit, and only a Super Admin can manage another Super Admin — so any mutation targeting a Super Admin requires the actor to already be a second, distinct, active Super Admin, meaning the count can never reach zero through this UI. Removed the runtime check and the two tests that had to contort their setup to reach it; kept `User::activeSuperAdminCount()` as a plain helper (used in Task 1's test and to document the invariant in Task 4's `test_deactivating_one_of_two_super_admins_leaves_one_active`) and added a comment on `toggleActive()` explaining why no guard is needed. The safety property the spec actually cared about — never being able to lock out the last Super Admin — is fully preserved; it's just structural instead of a runtime check.
- **Out-of-scope items from the spec** (self-service profile editing, email notifications, manual passwords, audit log) are deliberately not built anywhere in this plan.
