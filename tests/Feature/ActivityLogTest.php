<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
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
}
