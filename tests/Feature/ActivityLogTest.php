<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_role_user_cannot_access_audit_log(): void
    {
        $this->actingAs(User::factory()->normal()->create());
        $this->get(route('audit-log'))->assertForbidden();
    }

    public function test_creating_a_product_writes_an_activity_log_entry(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->post(route('product-management.store'), [
            'display_name' => 'Widget', 'match_keyword' => '', 'team' => 'SH Naturals',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action'  => 'product.created',
        ]);
    }

    public function test_deactivating_a_user_writes_an_activity_log_entry(): void
    {
        $admin  = User::factory()->create();
        $target = User::factory()->normal()->create();
        $this->actingAs($admin);

        $this->patch(route('user-management.toggle-active', $target));

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action'  => 'user.deactivated',
        ]);
    }

    public function test_connecting_pancake_settings_logs_shop_name_but_never_the_api_key(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        \Illuminate\Support\Facades\Http::fake([
            'pos.pages.fm/api/v1/shops*' => \Illuminate\Support\Facades\Http::response([
                'shops' => [['id' => '999', 'name' => 'Test Shop']],
            ]),
        ]);

        $this->post(route('settings.save'), [
            'api_key' => 'super-secret-key-value', 'shop_id' => '999', 'shop_name' => 'Test Shop',
        ]);

        $log = ActivityLog::where('action', 'settings.pancake_connected')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('super-secret-key-value', $log->description);
        $this->assertStringContainsString('Test Shop', $log->description);
    }

    public function test_bulk_deleting_products_writes_one_log_entry_not_one_per_product(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $p1 = Product::create(['display_name' => 'A', 'team' => 'SH Naturals', 'sort_order' => 1]);
        $p2 = Product::create(['display_name' => 'B', 'team' => 'SH Naturals', 'sort_order' => 2]);

        $this->post(route('product-management.bulk'), ['ids' => [$p1->id, $p2->id], 'action' => 'delete']);

        $this->assertSame(1, ActivityLog::where('action', 'product.bulk_delete')->count());
    }

    public function test_audit_log_page_shows_recorded_entries(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        ActivityLog::create(['user_id' => $admin->id, 'action' => 'product.created', 'description' => 'Added "Widget".']);

        $response = $this->get(route('audit-log'));

        $response->assertOk();
        $response->assertSee('Widget', false);
        $response->assertSee('data-sortable-table', false);
    }

    /**
     * Explicit request: log every meaningful action in the app, not just
     * Product/TSA/User/Settings CRUD — the ten actions below (login, logout,
     * manual sync triggers, reinfer, rest-days, shift schedules, Drive sync,
     * product hide/unhide) previously had no audit trail at all.
     */
    public function test_logging_in_writes_an_activity_log_entry(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password123']);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action'  => 'auth.login',
        ]);
    }

    public function test_logging_out_writes_an_activity_log_entry(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post(route('logout'));

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action'  => 'auth.logout',
        ]);
    }

    public function test_dashboard_sync_button_writes_an_activity_log_entry(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->post(route('dashboard.sync'), ['date_from' => now()->toDateString()]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action'  => 'dashboard.sync',
        ]);
    }

    public function test_retry_a_date_writes_an_activity_log_entry(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->post(route('sync-health.retry'), ['date' => now()->toDateString()]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action'  => 'sync-health.retry',
        ]);
    }

    public function test_fix_stale_order_statuses_writes_an_activity_log_entry(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->post(route('sync-health.reconcile-statuses'), ['days' => 30]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action'  => 'sync-health.reconcile-statuses',
        ]);
    }

    public function test_unmatched_orders_reinfer_writes_an_activity_log_entry(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->post(route('unmatched-orders.reinfer'));

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action'  => 'unmatched-orders.reinfer',
        ]);
    }

    public function test_saving_rest_days_writes_an_activity_log_entry(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $shift = TsaShift::first();
        $date  = now()->addDay()->toDateString();

        $this->post(route('tsa-management.rest-days', $date), ['tsas' => [$shift->tsa_key]]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action'  => 'tsa.rest_days_updated',
        ]);
    }

    public function test_saving_default_shift_schedules_writes_an_activity_log_entry(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->post(route('settings.shifts'), ['shifts' => []]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action'  => 'settings.shifts_saved',
        ]);
    }

    public function test_drive_manual_sync_writes_an_activity_log_entry(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        Setting::set('drive_refresh_token', 'fake-refresh-token');

        $this->post(route('settings.drive.sync-now'));

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action'  => 'settings.drive_sync_now',
        ]);
    }

    public function test_hiding_a_product_writes_an_activity_log_entry(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::create(['display_name' => 'Widget', 'team' => 'SH Naturals', 'sort_order' => 1]);

        $this->patch(route('product-management.toggle-hidden', $product));

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action'  => 'product.hidden',
        ]);
    }
}
